<?php

namespace Tests\Feature;

use App\Http\Controllers\API\PaymentController;
use App\Http\Controllers\API\JournalEntryController;
use App\Models\{AccountingPeriod, Booking, ChartOfAccount, FiscalYear, JournalEntry, Payment, User};
use App\Services\{AccountingPeriodService, PaymentAccountingService};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Support\AccountingTestCase;

class PaymentAccountingTest extends AccountingTestCase
{
    private function controller()
    {
        return new class extends PaymentController {
            protected function canAccessAllBranches() { return true; }
        };
    }

    private function request(array $data)
    {
        $request = Request::create('/payments', 'POST', $data);
        $request->setUserResolver(function () { return User::find(1); });
        return $request;
    }

    private function createPayment(array $overrides = [])
    {
        return $this->controller()->store($this->request($overrides + [
            'booking_id' => 1, 'customer_id' => 1, 'amount' => '125.25',
            'payment_date' => '2026-09-25', 'payment_method' => 'cash',
            'cash_bank_account_id' => ChartOfAccount::where('code', '1101')->value('id'),
        ]));
    }

    public function test_payment_and_journal_post_together_with_dimensions_and_no_duplicate_journal()
    {
        $this->assertSame(201, $this->createPayment()->getStatusCode());
        $payment = Payment::first();
        $entry = $payment->journalEntry;
        $this->assertSame('posted', $entry->status);
        $this->assertSame('125.25', $entry->lines[0]->debit);
        $this->assertSame('125.25', $entry->lines[1]->credit);
        $this->assertSame('2300', $entry->lines[1]->account->code);
        foreach ($entry->lines as $line) {
            $this->assertEquals(1, $line->project_id);
            $this->assertEquals(1, $line->customer_id);
        }
        app(PaymentAccountingService::class)->post($payment, 1);
        $this->assertSame(1, JournalEntry::count());
        $this->assertSame('125.25', Booking::find(1)->paid_amount);
    }

    public function test_closed_period_rolls_back_receipt_and_booking_changes()
    {
        AccountingPeriod::query()->update(['status' => 'closed']);
        try { $this->createPayment(); $this->fail('A closed period accepted a payment.'); }
        catch (ValidationException $e) { $this->assertArrayHasKey('entry_date', $e->errors()); }
        $this->assertSame(0, Payment::count());
        $this->assertSame(0, JournalEntry::count());
        $this->assertSame('0.00', Booking::find(1)->paid_amount);
        $this->assertSame(0, DB::table('financial_audits')->count());
    }

    public function test_reversal_is_atomic_and_retains_original_entries()
    {
        $this->createPayment();
        $payment = Payment::first();
        AccountingPeriod::query()->update(['status' => 'closed']);
        $request = $this->request(['reason' => 'Receipt cancelled', 'reversal_date' => '2026-09-26']);
        try { $this->controller()->reverse($request, $payment); $this->fail('Closed-period reversal succeeded.'); }
        catch (ValidationException $e) { $this->assertArrayHasKey('entry_date', $e->errors()); }
        $this->assertSame('verified', $payment->fresh()->status);
        $this->assertSame('125.25', Booking::find(1)->paid_amount);
        AccountingPeriod::query()->update(['status' => 'open']);
        $this->controller()->reverse($request, $payment);
        $this->assertSame('reversed', $payment->fresh()->status);
        $this->assertSame('0.00', Booking::find(1)->paid_amount);
        $this->assertSame(2, JournalEntry::count());
        $reversal = $payment->fresh()->reversalJournal;
        $this->assertSame('125.25', $reversal->lines[0]->credit);
        $this->assertSame('125.25', $reversal->lines[1]->debit);
        $this->assertEquals($payment->journalEntry->id, $reversal->reverses_entry_id);
        app(PaymentAccountingService::class)->reverse($payment, 1, '2026-09-26', 'Repeat');
        $this->assertSame(2, JournalEntry::count());
    }

    public function test_invalid_receiving_account_and_subcent_amount_are_rejected()
    {
        foreach ([['cash_bank_account_id' => ChartOfAccount::where('code', '2300')->value('id')], ['amount' => '10.001']] as $data) {
            try { $this->createPayment($data); $this->fail('Invalid payment accepted.'); }
            catch (ValidationException $e) { $this->assertSame(0, Payment::count()); }
        }
    }

    public function test_payment_journal_cannot_be_reversed_through_manual_journals()
    {
        $this->createPayment();
        $this->expectException(ValidationException::class);
        (new JournalEntryController)->reverse(Payment::first()->journalEntry,
            $this->request(['entry_date' => '2026-09-26', 'reason' => 'Bypass']), new AccountingPeriodService);
    }

    public function test_legacy_reversal_does_not_create_an_unmatched_journal()
    {
        $legacy = Payment::create(['receipt_number' => 'OLD-1', 'booking_id' => 1, 'customer_id' => 1, 'amount' => '20.00', 'payment_date' => '2026-09-01', 'payment_method' => 'cash', 'status' => 'verified', 'received_by' => 1]);
        Booking::find(1)->update(['paid_amount' => 20, 'remaining_amount' => 980]);
        $this->controller()->reverse($this->request(['reason' => 'Legacy correction']), $legacy);
        $this->assertSame(0, JournalEntry::count());
        $this->assertSame('reversed', $legacy->fresh()->status);
    }
}
