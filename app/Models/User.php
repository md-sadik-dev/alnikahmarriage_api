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
}
