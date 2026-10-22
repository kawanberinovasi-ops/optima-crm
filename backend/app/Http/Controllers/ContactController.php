<?php

namespace App\Http\Controllers;

use App\Models\ClientPartner;
use App\Models\Contact;
use App\Services\Access;
use App\Services\Audit;
use App\Support\FrontShape;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Kontak milik data Client & Partner (FR-REL-02). Melepas kontak memakai soft delete, jadi tidak hilang permanen.
 * Semua perubahan dicatat di audit log di bawah data induknya. Responsnya selalu data induk yang terbaru.
 */
class ContactController extends Controller
{
    public function __construct(private Access $access, private Audit $audit) {}

    public function store(Request $request, ClientPartner $clientPartner): JsonResponse
    {
        $this->authorizeEdit($request, $clientPartner);
        $data = $request->validate($this->rules());

        $contact = $clientPartner->contacts()->create($this->fields($data) + ['is_primary' => ! $clientPartner->contacts()->exists()]);
        $this->audit->log('Ubah', ClientPartnerController::ENTITY, $clientPartner->id, $clientPartner->name.': kontak '.$contact->name.' ditambahkan',
            ['contact' => null], ['contact' => $contact->name]);

        return $this->respond($request, $clientPartner, 201);
    }

    public function update(Request $request, Contact $contact): JsonResponse
    {
        $record = $contact->clientPartner;
        $this->authorizeEdit($request, $record);
        $data = $request->validate($this->rules());

        $before = [];
        $after = [];
        foreach ($this->fields($data) as $col => $value) {
            if ((string) $contact->{$col} !== (string) $value) {
                $before['contact.'.$col] = (string) $contact->{$col};
                $after['contact.'.$col] = (string) $value;
            }
        }
        $contact->update($this->fields($data));
        if ($after) {
            $this->audit->log('Ubah', ClientPartnerController::ENTITY, $record->id, $record->name.': kontak '.$contact->name.' diperbarui', $before, $after);
        }

        return $this->respond($request, $record);
    }

    /** "Lepas kontak": tidak muncul lagi di data, tapi tetap tersimpan. */
    public function destroy(Request $request, Contact $contact): JsonResponse
    {
        $record = $contact->clientPartner;
        $this->authorizeEdit($request, $record);

        DB::transaction(function () use ($contact, $record) {
            $wasPrimary = $contact->is_primary;
            $contact->update(['is_primary' => false]);
            $contact->delete();
            if ($wasPrimary && ($next = $record->contacts()->orderBy('id')->first())) {
                $next->update(['is_primary' => true]);
            }
            $this->audit->log('Ubah', ClientPartnerController::ENTITY, $record->id, $record->name.': kontak '.$contact->name.' dilepas',
                ['contact' => $contact->name], ['contact' => '']);
        });

        return $this->respond($request, $record);
    }

    public function primary(Request $request, Contact $contact): JsonResponse
    {
        $record = $contact->clientPartner;
        $this->authorizeEdit($request, $record);

        $old = $record->contacts()->where('is_primary', true)->first();
        if ($old?->id !== $contact->id) {
            DB::transaction(function () use ($record, $contact) {
                $record->contacts()->update(['is_primary' => false]);
                $contact->update(['is_primary' => true]);
            });
            $this->audit->log('Ubah', ClientPartnerController::ENTITY, $record->id, $record->name.': kontak utama jadi '.$contact->name,
                ['contact.primary' => $old?->name], ['contact.primary' => $contact->name]);
        }

        return $this->respond($request, $record);
    }

    private function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:200'],
            'title' => ['nullable', 'string', 'max:200'],
            'email' => ['nullable', 'email', 'max:200'],
            'phone' => ['nullable', 'string', 'max:50'],
            'social' => ['nullable', 'string', 'max:200'],
        ];
    }

    private function fields(array $data): array
    {
        return [
            'name' => trim($data['name']),
            'title' => $data['title'] ?? null,
            'email' => $data['email'] ?? null,
            'phone' => $data['phone'] ?? null,
            'social' => $data['social'] ?? null,
        ];
    }

    private function authorizeEdit(Request $request, ClientPartner $record): void
    {
        $me = $request->user();
        abort_unless($this->access->canViewRecord($me, $record), 403, 'Kamu ga punya akses ke data ini.');
        abort_unless($this->access->canEditRecord($me, $record), 403, 'Kamu ga bisa mengubah data ini.');
    }

    private function respond(Request $request, ClientPartner $record, int $status = 200): JsonResponse
    {
        return response()->json(['data' => FrontShape::record($record->fresh(['contacts', 'department', 'service', 'handovers.fromDepartment', 'handovers.toDepartment']), $request->user(), $this->access)], $status);
    }
}
