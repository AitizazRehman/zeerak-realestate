<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use ZipArchive;

class VerifyBackup extends Command
{
    protected $signature = 'zeerak:verify-backup {manifest? : Path to a backup manifest; defaults to the newest one}';
    protected $description = 'Verify ZeeraK backup artifacts against their manifest and checksums';

    public function handle()
    {
        $manifestPath = $this->argument('manifest');

        if (!$manifestPath) {
            $manifestPath = $this->latestManifest();
        }

        if (!$manifestPath || !is_file($manifestPath)) {
            $this->error('Backup manifest not found.');
            return 1;
        }

        $json = file_get_contents($manifestPath);
        $manifest = json_decode((string) $json, true);

        if (!is_array($manifest) || empty($manifest['artifacts']) || !is_array($manifest['artifacts'])) {
            $this->error('Backup manifest is invalid or contains no artifacts.');
            return 1;
        }

        $directory = dirname($manifestPath);
        $failures = 0;

        $this->info('Verifying ZeeraK backup');
        $this->line('Manifest: '.$manifestPath);
        $this->line('Created: '.($manifest['created_at'] ?? 'unknown'));
        $this->newLine();

        foreach ($manifest['artifacts'] as $artifact) {
            $file = $artifact['file'] ?? '';
            $path = $directory.DIRECTORY_SEPARATOR.$file;
            $type = $artifact['type'] ?? 'artifact';

            if (!$file || !is_file($path)) {
                $this->error(strtoupper($type).' missing: '.$file);
                $failures++;
                continue;
            }

            $size = filesize($path);
            if ($size === false || $size <= 0) {
                $this->error(strtoupper($type).' is empty: '.$file);
                $failures++;
                continue;
            }

            $expectedSize = isset($artifact['size']) ? (int) $artifact['size'] : null;
            if ($expectedSize !== null && $expectedSize !== (int) $size) {
                $this->error(strtoupper($type).' size mismatch: '.$file);
                $failures++;
                continue;
            }

            $expectedHash = (string) ($artifact['sha256'] ?? '');
            $actualHash = hash_file('sha256', $path);

            if (!$expectedHash || $actualHash === false || !hash_equals($expectedHash, $actualHash)) {
                $this->error(strtoupper($type).' checksum mismatch: '.$file);
                $failures++;
                continue;
            }

            if ($type === 'storage' && !$this->verifyZip($path)) {
                $this->error('STORAGE ZIP consistency check failed: '.$file);
                $failures++;
                continue;
            }

            if ($type === 'database' && !$this->verifySql($path)) {
                $this->error('DATABASE SQL sanity check failed: '.$file);
                $failures++;
                continue;
            }

            $this->line('<info>PASS</info> '.strtoupper($type).' '.$file.' · '.$this->humanBytes($size));
        }

        $this->newLine();

        if ($failures > 0) {
            $this->error('Backup verification failed with '.$failures.' problem(s).');
            return 1;
        }

        $this->info('Backup verification completed successfully.');
        return 0;
    }

    private function latestManifest()
    {
        $path = rtrim((string) config('backup.path', storage_path('backups')), '/\\');
        $files = glob($path.DIRECTORY_SEPARATOR.'zeerak-*-manifest.json') ?: [];

        if (!$files) {
            return null;
        }

        usort($files, function ($a, $b) {
            return filemtime($b) <=> filemtime($a);
        });

        return $files[0];
    }

    private function verifyZip($path)
    {
        if (!class_exists(ZipArchive::class)) {
            $this->warn('ZipArchive is unavailable; checksum passed but ZIP contents could not be inspected.');
            return true;
        }

        $zip = new ZipArchive();
        $result = $zip->open($path, ZipArchive::CHECKCONS);

        if ($result !== true) {
            return false;
        }

        $zip->close();
        return true;
    }

    private function verifySql($path)
    {
        $handle = fopen($path, 'rb');

        if (!$handle) {
            return false;
        }

        $sample = fread($handle, 4096);
        fclose($handle);

        if ($sample === false || trim($sample) === '') {
            return false;
        }

        $sampleLower = strtolower($sample);

        return strpos($sampleLower, 'mysql') !== false
            || strpos($sampleLower, 'mariadb') !== false
            || strpos($sampleLower, 'sql') !== false
            || strpos($sampleLower, '--') !== false;
    }

    private function humanBytes($bytes)
    {
        $bytes = (float) $bytes;

        if ($bytes >= 1073741824) {
            return number_format($bytes / 1073741824, 2).' GB';
        }

        if ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 2).' MB';
        }

        if ($bytes >= 1024) {
            return number_format($bytes / 1024, 2).' KB';
        }

        return number_format($bytes, 0).' B';
    }
}
