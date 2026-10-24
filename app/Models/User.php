<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use Notifiable;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'users';

    /**
     * The primary key associated with the table.
     *
     * @var string
     */
    protected $primaryKey = 'user_id';

    /**
     * Indicates if the model's ID is auto-incrementing.
     *
     * @var bool
     */
    public $incrementing = true;

    /**
     * The data type of the primary key.
     *
     * @var string
     */
    protected $keyType = 'int';

    /**
     * Indicates if the model should be timestamped.
     *
     * @var bool
     */
    public $timestamps = true;

    /**
     * The name of the "created at" column.
     *
     * @var string|null
     */
    const CREATED_AT = 'created_at';

    /**
     * The name of the "updated at" column matching the existing admin schema.
     *
     * @var string|null
     */
    const UPDATED_AT = 'modified_at';

    /**
     * The attributes that are mass assignable.
     * Sensitive administrative attributes (is_verified, is_deleted, is_active,
     * package, start_date, end_date) are intentionally excluded.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'profile_id',
        'user_type',
        'name',
        'dob',
        'height',
        'complexion',
        'marital_status',
        'disability',
        'qualification',
        'work',
        'religion',
        'sect',
        'caste',
        'accommodation',
        'income',
        'f_name',
        'm_name',
        'sister',
        'brother',
        'prefered_location',
        'email',
        'mobile',
        'alternate_mobile',
        'password',
        'address',
        'n_address',
        'policy',
        'profile_pic',
        'profile_pic1',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'is_active' => 'integer',
        'is_verified' => 'integer',
        'is_deleted' => 'integer',
        'created_at' => 'datetime',
        'modified_at' => 'datetime',
    ];

    /**
     * Check if user has an active, paid membership package.
     *
     * @return bool
     */
    public function hasActivePaidPackage(): bool
    {
        $pkg = strtoupper(trim($this->package ?? ''));
        if (empty($pkg) || in_array($pkg, ['NONE', 'FREE REGISTRATION', 'FREE', 'NO-DETAIL', 'UNPAID', 'EXPIRED'])) {
            return false;
        }

        // Verify end_date if set
        if (!empty($this->end_date)) {
            $endStr = strtoupper(trim($this->end_date));
            if (!str_contains($endStr, 'UNLIMITED') && !str_contains($endStr, 'NIKAH')) {
                try {
                    $endDate = \Carbon\Carbon::parse($this->end_date)->endOfDay();
                    if ($endDate->isPast()) {
                        return false; // Package validity expired
                    }
                } catch (\Throwable $e) {}
            }
        }

        return true;
    }

    /**
     * Check if user package has expired.
     *
     * @return bool
     */
    public function isPackageExpired(): bool
    {
        if (empty($this->end_date)) {
            return false;
        }
        $endStr = strtoupper(trim($this->end_date));
        if (str_contains($endStr, 'UNLIMITED') || str_contains($endStr, 'NIKAH')) {
            return false;
        }
        try {
            $endDate = \Carbon\Carbon::parse($this->end_date)->endOfDay();
            return $endDate->isPast();
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Check if user package is active.
     *
     * @return bool
     */
    public function isPackageActive(): bool
    {
        return !$this->isPackageExpired();
    }

    /**
     * Calculate user age from dob attribute.
     *
     * @return int
     */
    public function calculateAge(): int
    {
        if (empty($this->dob)) {
            return 25;
        }
        $rawAge = 0;
        if (preg_match('/^\d+/', trim($this->dob), $matches)) {
            $rawAge = (int) $matches[0];
        }
        if ($rawAge >= 1900) {
            return (int) date('Y') - $rawAge;
        }
        return $rawAge > 0 ? $rawAge : 25;
    }

    /**
     * Check if user belongs to Shia or Bohra sect.
     *
     * @return bool
     */
    public function isShia(): bool
    {
        $rel = strtolower($this->religion ?? '');
        $sct = strtolower($this->sect ?? '');
        return (
            str_contains($rel, 'shia') ||
            str_contains($rel, 'bohra') ||
            str_contains($sct, 'shia') ||
            str_contains($sct, 'bohra')
        );
    }

    /**
     * Calculate daily unlocked profile count based on days difference * 2.
     *
     * @return int
     */
    public function getUnlockedProfileCount(): int
    {
        $startDate = $this->start_date ?: $this->created_at;
        if (!$startDate) {
            return 2;
        }
        try {
            $currentDate = \Carbon\Carbon::now()->startOfDay();
            $start = \Carbon\Carbon::parse($startDate)->startOfDay();
            $daysDifference = $start->diffInDays($currentDate) + 1;
            return max(2, (int) ($daysDifference * 2));
        } catch (\Throwable $e) {
            return 2;
        }
    }

    /**
     * Get profile view limits record from profile_view_limits table.
     *
     * @return object|null
     */
    public function getProfileViewLimits()
    {
        return \Illuminate\Support\Facades\DB::table('profile_view_limits')
            ->where('viewer_user_id', $this->user_id)
            ->first();
    }

    /**
     * Check if viewer has already viewed given profile.
     *
     * @param int|string $profileUserId
     * @return bool
     */
    public function hasViewedProfile($profileUserId): bool
    {
        return \Illuminate\Support\Facades\DB::table('profile_views')
            ->where('viewer_user_id', $this->user_id)
            ->where('profile_user_id', $profileUserId)
            ->exists();
    }

    /**
     * Calculate days since user registration.
     *
     * @return int
     */
    public function daysSinceRegistration(): int
    {
        $startDate = $this->start_date ?: $this->created_at;
        if (!$startDate) {
            return 0;
        }
        try {
            $created = \Carbon\Carbon::parse($startDate)->startOfDay();
            return max(0, (int) $created->diffInDays(\Carbon\Carbon::now()->startOfDay()));
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * Check if user is within 3 day trial window.
     *
     * @return bool
     */
    public function isWithin3DayTrial(): bool
    {
        return $this->daysSinceRegistration() <= 3;
    }
}
