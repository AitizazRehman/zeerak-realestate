<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ProductionHealthCheck extends Command
{
    protected $signature = 'zeerak:production-check {--strict : Treat warnings as failures}';
    protected $description = 'Run production readiness checks for the ZeeraK application';

    private $failures = 0;
    private $warnings = 0;

    public function handle()
    {
        $this->info('ZeeraK production readiness check');
        $this->line(str_repeat('-', 42));

        $this->check(
            config('app.env') === 'production',
            'APP_ENV is production',
            'APP_ENV is currently '.config('app.env'),
            true
        );

        $this->check(
            config('app.debug') === false,
            'APP_DEBUG is disabled',
            'APP_DEBUG must be false in production',
            true
        );

        $this->check(
            !empty(config('app.key')),
            'APP_KEY is configured',
            'APP_KEY is missing',
            true
        );

        $url = (string) config('app.url');
        $this->check(
            stripos($url, 'https://') === 0,
            'APP_URL uses HTTPS',
            'APP_URL should use HTTPS: '.$url,
            false
        );

        $this->check(
            config('app.timezone') === 'Asia/Karachi',
            'Application timezone is Asia/Karachi',
            'Application timezone is '.config('app.timezone'),
            false
        );

        $this->check(
            version_compare(PHP_VERSION, '7.3.0', '>='),
            'PHP version is supported: '.PHP_VERSION,
            'PHP 7.3 or newer is required; current version is '.PHP_VERSION,
            true
        );

        foreach (['openssl','mbstring','fileinfo','pdo_mysql'] as $extension) {
            $this->check(
                extension_loaded($extension),
                'PHP extension loaded: '.$extension,
                'Missing PHP extension: '.$extension,
                true
            );
        }

        try {
            DB::connection()->getPdo();
            DB::select('select 1');
            $this->pass('Database connection is healthy');
        } catch (\Throwable $e) {
            $this->fail('Database connection failed: '.$e->getMessage());
        }

        try {
            if (!Schema::hasTable('migrations')) {
                $this->fail('Migrations table is missing');
            } else {
                $migrator = app('migrator');
                $files = $migrator->getMigrationFiles(database_path('migrations'));
                $ran = $migrator->getRepository()->getRan();
                $pending = array_diff(array_keys($files), $ran);

                if (count($pending)) {
                    $this->fail('Pending migrations: '.implode(', ', $pending));
                } else {
                    $this->pass('No pending migrations');
                }
            }
        } catch (\Throwable $e) {
            $this->warnCheck('Could not determine migration status: '.$e->getMessage());
        }

        $schemaChecks = [
            ['financial_documents', null, 'Financial document table exists'],
            ['customers', 'branch_id', 'Customer branch ownership is installed'],
            ['expenses', 'branch_id', 'Expense branch ownership is installed'],
        ];

        foreach ($schemaChecks as $check) {
            try {
                $ok = $check[1]
                    ? Schema::hasColumn($check[0], $check[1])
                    : Schema::hasTable($check[0]);

                $this->check($ok, $check[2], $check[2].' check failed', true);
            } catch (\Throwable $e) {
                $this->fail($check[2].' check failed: '.$e->getMessage());
            }
        }

        foreach ([
            storage_path(),
            storage_path('app'),
            storage_path('framework'),
            storage_path('logs'),
            base_path('bootstrap/cache'),
        ] as $path) {
            $this->check(
                is_dir($path) && is_writable($path),
                'Writable: '.$path,
                'Directory is missing or not writable: '.$path,
                true
            );
        }

        $publicStorage = public_path('storage');
        $this->check(
            is_link($publicStorage) || is_dir($publicStorage),
            'Public storage link is available',
            'Run php artisan storage:link so profile/company images are available',
            false
        );

        $httpsConfigured = stripos($url, 'https://') === 0;
        if ($httpsConfigured) {
            $this->check(
                (bool) config('session.secure'),
                'Secure session cookies are enabled',
                'SESSION_SECURE_COOKIE should be true for HTTPS',
                true
            );
        }

        $this->check(
            config('logging.default') === 'daily',
            'Daily log rotation is enabled',
            'LOG_CHANNEL is '.config('logging.default').'; daily is recommended in production',
            false
        );

        if (config('queue.default') === 'sync') {
            $this->warnCheck('QUEUE_CONNECTION=sync. This is acceptable until background jobs are introduced.');
        } else {
            $this->pass('Queue connection: '.config('queue.default'));
        }

        if (config('backup.enabled')) {
            $backupPath = (string) config('backup.path', storage_path('backups'));
            $backupParent = is_dir($backupPath) ? $backupPath : dirname($backupPath);

            $this->check(
                is_dir($backupParent) && is_writable($backupParent),
                'Backup destination is writable: '.$backupPath,
                'Backup destination is not writable or its parent does not exist: '.$backupPath,
                true
            );

            if (config('backup.include_files')) {
                $this->check(
                    class_exists(\ZipArchive::class),
                    'PHP ZipArchive is available for storage backups',
                    'PHP zip extension is required because BACKUP_FILES=true',
                    true
                );
            }

            $time = (string) config('backup.schedule_time', '02:00');
            $this->check(
                (bool) preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $time),
                'Backup schedule time is valid: '.$time,
                'BACKUP_SCHEDULE_TIME must use HH:MM 24-hour format; current value is '.$time,
                true
            );

            $this->check(
                (int) config('backup.retention_days', 14) >= 1,
                'Backup retention is configured: '.(int) config('backup.retention_days', 14).' day(s)',
                'BACKUP_RETENTION_DAYS must be at least 1',
                true
            );

            $this->pass('Automated application backups are enabled');
        } else {
            $this->warnCheck('BACKUP_ENABLED=false. Enable and verify automated backups before production launch.');
        }

        if (app()->isDownForMaintenance()) {
            $this->warnCheck('Application is currently in maintenance mode');
        } else {
            $this->pass('Application is not in maintenance mode');
        }

        $this->newLine();
        $this->line('Scheduler requirement: add one cron entry running "php artisan schedule:run" every minute.');
        $this->line('Backup verification: run "php artisan zeerak:backup" and "php artisan zeerak:verify-backup".');
        $this->newLine();

        $this->info('Failures: '.$this->failures.' | Warnings: '.$this->warnings);

        if ($this->failures > 0 || ($this->option('strict') && $this->warnings > 0)) {
            return 1;
        }

        $this->info('Production readiness checks completed.');
        return 0;
    }

    private function check($condition, $success, $problem, $critical)
    {
        if ($condition) {
            $this->pass($success);
            return;
        }

        if ($critical) {
            $this->fail($problem);
        } else {
            $this->warnCheck($problem);
        }
    }

    private function pass($message)
    {
        $this->line('<info>PASS</info> '.$message);
    }

    private function fail($message)
    {
        $this->failures++;
        $this->line('<error>FAIL</error> '.$message);
    }

    private function warnCheck($message)
    {
        $this->warnings++;
        $this->line('<comment>WARN</comment> '.$message);
    }
}
