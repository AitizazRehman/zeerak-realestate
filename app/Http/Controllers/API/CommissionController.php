<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\ChecksBranchAccess;
use App\Models\Commission;
use App\Models\Booking;
use App\Models\FinancialAudit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Services\CommissionAccountingService;
use App\Services\PaymentAccountingService;

class CommissionController extends Controller
{
    use ChecksBranchAccess;

    private function scopeBranch($query)
    {
        if (!$this->canAccessAllBranches()) {
            $query->whereHas('booking.property.project', function ($q) {
                $q->where('branch_id', auth()->user()->branch_id);
            });
        }
        return $query;
    }
    public function index(Request $r)
    {
        $q = $this->scopeBranch(Commission::with($this->relations())->withCount('financialDocuments'));
        if ($r->filled('agent_id')) $q->where('agent_id', (int)$r->agent_id);
        if ($r->filled('status')) $q->where('status', $r->status);
        $perPage = min(max((int)$r->get('per_page', 15), 1), 100);
        return response()->json($q->latest()->paginate($perPage));
    }

    public function store(Request $r)
    {
        $d = $r->validate(['booking_id'=>'required|exists:bookings,id','agent_id'=>'required|exists:users,id','percentage'=>['required','numeric','min:0.01','max:100','regex:/^\d+(\.\d{1,2})?$/'],'notes'=>'nullable|string']);
        $c = DB::transaction(function () use ($d) {
            $booking = Booking::lockForUpdate()->findOrFail($d['booking_id']);
            $this->ensureBranchAccess($booking->property->project->branch_id);
            if (!$booking->sales_agent_id || (int)$booking->sales_agent_id !== (int)$d['agent_id']) abort(422, 'Commission agent must match the booking sales agent.');
            if (in_array($booking->status, ['cancelled'], true)) abort(422, 'Cancelled bookings cannot receive commissions.');
            if (Commission::where('booking_id',$booking->id)->where('agent_id',$d['agent_id'])->whereIn('status',['pending','approved','paid'])->exists()) abort(422, 'A commission already exists for this booking and agent.');
            $base = (float)$booking->final_price;
            $d['base_amount'] = $base;
            $d['commission_amount'] = round($base * (float)$d['percentage'] / 100, 2);
            if ($d['commission_amount'] < 0.01) abort(422, 'Commission must be at least PKR 0.01.');
            $d['status'] = 'pending';
            $commission = Commission::create($d);
            FinancialAudit::create([
                'entity_type'=>'commission',
                'entity_id'=>$commission->id,
                'branch_id'=>$booking->property->project->branch_id, 'action'=>'created',
                'user_id'=>auth()->id(), 'after_data'=>$commission->fresh()->toArray()
            ]);
            return $commission;
        });
        return response()->json(['message'=>'Commission created.','commission'=>$c->load($this->relations())],201);
    }

    public function reverse(Request $r, Commission $commission)
    {
        $data = $r->validate(['reason'=>'required|string|max:1000','reversal_date'=>'required|date_format:Y-m-d|before_or_equal:today','payment_id'=>'nullable|integer|exists:commission_payments,id']);

        $commission = DB::transaction(function () use ($commission, $data) {
            $booking = Booking::with('property.project')->lockForUpdate()->findOrFail($commission->booking_id);
            $commission = $this->scopeBranch(Commission::query())->lockForUpdate()->findOrFail($commission->id);
            $this->ensureBranchAccess($booking->property->project->branch_id);

            if ($commission->status !== 'paid') abort(422, 'Only paid commissions can be reversed.');

            $before = $commission->toArray();
            if ($commission->paid_date && $data['reversal_date'] < $commission->paid_date->toDateString()) abort(422, 'Reversal date cannot precede the payment.');
            app(CommissionAccountingService::class)->reversePayment($commission, $data['reversal_date'], $data['reason'], auth()->id(), $data['payment_id'] ?? null);
            $note = trim(($commission->notes ? $commission->notes."\n" : '').'Paid commission reversed: '.$data['reason']);
            $commission->update([
                'status'=>'approved',
                'paid_date'=>null,
                'notes'=>$note,
            ]);

            FinancialAudit::create([
                'entity_type'=>'commission',
                'entity_id'=>$commission->id,
                'branch_id'=>$booking->property->project->branch_id,
                'action'=>'payment_reversed',
                'user_id'=>auth()->id(),
                'before_data'=>$before,
                'after_data'=>$commission->fresh()->toArray(),
                'reason'=>$data['reason'],
            ]);

            return $commission;
        });

        return response()->json([
            'message'=>'Commission payment reversed successfully. Commission returned to approved status.',
            'commission'=>$commission->fresh()->load($this->relations()),
        ]);
    }

    private function relations()
    {
        return ['booking.customer','booking.property','agent:id,name','accrualJournal','cancellationJournal',
            'payments.cashBankAccount:id,code,name','payments.journalEntry','payments.reversalJournal'];
    }

    public function postingAccounts(PaymentAccountingService $accounts)
    {
        return response()->json(['data' => $accounts->accounts()->map(function ($account) {
            return $account->only(['id','code','name']);
        })]);
    }

    public function recognize(Request $request, Commission $commission)
    {
        $data = $request->validate(['accounting_date' => 'required|date_format:Y-m-d|before_or_equal:today']);
        $commission = DB::transaction(function () use ($commission, $data) {
            $booking = Booking::with('property.project')->lockForUpdate()->findOrFail($commission->booking_id);
            $commission = $this->scopeBranch(Commission::query())->lockForUpdate()->findOrFail($commission->id);
            $this->ensureBranchAccess($booking->property->project->branch_id);
            if ($commission->status !== 'approved') abort(422, 'Only approved commissions can be posted through this action.');
            if ($booking->status === 'cancelled' || (int) $booking->sales_agent_id !== (int) $commission->agent_id) abort(422, 'The booking must be active and assigned to this commission agent.');
            $alreadyPosted = $commission->accrualJournal()->exists();
            $entry = app(CommissionAccountingService::class)->accrue($commission, $data['accounting_date'], auth()->id());
            if (!$alreadyPosted) FinancialAudit::create([
                'entity_type' => 'commission', 'entity_id' => $commission->id, 'branch_id' => $booking->property->project->branch_id,
                'action' => 'accounting_posted', 'user_id' => auth()->id(),
                'after_data' => ['journal_entry_id' => $entry->id, 'accounting_date' => $data['accounting_date']],
                'reason' => 'Existing approved commission explicitly posted to accounting',
            ]);
            return $commission;
        });
        return response()->json(['message' => 'Commission approval posted to accounting.', 'commission' => $commission->load($this->relations())]);
    }

    public function update(Request $r, Commission $commission)
    {
        $d = $r->validate([
            'status'=>'required|in:pending,approved,paid,cancelled', 'notes'=>'nullable|string',
            'accounting_date'=>'nullable|date_format:Y-m-d|before_or_equal:today',
            'cash_bank_account_id'=>'nullable|integer|exists:chart_of_accounts,id', 'request_key'=>'nullable|string|max:80', 'reason'=>'nullable|string|max:1000',
        ]);
        $commission = DB::transaction(function () use ($commission, $d) {
            $booking = Booking::with('property.project')->lockForUpdate()->findOrFail($commission->booking_id);
            $commission = $this->scopeBranch(Commission::query())->lockForUpdate()->findOrFail($commission->id);
            $this->ensureBranchAccess($booking->property->project->branch_id);
            $allowed = ['pending'=>['pending','approved','cancelled'], 'approved'=>['approved','paid','cancelled'], 'paid'=>['paid'], 'cancelled'=>['cancelled']];
            if (!in_array($d['status'], $allowed[$commission->status] ?? [], true)) abort(422, 'Invalid commission status transition.');
            if (in_array($d['status'], ['approved','paid'], true)) {
                if ($booking->status === 'cancelled') abort(422, 'Commission cannot be approved or paid for a cancelled booking.');
                if (!$booking->sales_agent_id || (int) $booking->sales_agent_id !== (int) $commission->agent_id) abort(422, 'Commission agent no longer matches the booking sales agent.');
            }
            $before = $commission->toArray();
            $changes = ['status' => $d['status']];
            if (array_key_exists('notes', $d)) $changes['notes'] = $d['notes'];
            $date = $d['accounting_date'] ?? now()->toDateString();
            $accounting = app(CommissionAccountingService::class);
            if ($d['status'] !== $commission->status) {
                if ($d['status'] === 'approved') {
                    $accounting->accrue($commission, $date, auth()->id());
                    $changes['approved_date'] = $date;
                } elseif ($d['status'] === 'paid') {
                    if (empty($d['cash_bank_account_id'])) abort(422, 'Choose the cash/bank account used for the commission payment.');
                    if (empty(trim($d['request_key'] ?? ''))) abort(422, 'A payment request key is required. Refresh and try again.');
                    $accounting->pay($commission, $date, $d['cash_bank_account_id'], auth()->id(), $d['request_key']);
                    $changes['paid_date'] = $date;
                } elseif ($d['status'] === 'cancelled') {
                    if (empty(trim($d['reason'] ?? ''))) abort(422, 'Provide a reason for cancelling the commission.');
                    $accounting->cancel($commission, $date, $d['reason'], auth()->id());
                }
            }
            $commission->update($changes);
            FinancialAudit::create([
                'entity_type'=>'commission', 'entity_id'=>$commission->id, 'branch_id'=>$booking->property->project->branch_id,
                'action'=>$changes['status'] === $before['status'] ? 'updated' : 'status_changed', 'user_id'=>auth()->id(),
                'before_data'=>$before, 'after_data'=>$commission->fresh()->toArray(), 'reason'=>$d['reason'] ?? ($d['notes'] ?? null),
            ]);
            return $commission;
        });
        return response()->json(['message'=>'Commission updated.','commission'=>$commission->fresh()->load($this->relations())]);
    }
}
