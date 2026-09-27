<?php

namespace Tests\Feature;

use App\Http\Controllers\API\{BookingAccountingController, BookingController, BookingStatusController, PaymentController, JournalEntryController};
use App\Models\{AccountingPeriod, Booking, ChartOfAccount, JournalEntry, Payment, User};
use App\Services\{AccountingPeriodService, BookingAccountingService};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\AccountingTestCase;

class BookingAccountingTest extends AccountingTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        DB::statement('ALTER TABLE bookings ADD COLUMN property_price DECIMAL(15,2) DEFAULT 1000');
        DB::statement('ALTER TABLE bookings ADD COLUMN discount DECIMAL(15,2) DEFAULT 0');
        DB::statement('ALTER TABLE bookings ADD COLUMN sales_agent_id INTEGER');
        DB::statement('ALTER TABLE bookings ADD COLUMN notes TEXT');
        DB::statement('ALTER TABLE properties ADD COLUMN created_at TEXT');
        DB::statement('ALTER TABLE properties ADD COLUMN updated_at TEXT');
        DB::statement('CREATE TABLE property_status_histories (id INTEGER PRIMARY KEY, property_id INTEGER, old_status TEXT, new_status TEXT, changed_by INTEGER, notes TEXT, created_at TEXT, updated_at TEXT)');
        $migration = require database_path('migrations/2026_09_09_176000_create_commissions_table.php');
        $migration->up();
        ChartOfAccount::create(['code'=>'1200', 'name'=>'Accounts Receivable', 'account_type'=>'asset', 'normal_balance'=>'debit', 'is_system'=>true, 'is_control_account'=>true]);
        ChartOfAccount::create(['code'=>'4100', 'name'=>'Property Sales', 'account_type'=>'revenue', 'normal_balance'=>'credit', 'is_system'=>true]);
    }

    private function controller()
    {
        return new class extends BookingAccountingController {
            protected function canAccessAllBranches() { return true; }
        };
    }

    private function paymentController()
    {
        return new class extends PaymentController {
            protected function canAccessAllBranches() { return true; }
        };
    }

    private function request(array $data)
    {
        $request = Request::create('/bookings/1/recognize-revenue', 'POST', $data);
        $request->setUserResolver(function () { return User::find(1); });
        return $request;
    }

    private function recognize($date = '2026-09-25')
    {
        return $this->controller()->recognize($this->request(['accounting_date'=>$date, 'reference'=>'Handover H-001', 'confirmed'=>true]), Booking::find(1), new BookingAccountingService);
    }

    private function collect($amount = '200.00', $date = '2026-09-24')
    {
        $this->paymentController()->store($this->request([
            'booking_id'=>1, 'customer_id'=>1, 'amount'=>$amount, 'payment_date'=>$date,
            'payment_method'=>'cash', 'cash_bank_account_id'=>ChartOfAccount::where('code','1101')->value('id'),
        ]));
        return Payment::latest('id')->first();
    }

    private function reversePayment($payment, $date = '2026-09-26')
    {
        return $this->paymentController()->reverse($this->request(['reason'=>'Receipt voided', 'reversal_date'=>$date]), $payment);
    }

    private function cancel()
    {
        $controller = new class extends BookingStatusController {
            protected function canAccessAllBranches() { return true; }
        };
        return $controller->cancel(Booking::find(1));
    }

    private function balance($code)
    {
        return (float) DB::table('journal_lines')->where('chart_of_account_id', ChartOfAccount::where('code',$code)->value('id'))->selectRaw('COALESCE(SUM(debit - credit),0) AS balance')->first()->balance;
    }

    private function rejected(callable $operation)
    {
        try { $operation(); $this->fail('Invalid accounting operation accepted.'); }
        catch (ValidationException $e) { $this->assertNotEmpty($e->errors()); }
        catch (HttpException $e) { $this->assertSame(422, $e->getStatusCode()); }
    }

    public function test_recognition_applies_existing_advances_and_preserves_dimensions_once()
    {
        $payment = $this->collect();
        $this->assertEquals(-200, $this->balance('2300'));
        $this->recognize();
        $this->recognize();
        $this->assertEquals(800, $this->balance('1200'));
        $this->assertEquals(-1000, $this->balance('4100'));
        $this->assertEquals(0, $this->balance('2300'));
        $this->assertEquals(200, $this->balance('1101'));
        $this->assertSame(3, JournalEntry::count());
        $this->assertSame(1, DB::table('financial_audits')->where('action','revenue_recognized')->count());
        $this->assertSame('2026-09-25', $payment->fresh()->applicationJournal->entry_date->toDateString());
        foreach (JournalEntry::with('lines')->get() as $entry) {
            $this->assertEquals($entry->lines->sum('debit'), $entry->lines->sum('credit'));
            foreach ($entry->lines as $line) {
                $this->assertEquals(1, $line->project_id);
                $this->assertEquals(1, $line->customer_id);
            }
        }
    }

    public function test_new_collection_applies_to_receivable_and_reversal_restores_it()
    {
        $this->recognize();
        $payment = $this->collect('350.50', '2026-09-26');
        $this->assertSame('2026-09-26', $payment->applicationJournal->entry_date->toDateString());
        $this->assertEquals(649.5, $this->balance('1200'));
        $this->assertEquals(0, $this->balance('2300'));
        $this->reversePayment($payment);
        $this->assertEquals(1000, $this->balance('1200'));
        $this->assertEquals(-1000, $this->balance('4100'));
        $this->assertEquals(0, $this->balance('2300'));
        $this->assertEquals(0, $this->balance('1101'));
        $this->assertSame('1000.00', Booking::find(1)->remaining_amount);
        $this->assertEquals($payment->applicationJournal->id, $payment->fresh()->applicationReversalJournal->reverses_entry_id);
        $this->assertSame(5, JournalEntry::count());
    }

    public function test_receipt_allocation_uses_later_date_even_for_backdated_recognition_or_receipt()
    {
        $later = $this->collect('100.00', '2026-09-26');
        $this->recognize('2026-09-24');
        $this->assertSame('2026-09-26', $later->fresh()->applicationJournal->entry_date->toDateString());
        $earlier = $this->collect('100.00', '2026-09-23');
        $this->assertSame('2026-09-24', $earlier->applicationJournal->entry_date->toDateString());
        $this->rejected(function () use ($earlier) { $this->reversePayment($earlier, '2026-09-23'); });
        $this->assertSame('verified', $earlier->fresh()->status);
        $this->assertSame('200.00', Booking::find(1)->paid_amount);
    }

    public function test_closed_period_rolls_back_recognition_receipts_reversals_and_cancellation()
    {
        $payment = $this->collect();
        AccountingPeriod::query()->update(['status'=>'closed']);
        $this->rejected(function () { $this->recognize(); });
        $this->assertNull(Booking::find(1)->revenueJournal);
        $this->assertSame(1, JournalEntry::count());
        AccountingPeriod::query()->update(['status'=>'open']);
        $this->recognize();
        AccountingPeriod::query()->update(['status'=>'closed']);
        $this->rejected(function () { $this->collect(); });
        $this->rejected(function () use ($payment) { $this->reversePayment($payment); });
        $this->assertSame(1, Payment::count());
        $this->assertSame(3, JournalEntry::count());
        $this->assertSame('200.00', Booking::find(1)->paid_amount);
        AccountingPeriod::query()->update(['status'=>'open']);
        $this->reversePayment($payment);
        AccountingPeriod::query()->update(['status'=>'closed']);
        $this->rejected(function () { $this->cancel(); });
        $this->assertSame('confirmed', Booking::find(1)->status);
        $this->assertNull(Booking::find(1)->revenueCancellationJournal);
        AccountingPeriod::query()->update(['status'=>'open']);
        $this->cancel();
        $this->assertSame('cancelled', Booking::find(1)->status);
        $this->assertEquals(0, $this->balance('1200'));
        $this->assertEquals(0, $this->balance('4100'));
        $this->assertSame(6, JournalEntry::count());
    }

    public function test_recognition_rejects_legacy_unjournaled_receipts_and_inconsistent_totals()
    {
        $legacy = Payment::create(['receipt_number'=>'LEGACY-1','booking_id'=>1,'customer_id'=>1,'amount'=>'100.00','payment_date'=>'2026-09-01','payment_method'=>'cash','status'=>'verified','received_by'=>1]);
        Booking::find(1)->update(['paid_amount'=>100,'remaining_amount'=>900]);
        $this->rejected(function () { $this->recognize(); });
        $this->assertSame(0, JournalEntry::count());
        $legacy->delete();
        $this->rejected(function () { $this->recognize(); });
        $this->assertSame(0, JournalEntry::count());
    }

    public function test_allocation_in_closed_period_rolls_back_recognition_and_audit()
    {
        $payment = $this->collect('100.00', '2026-09-26');
        $period = AccountingPeriod::first();
        $period->update(['ends_on'=>'2026-09-25']);
        AccountingPeriod::create(['fiscal_year_id'=>$period->fiscal_year_id, 'name'=>'Late September', 'starts_on'=>'2026-09-26', 'ends_on'=>'2026-09-30', 'status'=>'closed']);
        $this->rejected(function () { $this->recognize('2026-09-25'); });
        $this->assertSame(1, JournalEntry::count());
        $this->assertNull(Booking::find(1)->revenueJournal);
        $this->assertNull($payment->fresh()->applicationJournal);
        $this->assertSame(0, DB::table('financial_audits')->where('action','revenue_recognized')->count());
    }

    public function test_recognition_cannot_backdate_across_a_previously_reversed_receipt()
    {
        $payment = $this->collect();
        $this->reversePayment($payment, '2026-09-26');
        $this->rejected(function () { $this->recognize('2026-09-25'); });
        $this->assertSame(2, JournalEntry::count());
        $this->recognize('2026-09-26');
        $this->assertEquals(1000, $this->balance('1200'));
        $this->assertSame(3, JournalEntry::count());
    }

    public function test_recognized_price_date_and_deletion_are_locked()
    {
        $this->recognize();
        $controller = new class extends BookingController {
            protected function canAccessAllBranches() { return true; }
        };
        $this->rejected(function () use ($controller) { $controller->update($this->request(['discount'=>50]), Booking::find(1)); });
        $this->rejected(function () use ($controller) { $controller->update($this->request(['booking_date'=>'2026-09-24']), Booking::find(1)); });
        $this->rejected(function () use ($controller) { $controller->destroy(Booking::find(1)); });
        $controller->update($this->request(['notes'=>'Reference attached']), Booking::find(1));
        $this->assertSame('Reference attached', Booking::find(1)->notes);
        $this->assertSame('1000.00', Booking::find(1)->final_price);
    }

    public function test_reserved_bookings_invalid_dates_and_inactive_accounts_cannot_post()
    {
        Booking::find(1)->update(['status'=>'reserved']);
        $this->rejected(function () { $this->recognize(); });
        Booking::find(1)->update(['status'=>'confirmed']);
        $this->rejected(function () { $this->recognize('2026-09-27'); });
        $this->rejected(function () { $this->recognize('2025-12-31'); });
        ChartOfAccount::where('code','1200')->update(['is_active'=>false]);
        $this->rejected(function () { $this->recognize(); });
        $this->assertSame(0, JournalEntry::count());
    }

    public function test_branch_access_is_enforced_and_source_journal_cannot_be_manually_reversed()
    {
        $controller = new class extends BookingAccountingController {
            protected function canAccessAllBranches() { return false; }
        };
        auth()->user()->branch_id = 2;
        try { $controller->show(Booking::find(1)); $this->fail('Other branch visible.'); }
        catch (HttpException $e) { $this->assertSame(403, $e->getStatusCode()); }
        try { $controller->recognize($this->request(['accounting_date'=>'2026-09-25','reference'=>'H-1','confirmed'=>true]), Booking::find(1), new BookingAccountingService); $this->fail('Other branch posted.'); }
        catch (HttpException $e) { $this->assertSame(403, $e->getStatusCode()); }
        $this->assertSame(0, JournalEntry::count());
        $this->recognize();
        $this->rejected(function () { (new JournalEntryController)->reverse(Booking::find(1)->revenueJournal, $this->request(['entry_date'=>'2026-09-26','reason'=>'Bypass']), new AccountingPeriodService); });
    }

    public function test_full_payment_keeps_explicit_recognition_and_reversal_reopens_receivable()
    {
        $payment = $this->collect('1000.00');
        $this->assertSame('completed', Booking::find(1)->status);
        $this->assertNull(Booking::find(1)->revenueJournal);
        $this->recognize();
        $this->assertEquals(0, $this->balance('1200'));
        $this->reversePayment($payment);
        $this->assertSame('confirmed', Booking::find(1)->status);
        $this->assertEquals(1000, $this->balance('1200'));
        $this->assertEquals(-1000, $this->balance('4100'));
    }
}
