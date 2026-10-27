<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model
{
    protected $table = 'audit_logs';
    public $timestamps = false; // We use created_at only

    protected $fillable = [
        'actor_id',
        'actor_type',
        'actor_name',
        'actor_email',
        'action',
        'module',
        'target_id',
        'target_type',
        'old_values',
        'new_values',
        'reason',
        'ip_address',
        'user_agent',
        'created_at',
    ];

    protected $casts = [
        'actor_id' => 'integer',
        'created_at' => 'datetime',
    ];

    /**
     * Helper to write an audit log entry easily.
     */
    public static function record(
        string $action,
        string $module,
        ?string $targetId = null,
        $oldValues = null,
        $newValues = null,
        ?string $reason = null,
        $actor = null,
        ?string $targetType = null
    ): self {
        $request = request();

        $actorId = null;
        $actorType = 'operator';
        $actorName = 'System';
        $actorEmail = null;

        if ($actor instanceof Admin) {
            $actorId = $actor->id;
            $actorType = $actor->role ?? 'operator';
            $actorName = $actor->username;
            $actorEmail = $actor->email;
        } elseif ($actor instanceof User) {
            $actorId = $actor->user_id;
            $actorType = 'customer';
            $actorName = $actor->name;
            $actorEmail = $actor->email;
        } else {
            // Check session
            if (session('is_admin') || session('admin_id')) {
                $actorId = session('admin_id');
                $actorType = session('role') ?? 'operator';
                $actorName = session('admin_username') ?? 'Admin';
                $actorEmail = session('admin_email');
            } elseif (session('user_id')) {
                $actorId = session('user_id');
                $actorType = 'customer';
                $actorName = session('user_name') ?? 'Member';
                $actorEmail = session('user_email');
            }
        }

        $oldJson = is_array($oldValues) ? json_encode($oldValues, JSON_UNESCAPED_UNICODE) : (is_string($oldValues) ? $oldValues : null);
        $newJson = is_array($newValues) ? json_encode($newValues, JSON_UNESCAPED_UNICODE) : (is_string($newValues) ? $newValues : null);

        return self::create([
            'actor_id' => $actorId,
            'actor_type' => $actorType,
            'actor_name' => $actorName,
            'actor_email' => $actorEmail,
            'action' => $action,
            'module' => $module,
            'target_id' => $targetId,
            'target_type' => $targetType,
            'old_values' => $oldJson,
            'new_values' => $newJson,
            'reason' => $reason,
            'ip_address' => $request ? $request->ip() : null,
            'user_agent' => $request ? substr($request->userAgent() ?? '', 0, 500) : null,
            'created_at' => now(),
        ]);
    }
}
