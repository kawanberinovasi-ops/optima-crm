<?php

namespace App\Http\Controllers;

use App\Enums\AccessLevel;
use App\Models\AccessGrant;
use App\Models\AccessOverride;
use App\Models\AuditLog;
use App\Models\ClientPartner;
use App\Models\Deal;
use App\Models\Department;
use App\Models\Interaction;
use App\Models\Pipeline;
use App\Models\User;
use App\Services\Access;
use App\Services\ChangeHistory;
use App\Services\Trash;
use App\Support\FrontShape;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Data awal frontend: info halaman login (publik) dan isi Store setelah login. */
class BootstrapController extends Controller
{
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

    public function bootstrap(Request $request, Access $access, ChangeHistory $history): JsonResponse
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

        $options = AdminController::optionsShape();

        $records = $access->scopeClientPartners(ClientPartner::query(), $me)
            ->with(['contacts', 'department', 'service', 'handovers.fromDepartment', 'handovers.toDepartment'])
            ->whereNull('merged_into_id')
            ->orderBy('name')
            ->get()
            ->map(fn (ClientPartner $r) => FrontShape::record($r, $me, $access))
            ->values();

        $canDeal = $access->can($me, 'deal', AccessLevel::R);
        $deals = $canDeal
            ? Deal::with('histories', 'clientPartner')->whereIn('client_partner_id', $access->scopeClientPartners(ClientPartner::query(), $me)->select('id'))
                ->orderBy('id')->get()->map(fn (Deal $d) => FrontShape::deal($d, $me, $access))->values()
            : [];
        $interactions = $access->can($me, 'interaksi', AccessLevel::R)
            ? Interaction::whereIn('client_partner_id', $access->scopeClientPartners(ClientPartner::query(), $me)->select('id'))
                ->orderBy('occurred_on')->orderBy('id')->get()->map(fn (Interaction $i) => FrontShape::interaction($i))->values()
            : [];

        return response()->json([
            'me' => FrontShape::user($me),
            // Pipeline divisinya sendiri; Executive melihat semua.
            'lines' => $access->scopePipelines(Pipeline::with('stages', 'departments'), $me)->whereNull('archived_at')->orderBy('position')->get()->map(fn (Pipeline $p) => FrontShape::line($p))->values(),
            'deals' => $deals,
            'interactions' => $interactions,
            'trash' => app(Trash::class)->listFor($me),
            // Pengaturan pipeline per divisi dan per pengguna, untuk halaman Akses & Audit.
            'pipelineAccess' => $access->canViewAdmin($me) ? AdminController::pipelineAccessShape() : ['departments' => [], 'users' => []],
            'users' => User::with('department')->orderBy('id')->get()->map(fn (User $u) => FrontShape::user($u))->values(),
            'departments' => Department::orderBy('id')->get()->map(fn (Department $d) => ['id' => (string) $d->id, 'name' => $d->name, 'seesAll' => $d->sees_all])->values(),
            'services' => AdminController::servicesShape(),
            'access' => $access_,
            'overrides' => $overrides,
            'options' => $options,
            'records' => $records,
            'notifs' => MeController::notifications($me->id),
            'views' => MeController::views($me->id),
            'imports' => ImportController::historyFor($me, $access),
            // Audit log dan riwayat data hanya untuk yang boleh membuka Akses & Audit.
            'audit' => $access->canViewAdmin($me) ? AdminController::auditShape(AuditLog::orderByDesc('id')->limit(500)->get()) : [],
            'changes' => $access->canViewAdmin($me) ? $history->latest($me) : [],
            'session' => [
                'loginAt' => (int) $request->session()->get('login_at'),
                'idleMinutes' => (int) config('session.lifetime'),
                'maxHours' => (int) config('optima.session_absolute_hours'),
            ],
            'devLogin' => (bool) config('optima.dev_login'),
        ]);
    }
}
