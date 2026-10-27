<?php

namespace App\Services;

class BackupService
{
    protected $manager;

    public function __construct()
    {
        require_once base_path('../alnikah@1357admin/functions/backup.php');
        $this->manager = new \BackupManager();
    }

    public function createDatabaseBackup($trigger = 'manual', $gzip = true)
    {
        return $this->manager->createDatabaseBackup($trigger, $gzip);
    }

    public function createFullMediaBackup($trigger = 'manual')
    {
        return $this->manager->createFullMediaBackup($trigger);
    }

    public function checkAndRunAutoBackup()
    {
        return $this->manager->checkAndRunAutoBackup();
    }

    public function getConfig()
    {
        return $this->manager->getConfig();
    }

    public function saveConfig(array $data)
    {
        return $this->manager->saveConfig($data);
    }

    public function getBackupsList()
    {
        return $this->manager->getBackupsList();
    }

    public function deleteBackup(string $filename)
    {
        return $this->manager->deleteBackup($filename);
    }

    public function restoreDatabase(string $filename)
    {
        return $this->manager->restoreDatabase($filename);
    }
}
