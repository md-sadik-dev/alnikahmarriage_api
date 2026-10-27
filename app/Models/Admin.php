<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Admin extends Model
{
    protected $table = 'admin';
    protected $primaryKey = 'id';
    public $timestamps = true;

    const CREATED_AT = 'created_at';
    const UPDATED_AT = 'updated_at';

    protected $fillable = [
        'username',
        'email',
        'password',
        'mobile',
        'type',
        'role',
        'image',
        'status',
        'login_ip',
        'login_date',
    ];

    protected $hidden = [
        'password',
    ];

    protected $casts = [
        'status' => 'integer',
        'login_date' => 'datetime',
    ];

    public function isSuperAdmin(): bool
    {
        return $this->role === 'super_admin' 
            || str_contains(strtolower($this->type ?? ''), 'super') 
            || str_contains(strtolower($this->type ?? ''), 'master')
            || (int)$this->id === 1;
    }

    public function isOperator(): bool
    {
        return $this->role === 'operator' || strtolower($this->type ?? '') === 'operator';
    }
}
