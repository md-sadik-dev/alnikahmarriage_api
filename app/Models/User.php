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
     * Calculate days since registration based on created_at timestamp.
     *
     * @return int
     */
    public function daysSinceRegistration(): int
    {
        if (!$this->created_at) {
            return 999;
        }
        return (int) \Carbon\Carbon::parse($this->created_at)->diffInDays(\Carbon\Carbon::now());
    }

    /**
     * Check if user is within the initial 3-day signup trial window.
     *
     * @return bool
     */
    public function isWithin3DayTrial(): bool
    {
        return $this->daysSinceRegistration() <= 3;
    }
}
