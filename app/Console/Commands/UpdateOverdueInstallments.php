<?php

namespace App\Console\Commands;

use App\Models\Installment;
use Illuminate\Console\Command;

class UpdateOverdueInstallments extends Command
{
    protected $signature = 'zeerak:update-overdue-installments';
    protected $description = 'Mark unpaid installments past their due date as overdue';

    public function handle(): int
    {
        $count = Installment::whereIn('status', ['pending', 'partial'])
            ->whereDate('due_date', '<', now()->toDateString())
            ->where('remaining_amount', '>', 0)
            ->whereHas('booking', function ($booking) {
                $booking->whereNotIn('status', ['cancelled', 'completed']);
            })
            ->where(function ($query) {
                $query->whereDoesntHave('plan')
                    ->orWhereHas('plan', function ($plan) {
                        $plan->where('status', 'active');
                    });
            })
            ->update([
                'status' => 'overdue',
                'updated_at' => now()
            ]);

        $this->info("{$count} active installment(s) marked overdue.");

        return self::SUCCESS;
    }
}
