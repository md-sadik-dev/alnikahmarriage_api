<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use App\Models\Admin;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

class RoleMiddleware
{
    /**
     * Handle an incoming request to ensure the user has one of the allowed roles.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     * @param  string  ...$roles Allowed roles (e.g. 'operator', 'super_admin', 'customer')
     */
    public function handle(Request $request, Closure $next, ...$roles): Response
    {
        $currentRole = null;
        $currentUser = null;

        // 1. Check Admin / Operator session
        $adminId = session('admin_id');
        if ($adminId) {
            $admin = Admin::where('id', $adminId)->where('status', 1)->first();
            if ($admin) {
                $currentUser = $admin;
                if ($admin->isSuperAdmin()) {
                    $currentRole = 'super_admin';
                } elseif ($admin->isOperator()) {
                    $currentRole = 'operator';
                } else {
                    $currentRole = ($admin->role === 'super_admin' || $admin->type === 'Admin') ? 'super_admin' : 'operator';
                }
            }
        }

        // 2. Check Customer / Member session or Auth if not admin
        if (!$currentUser) {
            $userId = session('user_id') ?: Auth::id();

            if (!$userId && $request->hasHeader('X-User-Id')) {
                $hdr = (int) $request->header('X-User-Id');
                if ($hdr > 0) $userId = $hdr;
            }

            if ($userId) {
                $user = User::where('user_id', $userId)
                    ->where(function ($q) {
                        $q->whereNull('is_deleted')->orWhere('is_deleted', 0);
                    })
                    ->first();
                if ($user) {
                    $currentUser = $user;
                    $userRole = strtolower(trim($user->role ?? 'customer'));
                    if (in_array($userRole, ['super_admin', 'operator', 'customer'])) {
                        $currentRole = $userRole;
                    } else {
                        $currentRole = 'customer';
                    }
                }
            }
        }

        // 3. Unauthenticated Check
        if (!$currentUser || !$currentRole) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated. Please log in to continue.',
            ], 401);
        }

        // 4. Role Authorization Check
        // Normalize requested roles (e.g. support comma-separated or multiple arguments)
        $allowedRoles = [];
        foreach ($roles as $r) {
            foreach (explode(',', $r) as $part) {
                $allowedRoles[] = strtolower(trim($part));
            }
        }

        // Backward compatibility mappings: 'admin' => 'super_admin', 'user' => 'customer'
        if (in_array('admin', $allowedRoles) && !in_array('super_admin', $allowedRoles)) {
            $allowedRoles[] = 'super_admin';
        }
        if (in_array('user', $allowedRoles) && !in_array('customer', $allowedRoles)) {
            $allowedRoles[] = 'customer';
        }

        $isAuthorized = in_array($currentRole, $allowedRoles, true);

        // Super Admin is implicitly authorized if 'operator' is requested unless specifically restricted
        if (!$isAuthorized && $currentRole === 'super_admin' && in_array('operator', $allowedRoles)) {
            $isAuthorized = true;
        }

        if (!$isAuthorized) {
            return response()->json([
                'success' => false,
                'message' => 'Forbidden: You do not have permission to access this resource.',
                'current_role' => $currentRole,
                'required_roles' => $allowedRoles,
            ], 403);
        }

        // Attach verified user and role to request
        $request->attributes->set('auth_user', $currentUser);
        $request->attributes->set('auth_role', $currentRole);

        return $next($request);
    }
}
