<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

class EnsureMemberAuthenticated
{
    /**
     * Handle an incoming request to ensure the user is an authenticated active member.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Explicitly reject if current session belongs to an admin only
        $isAdmin = session('is_admin', false);
        $userId = session('user_id');

        // Check Laravel Auth guard or session user_id
        if (!$userId && Auth::check()) {
            $userId = Auth::id();
        }

        if (!$userId || $isAdmin) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated. Active member session is required.',
            ], 401);
        }

        $user = User::where('user_id', $userId)
            ->where(function ($q) {
                $q->whereNull('is_deleted')->orWhere('is_deleted', 0);
            })
            ->first();

        if (!$user) {
            session()->forget(['user_id', 'profile_id', 'user_name', 'user_email', 'user_type', 'user', 'role']);
            return response()->json([
                'success' => false,
                'message' => 'Member account not found or has been deactivated.',
            ], 401);
        }

        // Attach verified user instance to the request attributes (not request input)
        $request->attributes->set('authenticated_member', $user);

        // Ensure Laravel Auth facade is synced with this user
        if (!Auth::check() || Auth::id() !== $user->user_id) {
            Auth::setUser($user);
        }

        return $next($request);
    }
}
