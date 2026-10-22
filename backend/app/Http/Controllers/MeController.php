<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** Data milik pengguna yang sedang login: pemberitahuan dan tampilan tersimpan (FR-REL-05). */
class MeController extends Controller
{
    public static function notifications(int $userId): array
    {
        return DB::table('user_notifications')->where('user_id', $userId)->orderByDesc('id')->limit(50)->get()
            ->map(fn ($n) => ['id' => (string) $n->id, 'userId' => (string) $n->user_id, 'at' => Carbon::parse($n->created_at)->toIso8601String(),
                'text' => $n->text, 'link' => (string) $n->link, 'read' => $n->read_at !== null])->all();
    }

    public static function views(int $userId): array
    {
        return DB::table('saved_views')->where('user_id', $userId)->orderBy('id')->get()
            ->map(fn ($v) => ['id' => (string) $v->id, 'userId' => (string) $v->user_id, 'name' => $v->name, 'filter' => json_decode($v->filters, true)])->all();
    }

    /** Tandai satu pemberitahuan (id) atau semuanya sudah dibaca. */
    public function readNotifications(Request $request): JsonResponse
    {
        $data = $request->validate(['id' => ['nullable', 'integer']]);
        DB::table('user_notifications')->where('user_id', $request->user()->id)->whereNull('read_at')
            ->when($data['id'] ?? null, fn ($q, $id) => $q->where('id', $id))
            ->update(['read_at' => now()]);

        return response()->json(['data' => self::notifications($request->user()->id)]);
    }

    public function storeView(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'filter' => ['required', 'array'],
            'filter.cat' => ['nullable', 'string'], 'filter.dept' => ['nullable', 'string'], 'filter.service' => ['nullable', 'string', 'max:100'], 'filter.q' => ['nullable', 'string', 'max:100'],
            'filter.sort' => ['nullable', 'string'], 'filter.arch' => ['nullable', 'boolean'],
        ], ['name.required' => 'Kasih nama tampilannya.']);

        DB::table('saved_views')->insert([
            'user_id' => $request->user()->id, 'page' => 'cp', 'name' => trim($data['name']),
            'filters' => json_encode(array_intersect_key($data['filter'], array_flip(['cat', 'dept', 'service', 'q', 'sort', 'arch']))),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return response()->json(['data' => self::views($request->user()->id)], 201);
    }

    public function destroyView(Request $request, int $view): JsonResponse
    {
        DB::table('saved_views')->where('id', $view)->where('user_id', $request->user()->id)->delete();

        return response()->json(['data' => self::views($request->user()->id)]);
    }
}
