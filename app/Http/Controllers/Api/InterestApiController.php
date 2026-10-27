<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Interest;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class InterestApiController extends Controller
{
    /**
     * Helper to get authenticated member from request attributes or session.
     */
    private function getAuthUser(Request $request): ?User
    {
        /** @var User|null $user */
        $user = $request->attributes->get('authenticated_member');
        if (!$user) {
            $userId = session('user_id');
            if ($userId) {
                $user = User::where('user_id', $userId)
                    ->where(function ($q) {
                        $q->whereNull('is_deleted')->orWhere('is_deleted', 0);
                    })
                    ->first();
            }
        }
        return $user;
    }

    /**
     * Get list of interests sent by authenticated member.
     */
    public function sent(Request $request): JsonResponse
    {
        $user = $this->getAuthUser($request);
        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated. Active member session is required.',
            ], 401);
        }

        $items = Interest::with('recipient')
            ->where('sender_id', $user->user_id)
            ->where('status', '!=', 2)
            ->orderBy('interest_id', 'desc')
            ->get();

        $profileController = app(ProfileApiController::class);

        $transformed = $items->map(function ($item) use ($profileController) {
            $recipient = $item->recipient;
            $profile = $recipient ? $profileController->formatUserProfile($recipient, true) : null;
            $statusText = 'Pending Response';
            if ($item->status === 1) {
                $statusText = 'Accepted (Mutual Match)';
            } elseif ($item->status === 2) {
                $statusText = 'Declined';
            }

            return [
                'interest_id' => $item->interest_id,
                'target_id' => $item->user_id,
                'status' => (int) $item->status,
                'status_text' => $statusText,
                'created_at' => $item->created_at ? $item->created_at->format('d M Y') : 'Recent',
                'created_time_ago' => $item->created_at ? $item->created_at->diffForHumans() : 'Recently',
                'profile' => $profile,
            ];
        })->filter(function ($item) {
            return !empty($item['profile']);
        })->values();

        return response()->json([
            'success' => true,
            'message' => 'Sent interests retrieved successfully.',
            'count' => $transformed->count(),
            'data' => $transformed,
        ]);
    }

    /**
     * Get list of incoming interests received by authenticated member.
     */
    public function received(Request $request): JsonResponse
    {
        $user = $this->getAuthUser($request);
        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated. Active member session is required.',
            ], 401);
        }

        $items = Interest::with('sender')
            ->where('user_id', $user->user_id)
            ->where('status', 0) // Pending requests
            ->orderBy('interest_id', 'desc')
            ->get();

        $profileController = app(ProfileApiController::class);

        $transformed = $items->map(function ($item) use ($profileController) {
            $sender = $item->sender;
            $profile = $sender ? $profileController->formatUserProfile($sender, true) : null;

            return [
                'interest_id' => $item->interest_id,
                'sender_id' => $item->sender_id,
                'status' => (int) $item->status,
                'status_text' => 'New Interest Request',
                'created_at' => $item->created_at ? $item->created_at->format('d M Y') : 'Recent',
                'created_time_ago' => $item->created_at ? $item->created_at->diffForHumans() : 'Recently',
                'profile' => $profile,
            ];
        })->filter(function ($item) {
            return !empty($item['profile']);
        })->values();

        return response()->json([
            'success' => true,
            'message' => 'Received interests retrieved successfully.',
            'count' => $transformed->count(),
            'data' => $transformed,
        ]);
    }

    /**
     * Get list of mutually accepted interests (contacts unlocked).
     */
    public function accepted(Request $request): JsonResponse
    {
        $user = $this->getAuthUser($request);
        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated. Active member session is required.',
            ], 401);
        }

        $items = Interest::with(['sender', 'recipient'])
            ->where(function ($q) use ($user) {
                $q->where('user_id', $user->user_id)
                  ->orWhere('sender_id', $user->user_id);
            })
            ->where('status', 1)
            ->orderBy('interest_id', 'desc')
            ->get();

        $profileController = app(ProfileApiController::class);

        $transformed = $items->map(function ($item) use ($user, $profileController) {
            $isSender = ($item->sender_id == $user->user_id);
            $partner = $isSender ? $item->recipient : $item->sender;
            $profile = $partner ? $profileController->formatUserProfile($partner, true) : null;

            return [
                'interest_id' => $item->interest_id,
                'partner_id' => $partner ? $partner->user_id : null,
                'is_sender' => $isSender,
                'status' => 1,
                'status_text' => 'Mutual Match',
                'created_at' => $item->created_at ? $item->created_at->format('d M Y') : 'Recent',
                'created_time_ago' => $item->created_at ? $item->created_at->diffForHumans() : 'Recently',
                'profile' => $profile,
                'contact' => [
                    'mobile' => $partner && !empty($partner->mobile) ? $partner->mobile : 'Not Disclosed',
                    'alternate_mobile' => $partner && !empty($partner->alternate_mobile) ? $partner->alternate_mobile : '',
                    'email' => $partner && !empty($partner->email) ? $partner->email : '',
                    'address' => $partner && !empty($partner->address) ? $partner->address : '',
                ],
            ];
        })->filter(function ($item) {
            return !empty($item['profile']);
        })->values();

        return response()->json([
            'success' => true,
            'message' => 'Accepted interests retrieved successfully.',
            'count' => $transformed->count(),
            'data' => $transformed,
        ]);
    }

    /**
     * Send an express interest to another candidate.
     */
    public function send(Request $request): JsonResponse
    {
        $user = $this->getAuthUser($request);
        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Please login to express interest in a candidate.',
            ], 401);
        }

        $inputData = $request->isJson() ? $request->json()->all() : $request->all();
        $targetId = $inputData['user_id'] ?? $inputData['profile_id'] ?? $inputData['id'] ?? null;

        if (!$targetId) {
            return response()->json([
                'success' => false,
                'message' => 'Target profile identifier is required.',
            ], 422);
        }

        // Resolve by user_id or profile_id
        $targetUser = User::where('user_id', $targetId)
            ->orWhere('profile_id', $targetId)
            ->first();

        if (!$targetUser) {
            return response()->json([
                'success' => false,
                'message' => 'Candidate profile not found.',
            ], 404);
        }

        if ($targetUser->user_id == $user->user_id) {
            return response()->json([
                'success' => false,
                'message' => 'You cannot express interest in your own profile.',
            ], 422);
        }

        // Check if an existing interest already exists
        $existing = Interest::where(function ($q) use ($user, $targetUser) {
            $q->where('sender_id', $user->user_id)->where('user_id', $targetUser->user_id);
        })->orWhere(function ($q) use ($user, $targetUser) {
            $q->where('sender_id', $targetUser->user_id)->where('user_id', $user->user_id);
        })->first();

        if ($existing) {
            if ($existing->status === 1) {
                return response()->json([
                    'success' => true,
                    'already_exists' => true,
                    'message' => "You and {$targetUser->name} are already mutually connected!",
                ]);
            }
            if ($existing->sender_id == $user->user_id && $existing->status === 0) {
                return response()->json([
                    'success' => true,
                    'already_exists' => true,
                    'message' => "Interest already sent to {$targetUser->name}. Awaiting family response.",
                ]);
            }
            if ($existing->user_id == $user->user_id && $existing->status === 0) {
                // Partner had sent interest to current user; auto-accept!
                $existing->status = 1;
                $existing->save();

                // Dispatch notification to partner that their interest was accepted
                try {
                    app(NotificationService::class)->notifyInterestAccepted($existing);
                } catch (\Throwable $e) {}

                return response()->json([
                    'success' => true,
                    'is_mutual' => true,
                    'message' => "Alhamdulillah! Mutual Match! {$targetUser->name} had also sent you an interest.",
                ]);
            }
        }

        // Create new interest
        $interest = Interest::create([
            'sender_id' => $user->user_id,
            'user_id' => $targetUser->user_id,
            'status' => 0,
            'created_at' => now(),
        ]);

        // Dispatch browser push notification to candidate recipient
        try {
            app(NotificationService::class)->notifyInterestReceived($interest);
        } catch (\Throwable $e) {}

        return response()->json([
            'success' => true,
            'message' => "Alhamdulillah! Your interest has been sent to {$targetUser->name}.",
            'data' => [
                'interest_id' => $interest->interest_id,
                'status' => 0,
            ],
        ], 201);

    }

    /**
     * Accept an incoming interest request.
     */
    public function accept(Request $request, $id): JsonResponse
    {
        $user = $this->getAuthUser($request);
        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated.',
            ], 401);
        }

        $interest = Interest::with('sender')
            ->where('interest_id', $id)
            ->where('user_id', $user->user_id)
            ->first();

        if (!$interest) {
            return response()->json([
                'success' => false,
                'message' => 'Interest request not found or unauthorized.',
            ], 404);
        }

        $interest->status = 1;
        $interest->save();

        // Dispatch browser push notification to sender that their proposal was accepted
        try {
            app(NotificationService::class)->notifyInterestAccepted($interest);
        } catch (\Throwable $e) {}

        $sender = $interest->sender;
        $contactMobile = $sender && !empty($sender->mobile) ? $sender->mobile : 'Registered Contact';


        return response()->json([
            'success' => true,
            'message' => "Alhamdulillah! Interest accepted. Contact unlocked: {$contactMobile}",
            'contact' => [
                'mobile' => $sender ? $sender->mobile : '',
                'email' => $sender ? $sender->email : '',
            ],
        ]);
    }

    /**
     * Decline an incoming interest request.
     */
    public function decline(Request $request, $id): JsonResponse
    {
        $user = $this->getAuthUser($request);
        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated.',
            ], 401);
        }

        $interest = Interest::where('interest_id', $id)
            ->where('user_id', $user->user_id)
            ->first();

        if (!$interest) {
            return response()->json([
                'success' => false,
                'message' => 'Interest request not found or unauthorized.',
            ], 404);
        }

        $interest->status = 2; // Declined
        $interest->save();

        return response()->json([
            'success' => true,
            'message' => 'Interest declined politely.',
        ]);
    }

    /**
     * Cancel / withdraw a sent interest.
     */
    public function cancel(Request $request, $id): JsonResponse
    {
        $user = $this->getAuthUser($request);
        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated.',
            ], 401);
        }

        $interest = Interest::where('interest_id', $id)
            ->where('sender_id', $user->user_id)
            ->first();

        if (!$interest) {
            return response()->json([
                'success' => false,
                'message' => 'Sent interest record not found or unauthorized.',
            ], 404);
        }

        $interest->delete();

        return response()->json([
            'success' => true,
            'message' => 'Interest request cancelled.',
        ]);
    }

    /**
     * Get summary counts for Inbox badges.
     */
    public function counts(Request $request): JsonResponse
    {
        $user = $this->getAuthUser($request);
        if (!$user) {
            return response()->json([
                'success' => false,
                'sent' => 0,
                'received' => 0,
                'accepted' => 0,
            ]);
        }

        $sentCount = Interest::where('sender_id', $user->user_id)->where('status', '!=', 2)->count();
        $receivedCount = Interest::where('user_id', $user->user_id)->where('status', 0)->count();
        $acceptedCount = Interest::where(function ($q) use ($user) {
            $q->where('user_id', $user->user_id)->orWhere('sender_id', $user->user_id);
        })->where('status', 1)->count();

        return response()->json([
            'success' => true,
            'sent' => $sentCount,
            'received' => $receivedCount,
            'accepted' => $acceptedCount,
        ]);
    }
}
