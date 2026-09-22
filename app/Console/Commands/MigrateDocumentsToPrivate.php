<?php

namespace App\Console\Commands;

use App\Models\BookingDocument;
use App\Models\PropertyDocument;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class MigrateDocumentsToPrivate extends Command
{
    protected $signature = 'zeerak:migrate-documents-private {--dry-run : Report changes without moving files}';
    protected $description = 'Move booking and property documents from the public disk to private local storage.';

    public function handle()
    {
        $dryRun = (bool) $this->option('dry-run');
        $stats = ['private'=>0,'moved'=>0,'missing'=>0,'failed'=>0];

        $this->migrateModel(BookingDocument::class, 'Booking document', $dryRun, $stats);
        $this->migrateModel(PropertyDocument::class, 'Property document', $dryRun, $stats);

        $this->newLine();
        $this->table(['Already private','Moved','Missing','Failed'], [[
            $stats['private'],$stats['moved'],$stats['missing'],$stats['failed']
        ]]);

        return $stats['failed'] > 0 ? 1 : 0;
    }

    private function migrateModel($modelClass, $label, $dryRun, array &$stats)
    {
        $modelClass::query()->whereNotNull('file_path')->orderBy('id')->chunkById(100, function ($records) use ($label, $dryRun, &$stats) {
            foreach ($records as $record) {
                $path = ltrim($record->file_path, '/');

                if (Storage::disk('local')->exists($path)) {
                    $stats['private']++;
                    continue;
                }

                if (!Storage::disk('public')->exists($path)) {
                    $stats['missing']++;
                    $this->warn($label.' #'.$record->id.': missing '.$path);
                    continue;
                }

                if ($dryRun) {
                    $stats['moved']++;
                    $this->line('[DRY RUN] '.$label.' #'.$record->id.': '.$path);
                    continue;
                }

                try {
                    $source = Storage::disk('public')->readStream($path);
                    if ($source === false) throw new \RuntimeException('Unable to read source file.');

                    $written = Storage::disk('local')->put($path, $source);
                    if (is_resource($source)) fclose($source);
                    if (!$written || !Storage::disk('local')->exists($path)) {
                        throw new \RuntimeException('Unable to verify private copy.');
                    }

                    $publicSize = Storage::disk('public')->size($path);
                    $privateSize = Storage::disk('local')->size($path);
                    if ($publicSize !== $privateSize) {
                        Storage::disk('local')->delete($path);
                        throw new \RuntimeException('File size verification failed.');
                    }

                    if (!Storage::disk('public')->delete($path)) {
                        Storage::disk('local')->delete($path);
                        throw new \RuntimeException('Unable to remove public source after copy.');
                    }

                    $stats['moved']++;
                    $this->info($label.' #'.$record->id.': moved '.$path);
                } catch (\Throwable $e) {
                    $stats['failed']++;
                    $this->error($label.' #'.$record->id.': '.$e->getMessage());
                }
            }
        });
    }
}
