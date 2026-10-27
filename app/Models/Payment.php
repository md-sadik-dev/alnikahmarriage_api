<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    protected $table = 'payments';
    protected $primaryKey = 'payment_id';
    public $timestamps = true;

    const CREATED_AT = 'created_at';
    const UPDATED_AT = 'updated_at';

    protected $fillable = [
        'transaction_id',
        'user_id',
        'profile_id',
        'user_name',
        'user_email',
        'user_mobile',
        'plan_id',
        'plan_name',
        'amount',
        'currency',
        'payment_method',
        'gateway',
        'gateway_reference',
        'payment_status',
        'correction_status',
        'correction_reason',
        'corrected_by',
        'corrected_at',
        'activated_at',
        'notes',
    ];

    protected $casts = [
        'user_id' => 'integer',
        'plan_id' => 'integer',
        'amount' => 'decimal:2',
        'corrected_by' => 'integer',
        'corrected_at' => 'datetime',
        'activated_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'plan_id', 'plan_id');
    }
}
