<?php

namespace App\Console\Commands;

use App\Models\Booking;
use App\Models\Installment;
use App\Models\Payment;
use Illuminate\Console\Command;

class ReconcileFinancialBalances extends Command
{
    protected $signature = 'zeerak:reconcile-finances {--fix : Correct stored booking and installment balances from verified payments}';
    protected $description = 'Audit booking and installment balances against verified payments and optionally repair mismatches.';

    public function handle()
    {
        $fix = (bool) $this->option('fix');
        $issues = 0;
        $fixed = 0;

        Booking::withTrashed()->orderBy('id')->chunkById(100, function ($bookings) use ($fix, &$issues, &$fixed) {
            foreach ($bookings as $booking) {
                $verified = round((float) Payment::withTrashed()
                    ->where('booking_id', $booking->id)
                    ->whereNull('deleted_at')
                    ->where('status', 'verified')
                    ->sum('amount'), 2);
                $expectedRemaining = max(0, round((float) $booking->final_price - $verified, 2));

                if (round((float) $booking->paid_amount, 2) !== $verified ||
                    round((float) $booking->remaining_amount, 2) !== $expectedRemaining) {
                    $issues++;
                    $this->warn('Booking '.$booking->booking_number.' (#'.$booking->id.') stored paid/remaining '
                        .$booking->paid_amount.'/'.$booking->remaining_amount.'; expected '
                        .number_format($verified, 2, '.', '').'/'.number_format($expectedRemaining, 2, '.', ''));

                    if ($fix) {
                        $booking->forceFill(['paid_amount'=>$verified,'remaining_amount'=>$expectedRemaining])->save();
                        $fixed++;
                    }
                }
            }
        });

        Installment::orderBy('id')->chunkById(200, function ($installments) use ($fix, &$issues, &$fixed) {
            foreach ($installments as $installment) {
                $verified = round((float) Payment::withTrashed()
                    ->where('installment_id', $installment->id)
                    ->whereNull('deleted_at')
                    ->where('status', 'verified')
                    ->sum('amount'), 2);
                $expectedRemaining = max(0, round((float) $installment->amount - $verified, 2));
                $expectedStatus = $verified <= 0 ? 'pending' : ($expectedRemaining <= 0 ? 'paid' : 'partial');

                if (round((float) $installment->paid_amount, 2) !== $verified ||
                    round((float) $installment->remaining_amount, 2) !== $expectedRemaining ||
                    $installment->status !== $expectedStatus) {
                    $issues++;
                    $this->warn('Installment #'.$installment->id.' stored paid/remaining/status '
                        .$installment->paid_amount.'/'.$installment->remaining_amount.'/'.$installment->status
                        .'; expected '.number_format($verified, 2, '.', '').'/'
                        .number_format($expectedRemaining, 2, '.', '').'/'.$expectedStatus);

                    if ($fix) {
                        $installment->forceFill([
                            'paid_amount'=>$verified,
                            'remaining_amount'=>$expectedRemaining,
                            'status'=>$expectedStatus,
                            'paid_date'=>$expectedStatus === 'paid' ? ($installment->paid_date ?: now()->toDateString()) : null,
                        ])->save();
                        $fixed++;
                    }
                }
            }
        });

        $this->newLine();
        if (!$issues) {
            $this->info('Financial reconciliation complete: no inconsistencies found.');
        } else {
            $this->line('Inconsistencies found: '.$issues);
            $this->line($fix ? 'Records repaired: '.$fixed : 'No records changed. Run with --fix only after reviewing this report.');
        }

        return 0;
    }
}
