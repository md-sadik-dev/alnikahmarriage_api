<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\NotificationService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class SendPackageExpiryRemindersCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'package:send-expiry-reminders {--dry-run : Check and output eligible members without dispatching}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Send automated FCM push notifications for package expiry reminders (7, 3, 1, and 0 days)';

    /**
     * Execute the console command.
     */
    public function handle(NotificationService $notificationService): int
    {
        $isDryRun = (bool) $this->option('dry-run');
        $this->info("Scanning active membership packages for expiration reminders..." . ($isDryRun ? " [DRY RUN]" : ""));

        $today = Carbon::today();
        $targetIntervals = [7, 3, 1, 0];

        // Fetch users who have an end_date set and are not deleted
        $users = User::query()
            ->whereNotNull('end_date')
            ->where('end_date', '!=', '')
            ->where('end_date', '!=', '0000-00-00')
            ->where(function ($q) {
                $q->whereNull('is_deleted')->orWhere('is_deleted', 0);
            })
            ->get();

        $eligibleCount = 0;
        $sentCount = 0;

        foreach ($users as $user) {
            $endStr = strtoupper(trim($user->end_date ?? ''));
            // Skip lifetime/unlimited packages
            if (str_contains($endStr, 'UNLIMITED') || str_contains($endStr, 'NIKAH')) {
                continue;
            }

            try {
                $endDate = Carbon::parse($user->end_date)->startOfDay();
            } catch (\Throwable $e) {
                continue; // Malformed date
            }

            // Calculate integer days difference: positive means future, 0 is today, negative is past
            $daysRemaining = (int) $today->diffInDays($endDate, false);

            if (in_array($daysRemaining, $targetIntervals, true)) {
                $eligibleCount++;
                $this->line("Candidate: User #{$user->user_id} ({$user->name}) | Package: {$user->package} | Expires: {$user->end_date} ({$daysRemaining} days remaining)");

                if (!$isDryRun) {
                    $notif = $notificationService->notifyPackageExpiry($user, $daysRemaining);
                    if ($notif && $notif->wasRecentlyCreated) {
                        $sentCount++;
                        $this->info(" -> Reminder created & dispatched (ID: {$notif->id})");
                    } else {
                        $this->comment(" -> Skipped (already dispatched or deduplicated)");
                    }
                }
            }
        }

        $this->info("Scan complete. Eligible members found: {$eligibleCount}. Reminders dispatched: {$sentCount}.");
        return Command::SUCCESS;
    }
}
