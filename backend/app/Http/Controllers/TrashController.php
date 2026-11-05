<?php

namespace App\Http\Controllers;

use App\Models\TrashEntry;
use App\Services\Trash;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Data terhapus: hapus ke tempat pemulihan dan pulihkan lagi, sesuai hak akses (lihat App\Services\Trash). */
class TrashController extends Controller
{
    public function __construct(private Trash $trash) {}

    public function destroy(Request $request, string $kind, int $id): JsonResponse
    {
        abort_unless(in_array($kind, Trash::KINDS, true), 404);
        $entry = $this->trash->delete($request->user(), $kind, $id);

        return response()->json(['data' => ['id' => (string) $entry->id, 'label' => $entry->label]], 201);
    }

    public function restore(Request $request, TrashEntry $entry): JsonResponse
    {
        $this->trash->restore($request->user(), $entry);

        return response()->json(['ok' => true]);
    }
}
