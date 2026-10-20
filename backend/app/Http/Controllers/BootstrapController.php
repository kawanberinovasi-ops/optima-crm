<?php

namespace App\Http\Controllers;

use App\Models\AccessGrant;
use App\Models\AccessOverride;
use App\Models\ClientPartner;
use App\Models\Department;
use App\Models\OptionList;
use App\Models\User;
use App\Services\Access;
use App\Support\FrontShape;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Data awal frontend: info halaman login (publik) dan isi Store setelah login. */
class BootstrapController extends Controller
{
    private const OPTION_KEYS = ['segments' => 'segments', 'sources' => 'sources', 'interaction_types' => 'intTypes', 'lost_reasons' => 'lostReasons'];

    /** Publik: apa saja cara login yang tersedia. Daftar akun uji hanya ada di APP_ENV=local. */
    public function session(Request $request): JsonResponse
    {
        $dev = (bool) config('optima.dev_login');

        return response()->json([
            'authenticated' => $request->user() !== null,
            'sso' => ['google' => (bool) config('services.google.client_id')],
            'devLogin' => $dev,
            'accounts' => $dev
                ? User::with('department')->where('active', true)->orderBy('id')->get()->map(fn (User $u) => FrontShape::user($u))
                : [],
        ]);
    }

    public function bootstrap(Request $request, Access $access): JsonResponse
    {
        $me = $request->user()->load('department');

        $access_ = [];
        foreach (AccessGrant::with('department')->get() as $g) {
            $access_[$g->department->name][$g->entity] = $g->level;
        }

        $overrides = AccessOverride::query()
            ->when(! $access->canViewAdmin($me), fn ($q) => $q->where('user_id', $me->id))
            ->get()
            ->map(fn (AccessOverride $o) => ['id' => (string) $o->id, 'userId' => (string) $o->user_id, 'key' => $o->entity, 'value' => $o->level, 'reason' => (string) $o->reason])
            ->values();

        $options = array_fill_keys(array_values(self::OPTION_KEYS), []);
        foreach (OptionList::where('active', true)->orderBy('position')->get() as $o) {
            $options[self::OPTION_KEYS[$o->list]][] = $o->value;
        }

        $records = $access->scopeClientPartners(ClientPartner::query(), $me)
            ->with(['contacts', 'department'])
            ->whereNull('merged_into_id')
            ->orderBy('name')
            ->get()
            ->map(fn (ClientPartner $r) => FrontShape::record($r, $me, $access))
            ->values();

        return response()->json([
            'me' => FrontShape::user($me),
            'users' => User::with('department')->orderBy('id')->get()->map(fn (User $u) => FrontShape::user($u))->values(),
            'departments' => Department::orderBy('id')->get()->map(fn (Department $d) => ['id' => (string) $d->id, 'name' => $d->name])->values(),
            'access' => $access_,
            'overrides' => $overrides,
            'options' => $options,
            'records' => $records,
            'session' => [
                'loginAt' => (int) $request->session()->get('login_at'),
                'idleMinutes' => (int) config('session.lifetime'),
                'maxHours' => (int) config('optima.session_absolute_hours'),
            ],
            'devLogin' => (bool) config('optima.dev_login'),
        ]);
    }
}
