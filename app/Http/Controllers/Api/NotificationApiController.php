<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use App\Models\PushSubscription;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class NotificationApiController extends Controller
{
    /**
     * Resolve authenticated member from request attributes or session.
     */
    protected function getAuthUser(Request $request): ?User
    {
        $user = $request->attributes->get('authenticated_member');
        if ($user instanceof User) {
            return $user;
        }

        if (Auth::check()) {
            $user = Auth::user();
            if ($user instanceof User) {
                return $user;
            }
        }

        $userId = session('user_id');
        if ($userId) {
            return User::where('user_id', $userId)
                ->where(function ($q) {
                    $q->whereNull('is_deleted')->orWhere('is_deleted', 0);
                })
                ->first();
        }

        return null;
    }

    /**
     * Return public Firebase Web Configuration & VAPID key.
     */
    public function config(): JsonResponse
    {
        $webConfig = config('firebase.web_config');
        $vapidKey = config('firebase.vapid_key');

        return response()->json([
            'success' => true,
            'config' => $webConfig,
            'vapid_key' => $vapidKey,
        ]);
    }

    /**
     * Register or refresh browser push subscription token for authenticated member.
     */
    public function subscribe(Request $request): JsonResponse
    {
        $user = $this->getAuthUser($request);
        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated. Active member login is required to enable notifications.',
            ], 401);
        }

        $validator = Validator::make($request->all(), [
            'token' => 'required|string|min:20|max:512',
            'device_name' => 'nullable|string|max:100',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first(),
                'errors' => $validator->errors(),
            ], 422);
        }

        $token = trim($request->input('token'));
        $userAgent = substr($request->userAgent() ?? '', 0, 500);
        $deviceName = trim($request->input('device_name') ?? 'Web Browser');

        // Check if token already exists (e.g. from previous session or device switch)
        $subscription = PushSubscription::where('token', $token)->first();

        if ($subscription) {
            // Re-assign ownership to current authenticated member safely
            $subscription->user_id = $user->user_id;
            $subscription->user_agent = $userAgent;
            $subscription->device_name = $deviceName;
            $subscription->is_active = true;
            $subscription->last_used_at = now();
            $subscription->save();
        } else {
            $subscription = PushSubscription::create([
                'user_id' => $user->user_id,
                'token' => $token,
                'user_agent' => $userAgent,
                'device_name' => $deviceName,
                'is_active' => true,
                'last_used_at' => now(),
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Browser push notification subscription registered successfully.',
            'data' => [
                'subscription_id' => $subscription->id,
                'user_id' => $user->user_id,
                'is_active' => true,
            ],
        ]);
    }

    /**
     * Unsubscribe / remove push subscription for authenticated member.
     */
    public function unsubscribe(Request $request): JsonResponse
    {
        $user = $this->getAuthUser($request);
        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated.',
            ], 401);
        }

        $token = trim($request->input('token') ?? '');

        if (!empty($token)) {
            // Unregister only the specific device token
            PushSubscription::where('token', $token)
                ->where('user_id', $user->user_id)
                ->update(['is_active' => false]);
        } else {
            // Unregister all devices if no specific token is provided
            PushSubscription::where('user_id', $user->user_id)
                ->update(['is_active' => false]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Push notifications disabled for this device.',
        ]);
    }

    /**
     * Get paginated notification history for authenticated member.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $this->getAuthUser($request);
        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated.',
            ], 401);
        }

        $limit = min(50, max(5, (int) $request->input('limit', 15)));

        $notifications = Notification::where('user_id', $user->user_id)
            ->orderBy('id', 'desc')
            ->paginate($limit);

        $transformed = $notifications->getCollection()->map(function ($n) {
            return [
                'id' => $n->id,
                'type' => $n->type,
                'title' => $n->title,
                'body' => $n->body,
                'action_url' => $n->action_url,
                'data' => $n->data,
                'is_read' => !is_null($n->read_at),
                'created_at' => $n->created_at ? $n->created_at->format('Y-m-d H:i') : null,
                'created_ago' => $n->created_at ? $n->created_at->diffForHumans() : null,
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $transformed,
            'unread_count' => Notification::where('user_id', $user->user_id)->unread()->count(),
            'pagination' => [
                'current_page' => $notifications->currentPage(),
                'last_page' => $notifications->lastPage(),
                'total' => $notifications->total(),
            ],
        ]);
    }

    /**
     * Get count of unread notifications for authenticated member.
     */
    public function unreadCount(Request $request): JsonResponse
    {
        $user = $this->getAuthUser($request);
        if (!$user) {
            return response()->json([
                'success' => false,
                'unread_count' => 0,
            ]);
        }

        $count = Notification::where('user_id', $user->user_id)
            ->unread()
            ->count();

        return response()->json([
            'success' => true,
            'unread_count' => $count,
        ]);
    }

    /**
     * Mark a specific notification as read.
     */
    public function markAsRead(Request $request, $id): JsonResponse
    {
        $user = $this->getAuthUser($request);
        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated.',
            ], 401);
        }

        $notification = Notification::where('id', $id)
            ->where('user_id', $user->user_id)
            ->first();

        if (!$notification) {
            return response()->json([
                'success' => false,
                'message' => 'Notification not found.',
            ], 404);
        }

        $notification->markAsRead();

        return response()->json([
            'success' => true,
            'message' => 'Notification marked as read.',
        ]);
    }

    /**
     * Mark all notifications for authenticated member as read.
     */
    public function markAllAsRead(Request $request): JsonResponse
    {
        $user = $this->getAuthUser($request);
        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated.',
            ], 401);
        }

        Notification::where('user_id', $user->user_id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return response()->json([
            'success' => true,
            'message' => 'All notifications marked as read.',
        ]);
    }
}
