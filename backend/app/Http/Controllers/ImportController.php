<?php

namespace App\Http\Controllers;

use App\Enums\AccessLevel;
use App\Models\Import;
use App\Services\Access;
use App\Services\Importer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Import data di Client & Partner Database (FR-IMP-01..04). File dibaca di browser, setiap baris diperiksa ulang di server. */
class ImportController extends Controller
{
    public function __construct(private Access $access, private Importer $importer) {}

    public function check(Request $request): JsonResponse
    {
        $me = $request->user();
        abort_unless($this->access->canCreateAnyRecord($me) || $this->access->can($me, 'interaksi', AccessLevel::C) || $this->access->can($me, 'deal', AccessLevel::C),
            403, 'Akun ini cuma bisa melihat dan export.');
        $data = $request->validate([
            'filename' => ['required', 'string', 'max:255'],
            'sheets' => ['required', 'array'],
            'sheets.records' => ['nullable', 'array', 'max:'.Importer::MAX_ROWS],
            'sheets.interactions' => ['nullable', 'array', 'max:'.Importer::MAX_ROWS],
            'sheets.deals' => ['nullable', 'array', 'max:'.Importer::MAX_ROWS],
        ], ['sheets.*.max' => 'Maksimal '.Importer::MAX_ROWS.' baris per sheet. Pecah filenya jadi beberapa bagian.']);

        $import = $this->importer->check($me, $data['filename'], $data['sheets']);

        return response()->json(['data' => Importer::preview($import)], 201);
    }

    public function commit(Request $request, Import $import): JsonResponse
    {
        abort_unless($import->user_id === $request->user()->id, 403, 'Import ini milik orang lain.');

        return response()->json(['data' => Importer::history($this->importer->commit($request->user(), $import))]);
    }

    public function undo(Request $request, Import $import): JsonResponse
    {
        $me = $request->user();
        abort_unless($import->user_id === $me->id || $this->access->canAdminister($me), 403, 'Cuma yang mengimport atau Super Admin yang bisa membatalkan.');

        return response()->json(['data' => Importer::history($this->importer->undo($me, $import))]);
    }

    /** Riwayat import yang sudah disimpan: milik sendiri, atau semua untuk Super Admin. */
    public static function historyFor($user, Access $access): array
    {
        return Import::whereIn('status', ['committed', 'undone'])
            ->when(! $access->canAdminister($user), fn ($q) => $q->where('user_id', $user->id))
            ->orderByDesc('id')->limit(20)->get()->map(fn (Import $i) => Importer::history($i))->all();
    }
}
