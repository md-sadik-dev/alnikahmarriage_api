<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Video extends Model
{
    protected $table = 'videos';

    public $timestamps = false;

    protected $fillable = [
        'title',
        'video_link',
        'status',
        'updated_at',
    ];

    protected $casts = [
        'status' => 'integer',
        'updated_at' => 'datetime',
    ];
}
