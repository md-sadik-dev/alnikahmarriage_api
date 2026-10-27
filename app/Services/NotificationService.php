<?php

namespace App\Services;

use App\Models\Interest;
use App\Models\Notification;
use App\Models\NotificationDelivery;
use App\Models\PushSubscription;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class NotificationService
{
    protected FcmService $fcmService;

    public function __construct(FcmService $fcmService)
    {
        $this->fcmService = $fcmService;
    }

    /**
     * Create a notification record and dispatch browser push to active subscriptions.
     *
     * @param int $userId Recipient member user_id
     * @param string $type Notification category
     * @param string $title Notification title
     * @param string $body Notification message
     * @param string $actionUrl Internal redirect URL
     * @param string|null $dedupKey Unique deduplication key
     * @param array $extraData Additional payload data
     * @return Notification|null The created or existing notification model
     */
    public function createAndDispatch(
        int $userId,
        string $type,
        string $title,
        string $body,
        string $actionUrl = 'dashboard',
        ?string $dedupKey = null,
        array $extraData = []
    ): ?Notification {
        try {
            // 1. Deduplication check
            if (!empty($dedupKey)) {
                $existing = Notification::where('dedup_key', $dedupKey)->first();
                if ($existing) {
                    return $existing; // Duplicate prevented
                }
            }

            // 2. Persist notification in database
            $notification = Notification::create([
                'user_id' => $userId,
                'type' => $type,
                'title' => $title,
                'body' => $body,
                'action_url' => $actionUrl,
                'data' => $extraData,
                'dedup_key' => $dedupKey,
            ]);

            // 3. Find active push subscriptions for this member
            $subscriptions = PushSubscription::where('user_id', $userId)
                ->where('is_active', true)
                ->get();

            if ($subscriptions->isEmpty()) {
                // Record pending delivery attempt so status is known
                NotificationDelivery::create([
                    'notification_id' => $notification->id,
                    'push_subscription_id' => null,
                    'status' => 'queued',
                    'error_message' => 'No active browser push subscriptions registered for this member.',
                    'attempt_count' => 0,
                ]);

                return $notification;
            }

            // 4. Send FCM push to each registered device
            foreach ($subscriptions as $sub) {
                $delivery = NotificationDelivery::create([
                    'notification_id' => $notification->id,
                    'push_subscription_id' => $sub->id,
                    'status' => 'queued',
                    'attempt_count' => 1,
                ]);

                $res = $this->fcmService->sendToToken(
                    $sub->token,
                    $title,
                    $body,
                    $actionUrl,
                    array_merge($extraData, [
                        'notification_id' => $notification->id,
                        'type' => $type,
                    ])
                );

                if ($res['success']) {
                    $delivery->update([
                        'status' => 'sent',
                        'fcm_message_id' => $res['message_id'] ?? null,
                        'sent_at' => now(),
                    ]);
                } else {
                    $delivery->update([
                        'status' => $res['status'] ?? 'failed',
                        'error_message' => $res['error'] ?? 'Delivery failed',
                    ]);
                }
            }

            return $notification;
        } catch (\Throwable $e) {
            Log::error("[NotificationService] Exception creating/dispatching notification: " . $e->getMessage());
            return null;
        }
    }

    // =========================================================================
    // FEATURE 1: PACKAGE EXPIRY REMINDERS
    // =========================================================================

    /**
     * Send package expiration reminder to an eligible member.
     *
     * @param User $user
     * @param int $intervalDays Days before expiration (7, 3, 1, 0)
     * @return Notification|null
     */
    public function notifyPackageExpiry(User $user, int $intervalDays): ?Notification
    {
        // Re-check package eligibility immediately before dispatch
        if (!$user->hasActivePaidPackage() && $intervalDays > 0) {
            return null;
        }

        $packageName = $user->package ?: 'Membership';
        $endDate = $user->end_date;
        if (empty($endDate)) {
            return null;
        }

        $dedupKey = "expiry_{$user->user_id}_{$packageName}_{$endDate}_{$intervalDays}d";

        $titles = [
            7 => 'Membership Reminder: 7 Days Remaining',
            3 => 'Important: Your Package Expires in 3 Days',
            1 => 'Urgent: Your Membership Expires Tomorrow',
            0 => 'Your Membership Package Has Expired',
        ];

        $messages = [
            7 => "Assalamu Alaikum {$user->name}, your {$packageName} package expires on {$endDate}. Renew now to continue connecting with verified families uninterrupted.",
            3 => "Assalamu Alaikum {$user->name}, only 3 days remain on your {$packageName} membership. Renew today to maintain direct proposal and contact access.",
            1 => "Assalamu Alaikum {$user->name}, your {$packageName} membership expires tomorrow ({$endDate}). Please renew to prevent profile unlock restrictions.",
            0 => "Assalamu Alaikum {$user->name}, your {$packageName} package has expired. Renew your plan to unlock profiles and express interest.",
        ];

        $title = $titles[$intervalDays] ?? "Package Expiry Reminder ({$intervalDays} days)";
        $message = $messages[$intervalDays] ?? "Your {$packageName} package expires soon on {$endDate}.";

        return $this->createAndDispatch(
            $user->user_id,
            'package_expiry',
            $title,
            $message,
            'packages',
            $dedupKey,
            [
                'package' => $packageName,
                'end_date' => $endDate,
                'days_remaining' => $intervalDays,
            ]
        );
    }

    // =========================================================================
    // FEATURE 2: NEW MATCHING PROFILES
    // =========================================================================

    /**
     * Identify and notify eligible members matching a newly published/verified profile.
     *
     * @param User $candidate The newly published candidate profile
     * @param int $limit Max recipients to notify in this batch
     * @return int Number of matching members notified
     */
    public function notifyNewMatchingProfile(User $candidate, int $limit = 50): int
    {
        // Candidate must be verified, active, and not deleted
        if (empty($candidate->is_verified) || empty($candidate->is_active) || !empty($candidate->is_deleted)) {
            return 0;
        }

        $candidateType = strtoupper(trim($candidate->user_type ?? ''));
        if (!in_array($candidateType, ['BRIDE', 'GROOM'])) {
            return 0;
        }

        // Opposite gender rule
        $targetGender = ($candidateType === 'BRIDE') ? 'GROOM' : 'BRIDE';

        $query = User::query()
            ->where('user_type', $targetGender)
            ->where('user_id', '<>', $candidate->user_id)
            ->where('is_verified', 1)
            ->where('is_active', 1)
            ->where(function ($q) {
                $q->whereNull('is_deleted')->orWhere('is_deleted', 0);
            });

        // Firqa / Sect Isolation Rule
        if ($candidate->isShia()) {
            $query->where(function ($q) {
                $q->where('religion', 'LIKE', '%shia%')
                  ->orWhere('religion', 'LIKE', '%bohra%')
                  ->orWhere('sect', 'LIKE', '%shia%')
                  ->orWhere('sect', 'LIKE', '%bohra%');
            });
        } else {
            $query->where(function ($q) {
                $q->where('religion', 'NOT LIKE', '%shia%')
                  ->where('religion', 'NOT LIKE', '%bohra%')
                  ->where('sect', 'NOT LIKE', '%shia%')
                  ->where('sect', 'NOT LIKE', '%bohra%');
            });
        }

        // Marital status matching
        $candidateMarital = strtolower(trim($candidate->marital_status ?? ''));
        $isCandidateUnmarried = str_contains($candidateMarital, 'unmarr') || $candidateMarital === 'single' || str_contains($candidateMarital, 'never marri');
        $isCandidateDivorced = str_contains($candidateMarital, 'divor') || str_contains($candidateMarital, 'khul') || str_contains($candidateMarital, 'widow');

        if ($isCandidateUnmarried) {
            $query->where(function ($q) {
                $q->whereRaw("(LOWER(marital_status) LIKE '%unmarr%' OR LOWER(marital_status) = 'single' OR LOWER(marital_status) LIKE '%never marri%')");
            });
        } elseif ($isCandidateDivorced) {
            $query->where(function ($q) {
                $q->whereRaw("(LOWER(marital_status) LIKE '%divor%' OR LOWER(marital_status) LIKE '%khul%' OR LOWER(marital_status) LIKE '%widow%')");
            });
        }

        $recipients = $query->limit($limit)->get();
        $notifiedCount = 0;

        foreach ($recipients as $recipient) {
            $dedupKey = "match_{$recipient->user_id}_{$candidate->user_id}";

            $title = "New Matching {$candidateType} Profile";
            $message = "Assalamu Alaikum! A new compatible {$candidateType} profile from {$candidate->sect} community has joined Al-Nikah. View details in your dashboard.";
            $actionUrl = "profile-detail?id=" . urlencode($candidate->profile_id ?: (string)$candidate->user_id);

            $notif = $this->createAndDispatch(
                $recipient->user_id,
                'matching_profile',
                $title,
                $message,
                $actionUrl,
                $dedupKey,
                [
                    'candidate_id' => $candidate->user_id,
                    'candidate_profile_id' => $candidate->profile_id,
                    'candidate_type' => $candidateType,
                ]
            );

            if ($notif && $notif->wasRecentlyCreated) {
                $notifiedCount++;
            }
        }

        return $notifiedCount;
    }

    // =========================================================================
    // FEATURE 3: PROFILE APPROVAL / VERIFICATION
    // =========================================================================

    /**
     * Notify member when admin approves, rejects, or updates their profile verification.
     *
     * @param User $user The affected member
     * @param int $isVerified Verification status (1: Verified, 0: Unverified)
     * @param int $isActive Visibility status (1: Active, 0: Hidden)
     * @return Notification|null
     */
    public function notifyProfileApproval(User $user, int $isVerified, int $isActive = 1): ?Notification
    {
        $today = date('Ymd');

        if ($isVerified == 1) {
            $title = 'Profile Verified & Approved';
            $message = "Assalamu Alaikum {$user->name}, your matrimonial profile has been verified and is now live for matching members to connect.";
            $actionUrl = 'dashboard';
            $dedupKey = "verified_{$user->user_id}_{$today}";
        } else {
            $title = 'Profile Verification Update';
            $message = "Assalamu Alaikum {$user->name}, your profile requires review or corrections before verification. Please check your dashboard.";
            $actionUrl = 'dashboard';
            $dedupKey = "unverified_{$user->user_id}_{$today}";
        }

        return $this->createAndDispatch(
            $user->user_id,
            'profile_status',
            $title,
            $message,
            $actionUrl,
            $dedupKey,
            [
                'is_verified' => $isVerified,
                'is_active' => $isActive,
            ]
        );
    }

    // =========================================================================
    // FEATURE 4: INTEREST RECEIVED / RESPONSE
    // =========================================================================

    /**
     * Notify recipient when another member sends an interest proposal.
     *
     * @param Interest $interest
     * @return Notification|null
     */
    public function notifyInterestReceived(Interest $interest): ?Notification
    {
        $recipientId = (int) $interest->user_id;
        $sender = User::find($interest->sender_id);
        if (!$sender || empty($recipientId)) {
            return null;
        }

        $senderType = strtoupper(trim($sender->user_type ?? 'Member'));
        $title = 'New Proposal Received';
        $message = "Assalamu Alaikum! A candidate has expressed interest in your profile on Al-Nikah. Review their proposal in your Inbox.";
        $actionUrl = 'dashboard';
        $dedupKey = "interest_recv_{$interest->interest_id}";

        return $this->createAndDispatch(
            $recipientId,
            'interest_received',
            $title,
            $message,
            $actionUrl,
            $dedupKey,
            [
                'interest_id' => $interest->interest_id,
                'sender_id' => $sender->user_id,
                'sender_profile_id' => $sender->profile_id,
            ]
        );
    }

    /**
     * Notify original sender when recipient accepts their interest proposal.
     *
     * @param Interest $interest
     * @return Notification|null
     */
    public function notifyInterestAccepted(Interest $interest): ?Notification
    {
        $senderId = (int) $interest->sender_id;
        $recipient = User::find($interest->user_id);
        if (!$recipient || empty($senderId)) {
            return null;
        }

        $title = 'Interest Accepted — Mutual Match!';
        $message = "Alhamdulillah! Your expressed interest was accepted. You are now mutually connected. View contact details in your Inbox.";
        $actionUrl = 'dashboard';
        $dedupKey = "interest_accept_{$interest->interest_id}";

        return $this->createAndDispatch(
            $senderId,
            'interest_response',
            $title,
            $message,
            $actionUrl,
            $dedupKey,
            [
                'interest_id' => $interest->interest_id,
                'partner_id' => $recipient->user_id,
                'partner_profile_id' => $recipient->profile_id,
                'status' => 'accepted',
            ]
        );
    }
}
