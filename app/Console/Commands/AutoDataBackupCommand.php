<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\BackupService;

class AutoDataBackupCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'backup:run {--type=auto : auto or manual} {--full : Include user uploaded media/photos} {--sql : Do not gzip compress}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Automated MySQL Database and Media Backup for Al-Nikah Matrimonial';

    /**
     * Execute the console command.
     */
    public function handle(BackupService $backupService)
    {
        $type = $this->option('type') ?: 'auto';
        $full = $this->option('full');
        $gzip = !$this->option('sql');

        $this->info("Starting {$type} backup process for Al-Nikah platform...");

        try {
            if ($full) {
                $this->info("Creating full data backup (Database + User Media Photos)...");
                $result = $backupService->createFullMediaBackup($type);
            } else {
                $this->info("Creating database backup (gzip: " . ($gzip ? 'yes' : 'no') . ")...");
                $result = $backupService->createDatabaseBackup($type, $gzip);
            }

            if ($result['success']) {
                $this->info("Backup successfully generated!");
                $this->line("File: <comment>{$result['filename']}</comment>");
                $this->line("Size: <comment>{$result['size_formatted']}</comment>");
                $this->line("Path: <comment>{$result['path']}</comment>");
                return Command::SUCCESS;
            } else {
                $this->error("Backup failed: " . ($result['message'] ?? 'Unknown error'));
                return Command::FAILURE;
            }
        } catch (\Throwable $e) {
            $this->error("Backup Exception: " . $e->getMessage());
            return Command::FAILURE;
        }
    }
}
