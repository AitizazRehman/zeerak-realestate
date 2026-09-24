<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use ZipArchive;

class BackupApplication extends Command
{
    protected $signature = 'zeerak:backup
                            {--database-only : Back up only the configured MySQL database}
                            {--files-only : Back up only storage/app}
                            {--no-prune : Do not delete expired backup files}';

    protected $description = 'Create a production-safe ZeeraK database and storage backup';

    public function handle()
    {
        if ($this->option('database-only') && $this->option('files-only')) {
            $this->error('Use either --database-only or --files-only, not both.');
            return 1;
        }

        $includeDatabase = (bool) config('backup.include_database', true);
        $includeFiles = (bool) config('backup.include_files', true);

        if ($this->option('database-only')) {
            $includeDatabase = true;
            $includeFiles = false;
        } elseif ($this->option('files-only')) {
            $includeDatabase = false;
            $includeFiles = true;
        }

        if (!$includeDatabase && !$includeFiles) {
            $this->error('Both database and file backups are disabled.');
            return 1;
        }

        $backupPath = $this->backupPath();

        try {
            if (!is_dir($backupPath) && !File::makeDirectory($backupPath, 0750, true, true)) {
                throw new \RuntimeException('Unable to create backup directory.');
            }
        } catch (\Throwable $e) {
            $this->error('Unable to create backup directory: '.$e->getMessage());
            return 1;
        }

        if ($includeFiles && $this->backupPathIsInsideStorageApp($backupPath)) {
            $this->error('BACKUP_PATH must not be inside storage/app because that would recursively back up backups.');
            return 1;
        }

        $stamp = now()->format('Ymd-His').'-'.getmypid();
        $prefix = rtrim($backupPath, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'zeerak-'.$stamp;
        $created = [];
        $artifacts = [];

        $this->info('Creating ZeeraK backup...');
        $this->line('Destination: '.$backupPath);

        try {
            if ($includeDatabase) {
                $sqlPath = $prefix.'-database.sql';
                $this->dumpDatabase($sqlPath);
                $created[] = $sqlPath;
                $artifacts[] = $this->artifact('database', $sqlPath);
                $this->info('Database backup created: '.basename($sqlPath));
            }

            if ($includeFiles) {
                $zipPath = $prefix.'-storage.zip';
                $fileCount = $this->archiveStorage($zipPath);
                $created[] = $zipPath;
                $artifact = $this->artifact('storage', $zipPath);
                $artifact['files'] = $fileCount;
                $artifacts[] = $artifact;
                $this->info('Storage backup created: '.basename($zipPath).' ('.$fileCount.' files)');
            }

            $manifestPath = $prefix.'-manifest.json';
            $manifest = [
                'application' => 'ZeeraK Real Estate & Builders',
                'created_at' => now()->toIso8601String(),
                'timezone' => config('app.timezone'),
                'environment' => config('app.env'),
                'laravel_version' => app()->version(),
                'php_version' => PHP_VERSION,
                'host' => function_exists('gethostname') ? gethostname() : null,
                'database' => [
                    'connection' => config('database.default'),
                    'driver' => config('database.connections.'.config('database.default').'.driver'),
                    'database' => config('database.connections.'.config('database.default').'.database'),
                ],
                'artifacts' => $artifacts,
            ];

            if (file_put_contents(
                $manifestPath,
                json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
            ) === false) {
                throw new \RuntimeException('Unable to write backup manifest.');
            }

            @chmod($manifestPath, 0640);
            $created[] = $manifestPath;

            if (!$this->option('no-prune')) {
                $removed = $this->pruneExpiredBackups($backupPath);
                if ($removed > 0) {
                    $this->line('Expired backup files removed: '.$removed);
                }
            }

            $this->newLine();
            $this->info('Backup completed successfully.');
            $this->line('Manifest: '.$manifestPath);
            $this->line('Verify with: php artisan zeerak:verify-backup '.$this->commandArgument($manifestPath));

            return 0;
        } catch (\Throwable $e) {
            foreach ($created as $path) {
                if (is_file($path)) {
                    @unlink($path);
                }
            }

            $this->error('Backup failed: '.$e->getMessage());
            return 1;
        }
    }

    private function backupPath()
    {
        $path = (string) config('backup.path', storage_path('backups'));

        if ($path === '') {
            return storage_path('backups');
        }

        return rtrim($path, '/\\');
    }

    private function backupPathIsInsideStorageApp($backupPath)
    {
        $source = realpath(storage_path('app'));
        $backup = realpath($backupPath);

        if (!$source || !$backup) {
            return false;
        }

        $source = rtrim(str_replace('\\', '/', $source), '/').'/';
        $backup = rtrim(str_replace('\\', '/', $backup), '/').'/';

        return strpos($backup, $source) === 0;
    }

    private function dumpDatabase($sqlPath)
    {
        $connectionName = config('database.default');
        $database = config('database.connections.'.$connectionName);

        if (!$database || ($database['driver'] ?? null) !== 'mysql') {
            throw new \RuntimeException('zeerak:backup currently supports the MySQL database driver only.');
        }

        $databaseName = (string) ($database['database'] ?? '');
        if ($databaseName === '') {
            throw new \RuntimeException('DB_DATABASE is not configured.');
        }

        $credentials = tempnam(sys_get_temp_dir(), 'zeerak-mysql-');
        if ($credentials === false) {
            throw new \RuntimeException('Unable to create temporary MySQL credentials file.');
        }

        try {
            $options = "[client]\n";
            $options .= 'user='.$this->mysqlOptionValue($database['username'] ?? '')."\n";
            $options .= 'password='.$this->mysqlOptionValue($database['password'] ?? '')."\n";

            if (!empty($database['host'])) {
                $options .= 'host='.$this->mysqlOptionValue($database['host'])."\n";
            }

            if (!empty($database['port'])) {
                $options .= 'port='.(int) $database['port']."\n";
            }

            if (!empty($database['unix_socket'])) {
                $options .= 'socket='.$this->mysqlOptionValue($database['unix_socket'])."\n";
            }

            if (file_put_contents($credentials, $options) === false) {
                throw new \RuntimeException('Unable to write temporary MySQL credentials file.');
            }

            @chmod($credentials, 0600);

            $binary = (string) config('backup.mysqldump_path', 'mysqldump');
            if ($binary === '') {
                $binary = 'mysqldump';
            }

            if ($this->looksLikePath($binary) && !is_file($binary)) {
                throw new \RuntimeException('mysqldump was not found at MYSQLDUMP_PATH: '.$binary);
            }

            $arguments = [
                $this->commandArgument($binary),
                $this->commandArgument('--defaults-extra-file='.$credentials),
                '--single-transaction',
                '--quick',
                '--routines',
                '--triggers',
                '--events',
                '--default-character-set=utf8mb4',
                $this->commandArgument($databaseName),
            ];

            $command = implode(' ', $arguments);
            $descriptors = [
                0 => ['pipe', 'r'],
                1 => ['file', $sqlPath, 'w'],
                2 => ['pipe', 'w'],
            ];

            // Windows cmd.exe does not understand the single-quoted arguments
            // produced by escapeshellarg() in the same way as Unix shells.
            // bypass_shell sends our double-quoted command line directly to
            // CreateProcess and keeps paths such as C:\xampp\... intact.
            $processOptions = DIRECTORY_SEPARATOR === '\\'
                ? ['bypass_shell' => true]
                : [];

            $process = proc_open($command, $descriptors, $pipes, null, null, $processOptions);

            if (!is_resource($process)) {
                throw new \RuntimeException('Unable to start mysqldump. Check MYSQLDUMP_PATH.');
            }

            fclose($pipes[0]);
            $errorOutput = stream_get_contents($pipes[2]);
            fclose($pipes[2]);
            $exitCode = proc_close($process);

            if ($exitCode !== 0) {
                @unlink($sqlPath);
                $message = trim((string) $errorOutput);
                throw new \RuntimeException(
                    'mysqldump exited with code '.$exitCode.($message ? ': '.$message : '.')
                );
            }

            if (!is_file($sqlPath) || filesize($sqlPath) <= 0) {
                @unlink($sqlPath);
                throw new \RuntimeException('mysqldump completed but produced an empty SQL file.');
            }

            @chmod($sqlPath, 0640);
        } finally {
            @unlink($credentials);
        }
    }

    private function commandArgument($value)
    {
        $value = (string) $value;

        if (DIRECTORY_SEPARATOR !== '\\') {
            return escapeshellarg($value);
        }

        // Quote for the Windows CreateProcess/C runtime command-line parser.
        // Double backslashes that precede a quote and at the end of the
        // argument so paths and values survive parsing unchanged.
        $value = preg_replace('/(\\\\*)"/', '$1$1\\\\"', $value);
        $value = preg_replace('/(\\\\+)$/', '$1$1', $value);

        return '"'.$value.'"';
    }

    private function looksLikePath($value)
    {
        $value = (string) $value;

        return strpos($value, '/') !== false || strpos($value, '\\') !== false;
    }

    private function mysqlOptionValue($value)
    {
        $value = (string) $value;
        $value = str_replace('\\', '\\\\', $value);
        $value = str_replace('"', '\\"', $value);

        return '"'.$value.'"';
    }

    private function archiveStorage($zipPath)
    {
        if (!class_exists(ZipArchive::class)) {
            throw new \RuntimeException('PHP ZipArchive is required for storage backups. Enable the zip extension.');
        }

        $source = storage_path('app');
        if (!is_dir($source)) {
            throw new \RuntimeException('storage/app does not exist.');
        }

        $zip = new ZipArchive();
        $opened = $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        if ($opened !== true) {
            throw new \RuntimeException('Unable to create storage ZIP archive. ZipArchive error code: '.$opened);
        }

        $sourceReal = realpath($source);
        $count = 0;

        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($sourceReal, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::SELF_FIRST
            );

            foreach ($iterator as $item) {
                if ($item->isLink()) {
                    continue;
                }

                $absolute = $item->getPathname();
                $relative = ltrim(substr($absolute, strlen($sourceReal)), DIRECTORY_SEPARATOR);
                $relative = str_replace('\\', '/', $relative);

                if ($relative === '') {
                    continue;
                }

                $zipName = 'storage-app/'.$relative;

                if ($item->isDir()) {
                    $zip->addEmptyDir($zipName);
                    continue;
                }

                if (!$zip->addFile($absolute, $zipName)) {
                    throw new \RuntimeException('Unable to add file to storage archive: '.$relative);
                }

                $count++;
            }
        } catch (\Throwable $e) {
            $zip->close();
            @unlink($zipPath);
            throw $e;
        }

        if (!$zip->close()) {
            @unlink($zipPath);
            throw new \RuntimeException('Unable to finalize storage ZIP archive.');
        }

        if (!is_file($zipPath) || filesize($zipPath) <= 0) {
            @unlink($zipPath);
            throw new \RuntimeException('Storage ZIP archive is empty.');
        }

        @chmod($zipPath, 0640);

        return $count;
    }

    private function artifact($type, $path)
    {
        $hash = hash_file('sha256', $path);

        if ($hash === false) {
            throw new \RuntimeException('Unable to calculate checksum for '.basename($path).'.');
        }

        return [
            'type' => $type,
            'file' => basename($path),
            'size' => filesize($path),
            'sha256' => $hash,
        ];
    }

    private function pruneExpiredBackups($backupPath)
    {
        $days = max(1, (int) config('backup.retention_days', 14));
        $cutoff = time() - ($days * 86400);
        $removed = 0;

        foreach (glob(rtrim($backupPath, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'zeerak-*') ?: [] as $path) {
            if (!is_file($path)) {
                continue;
            }

            $mtime = filemtime($path);
            if ($mtime !== false && $mtime < $cutoff && @unlink($path)) {
                $removed++;
            }
        }

        return $removed;
    }
}
