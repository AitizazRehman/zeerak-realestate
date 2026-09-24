<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    protected $commands = [
        \App\Console\Commands\UpdateOverdueInstallments::class,
    ];

    protected function schedule(Schedule $schedule)
    {
        $schedule->command('zeerak:update-overdue-installments')
            ->dailyAt('00:10')
            ->withoutOverlapping(30);

        if (config('backup.enabled')) {
            $schedule->command('zeerak:backup')
                ->dailyAt(config('backup.schedule_time', '02:00'))
                ->withoutOverlapping(180);
        }
    }

    protected function commands()
    {
        $this->load(__DIR__.'/Commands');
        require base_path('routes/console.php');
    }
}
