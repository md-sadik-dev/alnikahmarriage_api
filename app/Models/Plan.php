<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Plan extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'plans';

    /**
     * The primary key associated with the table.
     *
     * @var string
     */
    protected $primaryKey = 'plan_id';

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
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'plan_name',
        'plan_duration',
        'plan_price',
        'plan_discount',
        'package_limit',
        'daily_limit',
        'plan_text1',
        'plan_text2',
        'plan_text3',
        'plan_text4',
        'plan_text5',
        'plan_text6',
        'is_active',
        'created_by',
        'modified_by',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'is_active' => 'integer',
        'package_limit' => 'integer',
        'daily_limit' => 'integer',
        'created_at' => 'datetime',
        'modified_at' => 'datetime',
    ];
}
