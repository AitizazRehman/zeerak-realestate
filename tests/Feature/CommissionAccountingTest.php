<?php

namespace Tests\Feature;

use App\Http\Controllers\API\{BookingStatusController, CommissionController};
use App\Models\{AccountingPeriod, Booking, ChartOfAccount, Commission, CommissionPayment, JournalEntry, User};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\AccountingTestCase;

class CommissionAccountingTest extends AccountingTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        DB::statement('ALTER TABLE bookings ADD COLUMN sales_agent_id INTEGER DEFAULT 1');
        DB::statement('ALTER TABLE properties ADD COLUMN created_at TEXT');
        DB::statement('ALTER TABLE properties ADD COLUMN updated_at TEXT');
        DB::statement('CREATE TABLE property_status_histories (id INTEGER PRIMARY KEY, property_id INTEGER, old_status TEXT, new_status TEXT, changed_by INTEGER, notes TEXT, created_at TEXT, updated_at TEXT)');
        $migration = require database_path('migrations/2026_09_09_176000_create_commissions_table.php');
        $migration->up();
        require_once database_path('migrations/2026_09_27_010000_create_commission_payments_table.php');
        (new \CreateCommissionPaymentsTable)->up();
        ChartOfAccount::create(['code'=>'6500', 'name'=>'Sales Commission Expense', 'account_type'=>'expense', 'normal_balance'=>'debit', 'is_system'=>true]);
        ChartOfAccount::create(['code'=>'2200', 'name'=>'Commission Payable', 'account_type'=>'liability', 'normal_balance'=>'credit', 'is_control_account'=>true, 'is_system'=>true]);
    }

    private function controller()
    {
        return new class extends CommissionController {
            protected function canAccessAllBranches() { return true; }
        };
    }

    private function request(array $data)
    {
        $request = Request::create('/commissions', 'POST', $data);
        $request->setUserResolver(function () { return User::find(1); });
        return $request;
    }

    private function createCommission()
    {
        $this->controller()->store($this->request(['booking_id'=>1, 'agent_id'=>1, 'percentage'=>'10']));
        return Commission::latest('id')->first();
    }

    private function change($commission, $status, array $extra = [])
    {
        return $this->controller()->update($this->request($extra + ['status'=>$status, 'accounting_date'=>'2026-09-25']), $commission);
    }

    private function pay($commission, $key = 'first-payment', array $extra = [])
    {
        return $this->change($commission, 'paid', $extra + ['cash_bank_account_id'=>ChartOfAccount::where('code','1101')->value('id'), 'request_key'=>$key]);
    }

    private function reverse($commission, $paymentId = null)
    {
        return $this->controller()->reverse($this->request(['reversal_date'=>'2026-09-25', 'reason'=>'Payment voided', 'payment_id'=>$paymentId]), $commission);
    }

    private function assertRejected(callable $operation)
    {
        try { $operation(); $this->fail('Invalid accounting operation accepted.'); }
        catch (ValidationException $e) { $this->assertNotEmpty($e->errors()); }
        catch (HttpException $e) { $this->assertSame(422, $e->getStatusCode()); }
    }

    public function test_approval_accrues_expense_and_payment_settles_payable_once()
    {
        $commission = $this->createCommission();
        $this->assertSame(0, JournalEntry::count());
        $this->change($commission, 'approved');
        $entry = $commission->fresh()->accrualJournal;
        $this->assertSame('6500', $entry->lines[0]->account->code);
        $this->assertSame('100.00', $entry->lines[0]->debit);
        $this->assertSame('2200', $entry->lines[1]->account->code);
        $this->assertSame('100.00', $entry->lines[1]->credit);
        $this->assertEquals(1, $entry->lines[0]->project_id);
        $this->assertEquals(1, $entry->lines[1]->customer_id);
        $this->change($commission, 'approved');
        $this->pay($commission);
        $this->pay($commission);
        $this->assertSame(2, JournalEntry::count());
        $this->assertSame(1, CommissionPayment::count());
        $payment = CommissionPayment::first();
        $this->assertSame('2200', $payment->journalEntry->lines[0]->account->code);
        $this->assertSame('100.00', $payment->journalEntry->lines[0]->debit);
        $this->assertSame('1101', $payment->journalEntry->lines[1]->account->code);
        $this->assertSame('100.00', $payment->journalEntry->lines[1]->credit);
        $this->assertSame('paid', $commission->fresh()->status);
    }

    public function test_reverse_and_repay_preserve_history_and_reject_stale_requests()
    {
        $commission = $this->createCommission();
        $this->change($commission, 'approved');
        $this->pay($commission);
        $first = CommissionPayment::first();
        $this->reverse($commission, $first->id);
        $this->assertSame('approved', $commission->fresh()->status);
        $this->assertNotNull($first->fresh()->reversed_at);
        $this->assertEquals($first->journalEntry->id, $first->fresh()->reversalJournal->reverses_entry_id);
        $this->assertRejected(function () use ($commission) { $this->pay($commission); });
        $this->assertSame(1, CommissionPayment::count());
        $this->pay($commission, 'second-payment');
        $this->assertRejected(function () use ($commission, $first) { $this->reverse($commission, $first->id); });
        $this->assertSame('paid', $commission->fresh()->status);
        $this->assertSame(2, CommissionPayment::count());
        $this->assertSame(4, JournalEntry::count());
        $this->assertEquals(0, DB::table('journal_lines')->where('chart_of_account_id', ChartOfAccount::where('code','2200')->value('id'))->selectRaw('SUM(debit - credit) AS balance')->first()->balance);
        $this->assertEquals(-100, DB::table('journal_lines')->where('chart_of_account_id', ChartOfAccount::where('code','1101')->value('id'))->selectRaw('SUM(debit - credit) AS balance')->first()->balance);
    }

    public function test_closed_period_rolls_back_approval_payment_and_reversal()
    {
        $commission = $this->createCommission();
        AccountingPeriod::query()->update(['status'=>'closed']);
        $this->assertRejected(function () use ($commission) { $this->change($commission, 'approved'); });
        $this->assertSame('pending', $commission->fresh()->status);
        $this->assertSame(0, JournalEntry::count());
        AccountingPeriod::query()->update(['status'=>'open']);
        $this->change($commission, 'approved');
        AccountingPeriod::query()->update(['status'=>'closed']);
        $this->assertRejected(function () use ($commission) { $this->pay($commission); });
        $this->assertSame('approved', $commission->fresh()->status);
        $this->assertSame(0, CommissionPayment::count());
        AccountingPeriod::query()->update(['status'=>'open']);
        $this->pay($commission);
        $payment = CommissionPayment::first();
        AccountingPeriod::query()->update(['status'=>'closed']);
        $auditCount = DB::table('financial_audits')->count();
        $this->assertRejected(function () use ($commission, $payment) { $this->reverse($commission, $payment->id); });
        $this->assertSame('paid', $commission->fresh()->status);
        $this->assertNull($payment->fresh()->reversed_at);
        $this->assertSame(2, JournalEntry::count());
        $this->assertSame($auditCount, DB::table('financial_audits')->count());
    }

    public function test_cancellation_reverses_accrual_and_paid_commission_must_be_reversed_first()
    {
        $commission = $this->createCommission();
        $this->change($commission, 'approved');
        $this->pay($commission);
        $this->assertRejected(function () use ($commission) { $this->change($commission, 'cancelled', ['reason'=>'Booking cancelled']); });
        $this->reverse($commission, CommissionPayment::first()->id);
        $this->change($commission, 'cancelled', ['reason'=>'Booking cancelled']);
        $cancel = $commission->fresh()->cancellationJournal;
        $this->assertEquals($commission->fresh()->accrualJournal->id, $cancel->reverses_entry_id);
        $this->assertSame('100.00', $cancel->lines[0]->credit);
        $this->assertSame('100.00', $cancel->lines[1]->debit);
        $this->assertSame('cancelled', $commission->fresh()->status);
        $pending = $this->createCommission();
        $this->change($pending, 'cancelled', ['reason'=>'Not earned']);
        $this->assertNull($pending->fresh()->cancellationJournal);
        $this->assertSame(4, JournalEntry::count());
    }

    public function test_booking_cancellation_is_atomic_with_commission_reversal()
    {
        $commission = $this->createCommission();
        $this->change($commission, 'approved');
        $controller = new class extends BookingStatusController {
            protected function canAccessAllBranches() { return true; }
        };
        AccountingPeriod::query()->update(['status'=>'closed']);
        $this->assertRejected(function () use ($controller) { $controller->cancel(Booking::find(1)); });
        $this->assertSame('confirmed', Booking::find(1)->status);
        $this->assertSame('approved', $commission->fresh()->status);
        $this->assertSame(1, JournalEntry::count());
        AccountingPeriod::query()->update(['status'=>'open']);
        $controller->cancel(Booking::find(1));
        $this->assertSame('cancelled', Booking::find(1)->status);
        $this->assertSame('cancelled', $commission->fresh()->status);
        $this->assertNotNull($commission->fresh()->cancellationJournal);
        $this->assertSame('available', DB::table('properties')->value('status'));
    }

    public function test_historical_approved_commissions_require_explicit_recognition()
    {
        $commission = $this->createCommission();
        $commission->update(['status'=>'approved', 'approved_date'=>'2026-09-01']);
        $this->assertRejected(function () use ($commission) { $this->pay($commission); });
        $this->assertSame(0, CommissionPayment::count());
        $this->controller()->recognize($this->request(['accounting_date'=>'2026-09-25']), $commission);
        $this->controller()->recognize($this->request(['accounting_date'=>'2026-09-25']), $commission);
        $this->assertSame(1, JournalEntry::count());
        $this->assertSame(1, DB::table('financial_audits')->where('action','accounting_posted')->count());
        $this->pay($commission);
        $this->assertSame('paid', $commission->fresh()->status);
    }

    public function test_historical_paid_reversal_has_no_unmatched_journal()
    {
        $commission = $this->createCommission();
        $commission->update(['status'=>'paid', 'approved_date'=>'2026-09-01', 'paid_date'=>'2026-09-02']);
        $this->reverse($commission);
        $this->assertSame('approved', $commission->fresh()->status);
        $this->assertSame(0, JournalEntry::count());
        $this->assertSame(0, CommissionPayment::count());
    }

    public function test_invalid_cash_account_and_backdated_payment_do_not_change_balances()
    {
        $commission = $this->createCommission();
        $this->change($commission, 'approved');
        $this->assertRejected(function () use ($commission) { $this->pay($commission, 'bad-account', ['cash_bank_account_id'=>ChartOfAccount::where('code','2200')->value('id')]); });
        $this->assertRejected(function () use ($commission) { $this->pay($commission, 'bad-date', ['accounting_date'=>'2026-09-24']); });
        $this->assertSame('approved', $commission->fresh()->status);
        $this->assertSame(0, CommissionPayment::count());
        $this->assertSame(1, JournalEntry::count());
    }
}
