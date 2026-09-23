<?php

namespace App\Console\Commands;

use App\Models\Booking;
use App\Models\Installment;
use App\Models\InstallmentPlan;
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

                if ($verified > round((float) $booking->final_price, 2) + 0.01) {
                    $issues++;
                    $this->error('Booking '.$booking->booking_number.' (#'.$booking->id.') is OVERPAID: verified '
                        .number_format($verified, 2, '.', '').' exceeds final price '
                        .number_format((float) $booking->final_price, 2, '.', '').'. Manual review required.');
                }

                if (abs(round((float) $booking->paid_amount, 2) - $verified) > 0.01 ||
                    abs(round((float) $booking->remaining_amount, 2) - $expectedRemaining) > 0.01) {
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

        Installment::with('plan')->orderBy('id')->chunkById(200, function ($installments) use ($fix, &$issues, &$fixed) {
            foreach ($installments as $installment) {
                if ($installment->plan && (int) $installment->plan->booking_id !== (int) $installment->booking_id) {
                    $issues++;
                    $this->error('Installment #'.$installment->id.' booking #'.$installment->booking_id
                        .' does not match installment plan booking #'.$installment->plan->booking_id.'. Manual review required.');
                }

                $verified = round((float) Payment::withTrashed()
                    ->where('installment_id', $installment->id)
                    ->whereNull('deleted_at')
                    ->where('status', 'verified')
                    ->sum('amount'), 2);
                $expectedRemaining = max(0, round((float) $installment->amount - $verified, 2));
                $expectedStatus = $verified <= 0 ? 'pending' : ($expectedRemaining <= 0 ? 'paid' : 'partial');
                $verifiedPaidDate = Payment::where('installment_id', $installment->id)
                    ->where('status', 'verified')
                    ->orderByDesc('payment_date')
                    ->value('payment_date');

                if ($verified > round((float) $installment->amount, 2) + 0.01) {
                    $issues++;
                    $this->error('Installment #'.$installment->id.' is OVERPAID: verified '
                        .number_format($verified, 2, '.', '').' exceeds installment amount '
                        .number_format((float) $installment->amount, 2, '.', '').'. Manual review required.');
                }

                if (abs(round((float) $installment->paid_amount, 2) - $verified) > 0.01 ||
                    abs(round((float) $installment->remaining_amount, 2) - $expectedRemaining) > 0.01 ||
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
                            'paid_date'=>$expectedStatus === 'paid' ? ($verifiedPaidDate ?: $installment->paid_date) : null,
                        ])->save();
                        $fixed++;
                    }
                }
            }
        });

        InstallmentPlan::withTrashed()->with('booking')->orderBy('id')->chunkById(100, function ($plans) use ($fix, &$issues, &$fixed) {
            foreach ($plans as $plan) {
                $count = $plan->installments()->count();
                $scheduledTotal = round((float) $plan->installments()->sum('amount'), 2);
                $outstanding = round((float) $plan->installments()->sum('remaining_amount'), 2);
                $verifiedPayments = Payment::whereIn('installment_id', $plan->installments()->pluck('id'))
                    ->where('status', 'verified')
                    ->exists();

                if ($count !== (int) $plan->number_of_installments) {
                    $issues++;
                    $this->error('Installment plan #'.$plan->id.' schedule count '.$count
                        .' does not match configured count '.$plan->number_of_installments.'. Manual review required.');
                }

                if (abs($scheduledTotal - round((float) $plan->total_amount, 2)) > 0.01) {
                    $issues++;
                    $this->error('Installment plan #'.$plan->id.' schedule total '
                        .number_format($scheduledTotal, 2, '.', '').' does not match plan total '
                        .number_format((float) $plan->total_amount, 2, '.', '').'. Manual review required.');
                }

                if ($plan->status === 'completed' && $outstanding > 0.01) {
                    $issues++;
                    $this->error('Completed installment plan #'.$plan->id.' still has outstanding balance '
                        .number_format($outstanding, 2, '.', '').'. Manual review required.');
                }

                if ($plan->status === 'cancelled' && $verifiedPayments) {
                    $issues++;
                    $this->error('Cancelled installment plan #'.$plan->id.' has verified payment history. Manual review required.');
                }

                if (!$plan->trashed() && $plan->status === 'active' && $outstanding <= 0.01 && $count > 0) {
                    $issues++;
                    $this->warn('Installment plan #'.$plan->id.' is active but fully paid.');

                    if ($fix) {
                        $plan->forceFill(['status' => 'completed'])->save();
                        $fixed++;
                    }
                }

                if ($plan->status === 'active' && $plan->booking && $outstanding > round((float) $plan->booking->remaining_amount, 2) + 0.01) {
                    $issues++;
                    $this->error('Installment plan #'.$plan->id.' outstanding '
                        .number_format($outstanding, 2, '.', '').' exceeds booking remaining balance '
                        .number_format((float) $plan->booking->remaining_amount, 2, '.', '').'. Manual review required.');
                }
            }
        });

        Payment::with(['booking','installment'])->where('status', 'verified')->orderBy('id')->chunkById(200, function ($payments) use (&$issues) {
            foreach ($payments as $payment) {
                if ($payment->booking && (int) $payment->customer_id !== (int) $payment->booking->customer_id) {
                    $issues++;
                    $this->error('Payment '.$payment->receipt_number.' (#'.$payment->id.') customer #'.$payment->customer_id
                        .' does not match booking customer #'.$payment->booking->customer_id.'. Manual review required.');
                }

                if ($payment->installment && (int) $payment->installment->booking_id !== (int) $payment->booking_id) {
                    $issues++;
                    $this->error('Payment '.$payment->receipt_number.' (#'.$payment->id.') installment belongs to booking #'
                        .$payment->installment->booking_id.' but payment references booking #'.$payment->booking_id
                        .'. Manual review required.');
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
