<?php

namespace App\Console\Commands;

use App\Models\PushSubscription;
use App\Models\User;
use App\Services\FcmService;
use App\Services\NotificationService;
use Illuminate\Console\Command;

class SendFcmTestNotificationCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'fcm:test {--user= : User ID to test with} {--token= : Raw FCM token to test with}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Send test FCM push notification and verify server configuration';

    /**
     * Execute the console command.
     */
    public function handle(FcmService $fcmService, NotificationService $notificationService): int
    {
        $this->info("=== FCM Browser Push Diagnostics ===");
        $this->line("Project ID: " . config('firebase.project_id'));
        $this->line("Credentials Path: " . config('firebase.credentials_path'));
        $this->line("Configured: " . ($fcmService->isConfigured() ? "YES [Ready]" : "NO [Awaiting service account JSON in storage/credentials/]"));
        $this->line("VAPID Key: " . (config('firebase.vapid_key') ? "SET (" . substr(config('firebase.vapid_key'), 0, 15) . "...)" : "MISSING"));

        $userId = $this->option('user');
        $rawToken = $this->option('token');

        if ($rawToken) {
            $this->info("Sending direct push to provided token...");
            $res = $fcmService->sendToToken(
                $rawToken,
                "Test Push Notification",
                "Assalamu Alaikum! This is a test push notification from Al-Nikah Marriage Bureau.",
                "dashboard"
            );
            $this->line("Result: " . json_encode($res, JSON_PRETTY_PRINT));
            return Command::SUCCESS;
        }

        if ($userId) {
            $user = User::find($userId);
            if (!$user) {
                $this->error("User #{$userId} not found.");
                return Command::FAILURE;
            }

            $subscriptions = PushSubscription::where('user_id', $user->user_id)->get();
            $this->line("Found " . $subscriptions->count() . " push subscriptions for member #{$user->user_id} ({$user->name}).");

            $notif = $notificationService->createAndDispatch(
                $user->user_id,
                'test',
                'Al-Nikah Test Notification',
                'Assalamu Alaikum! Browser push notifications are active for your account.',
                'dashboard',
                'test_' . $user->user_id . '_' . time()
            );

            $this->info("Notification created with ID: " . ($notif ? $notif->id : 'failed'));
            return Command::SUCCESS;
        }

        $this->comment("Pass --user=ID or --token=TOKEN to test push dispatch.");
        return Command::SUCCESS;
    }
}
