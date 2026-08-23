<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use HasFactory, HasRoles, Notifiable, SoftDeletes;

    protected $guarded = ['id'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'dob' => 'date',
            'available_at' => 'datetime',
            'paused_at' => 'datetime',
            'is_cmmsn_agnt' => 'boolean',
            'selected_contacts' => 'boolean',
            'allow_login' => 'boolean',
        ];
    }

    /* --------------------------------------------------------------------
     | Scopes
     -------------------------------------------------------------------- */

    /**
     * Only real system users — excludes commission agents and CRM contacts.
     */
    public function scopeUser(Builder $query): Builder
    {
        return $query->where('users.user_type', 'user');
    }

    /**
     * Users who may access the given location.
     */
    public function scopeOnlyPermittedLocations(Builder $query, ?int $locationId = null): Builder
    {
        if (is_null($locationId)) {
            return $query;
        }

        return $query->where(function ($q) use ($locationId) {
            $q->whereHas('permissions', fn ($p) => $p->where('name', 'location.'.$locationId))
                ->orWhereHas('permissions', fn ($p) => $p->where('name', 'access_all_locations'))
                ->orWhereHas('roles.permissions', fn ($p) => $p->whereIn('name', [
                    'location.'.$locationId, 'access_all_locations',
                ]));
        });
    }

    /* --------------------------------------------------------------------
     | Dropdowns
     -------------------------------------------------------------------- */

    /**
     * System users of the active tenant, keyed by id.
     *
     * `User` deliberately does not use BelongsToBusiness: authentication has to
     * find a user *before* a tenant exists, so a global scope would break
     * login. The tenant filter is therefore applied explicitly here — and it is
     * never optional, or one shop would list another's staff.
     *
     * @return array<int, string>
     */
    public static function forDropdown(?int $businessId = null): array
    {
        return static::dropdownQuery($businessId)->get()
            ->mapWithKeys(fn (self $user) => [$user->id => $user->user_full_name])
            ->all();
    }

    /**
     * Users flagged as commission agents — the only ones a sale may be
     * attributed to.
     *
     * @return array<int, string>
     */
    public static function commissionAgentsForDropdown(?int $businessId = null): array
    {
        return static::dropdownQuery($businessId)
            ->where('is_cmmsn_agnt', 1)
            ->get()
            ->mapWithKeys(fn (self $user) => [$user->id => $user->user_full_name])
            ->all();
    }

    /**
     * Shared base for the user dropdowns: this tenant's real users, in a
     * stable order.
     */
    protected static function dropdownQuery(?int $businessId = null): Builder
    {
        return static::query()
            ->where('business_id', $businessId ?? \App\Support\Tenancy::id())
            ->user()
            ->orderBy('first_name')
            ->orderBy('last_name');
    }

    /* --------------------------------------------------------------------
     | Accessors
     -------------------------------------------------------------------- */

    public function getUserFullNameAttribute(): string
    {
        return trim(
            ($this->surname ? $this->surname.' ' : '')
            .$this->first_name.' '
            .($this->last_name ?? '')
        );
    }

    public function getRoleNameAttribute(): ?string
    {
        $role = $this->roles->first();

        if (empty($role)) {
            return null;
        }

        // Roles are namespaced per tenant as "Admin#3" — strip the suffix.
        return explode('#', $role->name)[0];
    }

    public function getImageUrlAttribute(): string
    {
        $media = $this->media;

        if (! empty($media)) {
            return $media->display_url;
        }

        return asset('img/default-avatar.svg');
    }

    /* --------------------------------------------------------------------
     | Relationships
     -------------------------------------------------------------------- */

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function contactAccess(): BelongsToMany
    {
        return $this->belongsToMany(Contact::class, 'user_contact_access');
    }

    public function documentsAndnote(): MorphMany
    {
        return $this->morphMany(DocumentAndNote::class, 'notable');
    }

    public function media(): MorphOne
    {
        return $this->morphOne(Media::class, 'model');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(
            \App\Modules\Essentials\Models\EssentialsDepartment::class,
            'essentials_department_id'
        );
    }

    public function designation(): BelongsTo
    {
        return $this->belongsTo(
            \App\Modules\Essentials\Models\EssentialsDesignation::class,
            'essentials_designation_id'
        );
    }

    public function employeeDetail()
    {
        return $this->hasOne(
            \App\Modules\Essentials\Models\EssentialsEmployeeDetail::class,
            'user_id'
        );
    }

    /* --------------------------------------------------------------------
     | Helpers
     -------------------------------------------------------------------- */

    /**
     * Tenant-namespaced role check, e.g. hasBusinessRole('Admin').
     */
    public function hasBusinessRole(string $role): bool
    {
        return $this->hasRole($role.'#'.$this->business_id);
    }

    public function isAdmin(): bool
    {
        return $this->hasBusinessRole('Admin');
    }

    /**
     * Superadmin is configured by username in config/constants.php.
     */
    public function isSuperadmin(): bool
    {
        return in_array($this->username, config('constants.administrator_usernames', []), true);
    }
}
