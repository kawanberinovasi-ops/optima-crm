<?php

namespace App\Models;

use App\Enums\Role;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;

#[Fillable(['name', 'email', 'employee_no', 'job_title', 'department_id', 'role', 'active', 'sso_subject', 'last_login_at', 'deactivated_at'])]
#[Hidden(['password', 'remember_token', 'sso_subject'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => Role::class,
            'active' => 'boolean',
            'last_login_at' => 'datetime',
            'deactivated_at' => 'datetime',
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /** Pengguna yang dinonaktifkan langsung keluar dari sistem (SRS bagian 3): semua sesinya dihapus. */
    protected static function booted(): void
    {
        static::updated(function (User $user) {
            if ($user->wasChanged('active') && ! $user->active) {
                DB::table('sessions')->where('user_id', $user->id)->delete();
            }
        });
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function accessOverrides(): HasMany
    {
        return $this->hasMany(AccessOverride::class);
    }

    public function isSuperAdmin(): bool
    {
        return $this->role === Role::SuperAdmin;
    }
}
