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

        if (!$userId && $request->hasHeader('X-User-Id')) {
            $hdr = (int) $request->header('X-User-Id');
            if ($hdr > 0) $userId = $hdr;
        }

        if (!$userId && ($request->filled('profile_id') || $request->filled('user_id'))) {
            $pId = trim($request->input('profile_id') ?? '');
            $uId = $request->input('user_id');
            $candidate = User::where(function ($q) {
                $q->whereNull('is_deleted')->orWhere('is_deleted', 0);
            })->where(function($q) use ($pId, $uId) {
                if ($pId) $q->where('profile_id', $pId);
                if ($uId) $q->orWhere('user_id', $uId);
            })->first();
            if ($candidate) {
                $userId = $candidate->user_id;
            }
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
