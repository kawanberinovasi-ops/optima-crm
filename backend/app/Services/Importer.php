<?php

namespace App\Services;

use App\Enums\AccessLevel;
use App\Enums\Category;
use App\Enums\DealStatus;
use App\Models\ClientPartner;
use App\Models\Deal;
use App\Models\Department;
use App\Models\Import;
use App\Models\Interaction;
use App\Models\OptionList;
use App\Models\Pipeline;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Import dari template Excel (SRS 4.4, 8.3; FR-IMP-01..04).
 * 1. check: setiap baris diperiksa dan disimpan sebagai hasil pemeriksaan, tanpa mengubah data apa pun.
 * 2. commit: baris yang lolos disimpan. Import ulang tidak membuat data ganda (kode sumber jadi kunci).
 * 3. undo: data yang dibuat import ditarik dan data yang diperbarui dikembalikan, selama belum dipakai.
 * Aturan pemeriksaan sama dengan parseWorkbook di prototipe (assets/actions.js).
 */
class Importer
{
    public const MAX_ROWS = 2000;

    public const SHEETS = ['records' => '7', 'interactions' => '8', 'deals' => '9'];

    public function __construct(private Access $access, private Audit $audit) {}

    /**
     * @param  array<string, list<array<string, string>>>  $sheets  baris per sheet, kunci kolom huruf kecil seperti di template
     */
    public function check(User $by, string $filename, array $sheets): Import
    {
        $records = array_slice($sheets['records'] ?? [], 0, self::MAX_ROWS);
        $ints = array_slice($sheets['interactions'] ?? [], 0, self::MAX_ROWS);
        $deals = array_slice($sheets['deals'] ?? [], 0, self::MAX_ROWS);
        if (! $records && ! $ints && ! $deals) {
            throw new HttpException(422, 'Sheet "7 Data Client & Partner" ga ketemu. Pakai template dari tombol Template kosong.');
        }

        $users = User::where('active', true)->get()->keyBy(fn ($u) => strtolower($u->email));
        $depts = Department::all()->keyBy(fn ($d) => strtolower($d->name));
        $sources = OptionList::where(['list' => 'sources', 'active' => true])->pluck('value');
        $types = OptionList::where(['list' => 'interaction_types', 'active' => true])->pluck('value');
        $lostReasons = OptionList::where(['list' => 'lost_reasons', 'active' => true])->pluck('value');
        $pipelines = Pipeline::with('stages')->get()->keyBy(fn ($p) => strtolower($p->name));

        $rows = [];
        $skippedExample = false;
        $fileCodes = [];

        foreach ($records as $o) {
            if (($o['kode sumber'] ?? '') === 'CP-0001' && ($o['nama'] ?? '') === 'PT Contoh Sejahtera') {
                $skippedExample = true;

                continue;
            }
            $err = null;
            $code = trim($o['kode sumber'] ?? '');
            $cat = collect(Category::cases())->first(fn ($c) => strtolower($c->value) === strtolower(trim($o['kategori'] ?? '')));
            $pic = $users[strtolower(trim($o['email pic'] ?? ''))] ?? null;
            $dept = $depts[strtolower(trim($o['department pemilik'] ?? ''))] ?? null;
            $existing = $code !== '' ? ClientPartner::where('code', $code)->first() : null;
            $err = match (true) {
                $code === '' || trim($o['nama'] ?? '') === '' => 'Kode sumber dan Nama wajib diisi.',
                isset($fileCodes[$code]) => 'Kode sumber '.$code.' dobel di file.',
                ! $cat => 'Kategori "'.($o['kategori'] ?? '').'" ga dikenal.',
                ! $pic => 'Email PIC "'.($o['email pic'] ?? '').'" belum terdaftar di CRM.',
                ! $dept => 'Department "'.($o['department pemilik'] ?? '').'" belum ada.',
                ! $this->access->canCreateRecord($by, $cat) => 'Akun ini ga boleh menambah kategori '.$cat->value.'.',
                $existing && ! $this->access->canEditRecord($by, $existing) => 'Data '.$existing->name.' udah ada dan akun ini ga boleh mengubahnya.',
                default => null,
            };
            if (! $err) {
                $fileCodes[$code] = true;
            }
            $source = trim($o['sumber'] ?? '');
            $rows[] = $this->row('records', $o, $err, $err ? null : [
                'code' => $code, 'name' => trim($o['nama']), 'category' => $cat->value, 'segment' => trim($o['segmen'] ?? '') ?: null,
                'pic_id' => $pic->id, 'department_id' => $dept->id, 'source' => $source === '' ? 'Lainnya' : ($sources->first(fn ($s) => strtolower($s) === strtolower($source)) ?? $source),
                'city' => trim($o['kota'] ?? '') ?: null, 'note' => trim($o['catatan'] ?? '') ?: null,
                'rate' => self::digits($o['rate card (kol/koc)'] ?? ''),
                'contact' => ['name' => trim($o['nama kontak utama'] ?? ''), 'title' => trim($o['jabatan kontak'] ?? '') ?: null,
                    'email' => trim($o['email kontak'] ?? '') ?: null, 'phone' => trim($o['telepon kontak'] ?? '') ?: null],
                'existing_id' => $existing?->id,
            ], $code);
        }

        $recordExists = fn (string $code) => isset($fileCodes[$code]) || ClientPartner::where('code', $code)->exists();
        $seen = [];
        foreach ($ints as $o) {
            if (($o['kode sumber'] ?? '') === 'INT-0001' && ($o['kode client & partner'] ?? '') === 'CP-0001') {
                $skippedExample = true;

                continue;
            }
            $code = trim($o['kode sumber'] ?? '');
            $cp = trim($o['kode client & partner'] ?? '');
            $by_ = $users[strtolower(trim($o['email pencatat'] ?? ''))] ?? null;
            $err = match (true) {
                $code === '' || $cp === '' || trim($o['hasil pembicaraan'] ?? '') === '' => 'Kode, kode Client & Partner, dan hasil wajib diisi.',
                ! $recordExists($cp) => 'Kode Client & Partner '.$cp.' ga ketemu.',
                ! $by_ => 'Email pencatat belum terdaftar.',
                ! $this->access->can($by, 'interaksi', AccessLevel::C) => 'Akun ini ga boleh menambah interaksi.',
                isset($seen['i'.$code]) => 'Kode sumber '.$code.' dobel di file.',
                Interaction::where('code', $code)->exists() => 'Interaksi '.$code.' udah pernah diimport.',
                default => null,
            };
            $seen['i'.$code] = true;
            $type = trim($o['jenis'] ?? '');
            $rows[] = $this->row('interactions', $o, $err, $err ? null : [
                'code' => $code, 'record_code' => $cp, 'user_id' => $by_->id,
                'occurred_on' => self::date($o['tanggal'] ?? '') ?? today()->toDateString(),
                'type' => $types->first(fn ($t) => strtolower($t) === strtolower($type)) ?? 'Lainnya',
                'result' => trim($o['hasil pembicaraan']), 'next_action' => trim($o['langkah berikutnya'] ?? '') ?: null,
                'followup_on' => self::date($o['tanggal follow-up'] ?? ''),
            ], $code);
        }

        foreach ($deals as $o) {
            if (($o['kode sumber'] ?? '') === 'DL-0001' && ($o['kode client & partner'] ?? '') === 'CP-0001') {
                $skippedExample = true;

                continue;
            }
            $code = trim($o['kode sumber'] ?? '');
            $cp = trim($o['kode client & partner'] ?? '');
            $owner = $users[strtolower(trim($o['email pic'] ?? ''))] ?? null;
            $pipeline = $pipelines[strtolower(trim($o['lini bisnis'] ?? ''))] ?? null;
            $status = collect(DealStatus::cases())->first(fn ($s) => strtolower($s->value) === strtolower(trim($o['status'] ?? '')));
            $stage = $pipeline?->stages->first(fn ($s) => strtolower($s->name) === strtolower(trim($o['stage'] ?? '')));
            if ($pipeline && $status === DealStatus::Won) {
                $stage = $pipeline->stages->firstWhere('is_closing', true);
            } elseif ($pipeline && $status && (! $stage || $stage->is_closing) && $status !== DealStatus::Open && $status !== DealStatus::OnHold) {
                $stage = $pipeline->stages->where('is_closing', false)->sortBy('position')->last();
            }
            $value = self::digits($o['nilai deal (rp)'] ?? '');
            $closedAt = self::date($o['tanggal closing'] ?? '');
            $reason = trim($o['alasan lost / on hold'] ?? '');
            $err = match (true) {
                $code === '' || $cp === '' => 'Kode wajib diisi.',
                ! $recordExists($cp) => 'Kode Client & Partner '.$cp.' ga ketemu.',
                ! $pipeline => 'Lini bisnis "'.($o['lini bisnis'] ?? '').'" belum ada.',
                ! $status => 'Status harus Open, Won, Lost, atau On Hold.',
                ! $owner => 'Email PIC belum terdaftar.',
                ! $this->access->can($by, 'deal', AccessLevel::C) => 'Akun ini ga boleh menambah deal.',
                ! $stage || ($stage->is_closing && $status !== DealStatus::Won) => 'Stage "'.($o['stage'] ?? '').'" ga ada di '.$pipeline->name.'.',
                $status === DealStatus::Won && (! $value || ! $closedAt) => 'Deal Won wajib ada nilai deal dan tanggal closing.',
                in_array($status, [DealStatus::Lost, DealStatus::OnHold], true) && $reason === '' => 'Deal '.$status->value.' wajib ada alasan.',
                isset($seen['d'.$code]) => 'Kode sumber '.$code.' dobel di file.',
                Deal::where('code', $code)->exists() => 'Deal '.$code.' udah pernah diimport.',
                default => null,
            };
            $seen['d'.$code] = true;
            $rows[] = $this->row('deals', $o, $err, $err ? null : [
                'code' => $code, 'record_code' => $cp, 'pipeline_id' => $pipeline->id, 'stage_id' => $stage->id, 'status' => $status->value,
                'title' => trim($o['layanan'] ?? '') ?: 'Deal '.$code, 'service' => trim($o['layanan'] ?? '') ?: null, 'owner_id' => $owner->id,
                'offer_value' => self::digits($o['nilai penawaran (rp)'] ?? ''), 'deal_value' => $status === DealStatus::Won ? $value : null,
                'target_closing' => self::date($o['target closing'] ?? ''),
                'closed_at' => in_array($status, [DealStatus::Won, DealStatus::Lost], true) ? ($closedAt ?? today()->toDateString()) : null,
                'lost_reason' => $status === DealStatus::Lost ? ($lostReasons->first(fn ($r) => strtolower($r) === strtolower($reason)) ?? 'Lainnya') : null,
                'lost_note' => $status === DealStatus::Lost ? $reason : null,
                'hold_reason' => $status === DealStatus::OnHold ? $reason : null,
                'review_date' => $status === DealStatus::OnHold ? today()->addDays(14)->toDateString() : null,
                'created_on' => self::date($o['tanggal dibuat'] ?? ''),
            ], $code);
        }

        return DB::transaction(function () use ($by, $filename, $rows, $skippedExample) {
            $errors = count(array_filter($rows, fn ($r) => $r['errors']));
            $import = Import::create([
                'user_id' => $by->id, 'filename' => $filename, 'status' => 'checked', 'total_rows' => count($rows),
                'ok_rows' => count($rows) - $errors, 'error_rows' => $errors, 'skipped_example' => $skippedExample,
            ]);
            foreach (array_chunk($rows, 500) as $chunk) {
                $import->rows()->createMany($chunk);
            }

            return $import;
        });
    }

    /** Simpan baris yang lolos pemeriksaan. */
    public function commit(User $by, Import $import): Import
    {
        abort_unless($import->status === 'checked', 409, 'Import ini sudah diproses.');

        DB::transaction(function () use ($by, $import) {
            $recordIds = [];
            $rows = $import->rows()->whereNull('errors')->get()->groupBy('sheet');

            foreach ($rows->get('records', collect()) as $row) {
                $d = $row->data;
                $fields = collect($d)->only(['name', 'category', 'segment', 'pic_id', 'department_id', 'source', 'city', 'note'])->all();
                if ($d['category'] === Category::KolKoc->value && $d['rate'] && $this->access->canManageRateCard($by)) {
                    $fields['rate_card'] = ['rate' => (int) $d['rate']];
                }
                $existing = ClientPartner::where('code', $d['code'])->first();
                if ($existing) {
                    $before = $existing->only(array_keys($fields));
                    $existing->update($fields);
                    $this->upsertPrimaryContact($existing, $d['contact']);
                    $row->update(['entity_type' => 'Client & Partner', 'entity_id' => $existing->id, 'action' => 'updated', 'before' => $before]);
                    $this->audit->changed('Client & Partner', $existing, $before, 'Diperbarui dari import '.$import->filename);
                    $recordIds[$d['code']] = $existing->id;
                } else {
                    $record = ClientPartner::create($fields + ['code' => $d['code'], 'created_by' => $by->id]);
                    $this->upsertPrimaryContact($record, $d['contact']);
                    $row->update(['entity_type' => 'Client & Partner', 'entity_id' => $record->id, 'action' => 'created']);
                    $this->audit->log('Tambah', 'Client & Partner', $record->id, $record->name.' ('.$record->code.') dari import '.$import->filename, null, $record->getAttributes(), $by);
                    $recordIds[$d['code']] = $record->id;
                }
            }
            $recordId = fn (string $code) => $recordIds[$code] ?? ClientPartner::where('code', $code)->value('id');

            foreach ($rows->get('interactions', collect()) as $row) {
                $d = $row->data;
                $i = Interaction::create(collect($d)->except('record_code')->all() + ['client_partner_id' => $recordId($d['record_code'])]);
                $row->update(['entity_type' => 'Interaksi', 'entity_id' => $i->id, 'action' => 'created']);
            }

            foreach ($rows->get('deals', collect()) as $row) {
                $d = $row->data;
                $deal = new Deal;
                $deal->forceFill(collect($d)->except(['record_code', 'created_on'])->all() + [
                    'client_partner_id' => $recordId($d['record_code']), 'created_by' => $by->id,
                ]);
                if ($d['created_on']) {
                    $deal->created_at = Carbon::parse($d['created_on'])->setTime(9, 0);
                }
                $deal->save();
                $deal->histories()->create([
                    'user_id' => $by->id, 'to_stage_id' => $deal->stage_id, 'to_stage_name' => $deal->stage->name,
                    'status' => $deal->status, 'note' => 'Dari import '.$import->filename, 'created_at' => now(),
                ]);
                $row->update(['entity_type' => 'Deal', 'entity_id' => $deal->id, 'action' => 'created']);
            }

            $import->update(['status' => 'committed', 'committed_at' => now()]);
            $count = fn ($k) => $rows->get($k, collect())->count();
            $this->audit->log('Import', 'Client & Partner', null, $import->filename.': '.$count('records').' data, '.$count('interactions').' interaksi, '.$count('deals').' deal'
                .($import->error_rows ? ', '.$import->error_rows.' baris dilewati' : ''), null, ['import_id' => $import->id], $by);
        });

        return $import->fresh();
    }

    /** Batalkan import: tarik data yang dibuat, kembalikan data yang diperbarui. Ditolak kalau datanya sudah dipakai. */
    public function undo(User $by, Import $import): Import
    {
        abort_unless($import->status === 'committed', 409, 'Cuma import yang sudah disimpan yang bisa dibatalkan.');
        $rows = $import->rows()->whereNotNull('action')->get();
        $created = fn (string $type) => $rows->where('entity_type', $type)->where('action', 'created')->pluck('entity_id')->all();
        [$recs, $ints, $deals] = [$created('Client & Partner'), $created('Interaksi'), $created('Deal')];

        $usedInts = Interaction::whereIn('client_partner_id', $recs)->whereNotIn('id', $ints)->count();
        $usedDeals = Deal::whereIn('client_partner_id', $recs)->whereNotIn('id', $deals)->count();
        $movedDeals = Deal::whereIn('id', $deals)->whereHas('histories', fn ($h) => $h->where('note', 'not like', 'Dari import%'))->count();
        $usedByInts = Interaction::whereIn('deal_id', $deals)->whereNotIn('id', $ints)->count();
        if ($usedInts + $usedDeals + $movedDeals + $usedByInts > 0) {
            throw new HttpException(422, 'Data dari import ini sudah dipakai ('.($usedInts + $usedByInts).' interaksi dan '.($usedDeals + $movedDeals).' deal baru atau berubah). Import ga bisa dibatalkan lagi.');
        }

        DB::transaction(function () use ($by, $import, $rows, $recs, $ints, $deals) {
            Interaction::whereIn('id', $ints)->delete();
            Deal::whereIn('id', $deals)->delete();
            ClientPartner::whereIn('id', $recs)->delete();
            foreach ($rows->where('action', 'updated') as $row) {
                if ($record = ClientPartner::find($row->entity_id)) {
                    $record->update($row->before);
                }
            }
            $import->update(['status' => 'undone', 'undone_at' => now(), 'undone_by' => $by->id]);
            $this->audit->log('Batal', 'Import', $import->id, $import->filename.' dibatalkan: '.count($recs).' data, '.count($ints).' interaksi, '.count($deals).' deal ditarik, '
                .$rows->where('action', 'updated')->count().' data dikembalikan', null, null, $by);
        });

        return $import->fresh();
    }

    /** Bentuk yang dipakai frontend untuk pratinjau (Modals.import). */
    public static function preview(Import $import): array
    {
        $rows = $import->rows()->get();
        $ok = fn ($sheet) => $rows->where('sheet', $sheet)->whereNull('errors');

        return [
            'id' => (string) $import->id,
            'records' => $ok('records')->map(fn ($r) => ['isNew' => empty($r->data['existing_id']), 'code' => $r->data['code']])->values(),
            'ints' => $ok('interactions')->map(fn ($r) => ['code' => $r->data['code']])->values(),
            'deals' => $ok('deals')->map(fn ($r) => ['code' => $r->data['code']])->values(),
            'errors' => $rows->whereNotNull('errors')->map(fn ($r) => 'Sheet '.self::SHEETS[$r->sheet].' baris '.$r->row_no.': '.$r->errors[0])->values(),
            'skippedExample' => $import->skipped_example,
            'totalRows' => $import->total_rows,
        ];
    }

    /** Bentuk riwayat import yang dipakai frontend (Store.db.imports). */
    public static function history(Import $import): array
    {
        $rows = $import->rows()->whereNotNull('action')->get();
        $ids = fn ($type) => $rows->where('entity_type', $type)->where('action', 'created')->pluck('entity_id')->map(fn ($id) => (string) $id)->values();

        return [
            'id' => (string) $import->id, 'file' => $import->filename, 'at' => $import->committed_at?->toIso8601String(), 'by' => (string) $import->user_id,
            'created' => ['r' => $ids('Client & Partner'), 'i' => $ids('Interaksi'), 'd' => $ids('Deal')],
            'updated' => $rows->where('action', 'updated')->map(fn ($r) => ['id' => (string) $r->entity_id])->values(),
            'undone' => $import->status === 'undone', 'undoneAt' => $import->undone_at?->toIso8601String() ?? '', 'undoneBy' => (string) $import->undone_by,
        ];
    }

    private function upsertPrimaryContact(ClientPartner $record, array $c): void
    {
        if (($c['name'] ?? '') === '' && ! ($c['email'] ?? null) && ! ($c['phone'] ?? null)) {
            return;
        }
        $primary = $record->contacts()->where('is_primary', true)->first();
        $primary ? $primary->update($c) : $record->contacts()->create($c + ['is_primary' => true]);
    }

    private function row(string $sheet, array $o, ?string $error, ?array $data, string $key): array
    {
        return [
            'sheet' => $sheet, 'row_no' => (int) ($o['_row'] ?? 0), 'data' => $data ?? array_diff_key($o, ['_row' => 1]),
            'errors' => $error ? [$error] : null, 'source_key' => $key ?: null,
        ];
    }

    private static function digits(?string $s): ?int
    {
        $d = preg_replace('/\D/', '', (string) $s);

        return $d === '' ? null : (int) $d;
    }

    /** Tanggal dari template: YYYY-MM-DD, atau D/M/YYYY (format Indonesia). */
    private static function date(?string $s): ?string
    {
        $s = trim((string) $s);
        if ($s === '') {
            return null;
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}/', $s)) {
            return substr($s, 0, 10);
        }
        if (preg_match('/^(\d{1,2})[\/.-](\d{1,2})[\/.-](\d{2,4})$/', $s, $m)) {
            $y = strlen($m[3]) === 2 ? '20'.$m[3] : $m[3];

            return checkdate((int) $m[2], (int) $m[1], (int) $y) ? sprintf('%04d-%02d-%02d', $y, $m[2], $m[1]) : null;
        }
        try {
            return Carbon::parse($s)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }
}
