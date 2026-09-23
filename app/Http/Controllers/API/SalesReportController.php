<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\ChecksBranchAccess;
use App\Models\Booking;
use App\Models\Commission;
use App\Models\Expense;
use App\Models\Installment;
use App\Models\Payment;
use App\Models\Project;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Barryvdh\DomPDF\Facade\Pdf;

class SalesReportController extends Controller
{
    use ChecksBranchAccess;

    private function branch($query, $relation = 'property.project')
    {
        if (!$this->canAccessAllBranches()) {
            $query->whereHas($relation, function ($q) {
                $q->where('branch_id', auth()->user()->branch_id);
            });
        }
        return $query;
    }

    private function branchExpenses($query)
    {
        if (!$this->canAccessAllBranches()) {
            $branchId = auth()->user()->branch_id;
            $query->where(function ($q) use ($branchId) {
                $q->where('branch_id', $branchId)
                  ->orWhereHas('project', function ($project) use ($branchId) {
                    $project->where('branch_id', $branchId);
                })->orWhere(function ($legacy) use ($branchId) {
                    $legacy->whereNull('project_id')->whereHas('property.project', function ($project) use ($branchId) {
                        $project->where('branch_id', $branchId);
                    });
                });
            });
        }
        return $query;
    }

    private function dates(Request $request)
    {
        $from = $request->filled('from') ? Carbon::parse($request->get('from'))->toDateString() : now()->startOfYear()->toDateString();
        $to = $request->filled('to') ? Carbon::parse($request->get('to'))->toDateString() : now()->toDateString();

        if ($from > $to) {
            abort(422, 'The From date cannot be later than the To date.');
        }

        return [$from, $to];
    }

    private function projectId(Request $request)
    {
        return $request->filled('project_id') ? (int) $request->get('project_id') : null;
    }

    private function applyBookingProject($query, $projectId)
    {
        if ($projectId) {
            $query->whereHas('property', function ($property) use ($projectId) {
                $property->where('project_id', $projectId);
            });
        }
        return $query;
    }

    private function applyPaymentProject($query, $projectId)
    {
        if ($projectId) {
            $query->whereHas('booking.property', function ($property) use ($projectId) {
                $property->where('project_id', $projectId);
            });
        }
        return $query;
    }

    private function applyInstallmentProject($query, $projectId)
    {
        if ($projectId) {
            $query->whereHas('booking.property', function ($property) use ($projectId) {
                $property->where('project_id', $projectId);
            });
        }
        return $query;
    }

    private function applyCommissionProject($query, $projectId)
    {
        if ($projectId) {
            $query->whereHas('booking.property', function ($property) use ($projectId) {
                $property->where('project_id', $projectId);
            });
        }
        return $query;
    }

    private function applyExpenseProject($query, $projectId)
    {
        if ($projectId) {
            $query->where(function ($expense) use ($projectId) {
                $expense->where('project_id', $projectId)
                    ->orWhere(function ($legacy) use ($projectId) {
                        $legacy->whereNull('project_id')
                            ->whereHas('property', function ($property) use ($projectId) {
                                $property->where('project_id', $projectId);
                            });
                    });
            });
        }
        return $query;
    }

    public function projects()
    {
        $query = Project::query()->select('id','name','code')->where('is_active', true);

        if (!$this->canAccessAllBranches()) {
            $query->where('branch_id', auth()->user()->branch_id);
        }

        return response()->json($query->orderBy('name')->get());
    }

    public function summary(Request $request)
    {
        list($from, $to) = $this->dates($request);
        $projectId = $this->projectId($request);

        $sales = $this->applyBookingProject(
            $this->branch(Booking::query())
                ->whereIn('status', ['confirmed','completed'])
                ->whereBetween('booking_date', [$from,$to]),
            $projectId
        );

        $collections = $this->applyPaymentProject(
            $this->branch(Payment::query(),'booking.property.project')
                ->where('status','verified')
                ->whereBetween('payment_date', [$from,$to]),
            $projectId
        );

        $expenses = $this->applyExpenseProject(
            $this->branchExpenses(Expense::query())
                ->whereBetween('expense_date', [$from,$to]),
            $projectId
        );

        $commissions = $this->applyCommissionProject(
            $this->branch(Commission::query(),'booking.property.project')
                ->whereIn('status',['approved','paid'])
                ->whereHas('booking', function ($q) use ($from,$to) {
                    $q->whereBetween('booking_date', [$from,$to]);
                }),
            $projectId
        );

        $overdue = $this->applyInstallmentProject(
            $this->branch(Installment::query(),'booking.property.project')
                ->whereIn('status',['pending','partial','overdue'])
                ->where('due_date','<',now()->toDateString())
                ->where('remaining_amount','>',0),
            $projectId
        );

        $receivables = $this->applyBookingProject(
            $this->branch(Booking::query())->whereNotIn('status',['cancelled']),
            $projectId
        );

        $monthly = $this->applyPaymentProject(
            $this->branch(
                Payment::selectRaw("DATE_FORMAT(payment_date, '%Y-%m') as month, SUM(amount) as amount"),
                'booking.property.project'
            )->where('status','verified')->whereBetween('payment_date',[$from,$to]),
            $projectId
        )->groupBy(DB::raw("DATE_FORMAT(payment_date, '%Y-%m')"))->orderBy('month')->get();

        $collectionTotal = (float) (clone $collections)->sum('amount');
        $expenseTotal = (float) (clone $expenses)->sum('amount');

        return response()->json([
            'period'=>['from'=>$from,'to'=>$to],
            'metrics'=>[
                'sales_count'=>(clone $sales)->count(),
                'sales_value'=>(float)(clone $sales)->sum('final_price'),
                'collections'=>$collectionTotal,
                'expenses'=>$expenseTotal,
                'net_cash_flow'=>$collectionTotal-$expenseTotal,
                'commissions'=>(float)(clone $commissions)->sum('commission_amount'),
                'receivables'=>(float)(clone $receivables)->sum('remaining_amount'),
                'overdue_amount'=>(float)(clone $overdue)->sum('remaining_amount'),
                'overdue_count'=>(int)(clone $overdue)->count(),
            ],
            'monthly'=>$monthly,
        ]);
    }

    public function sales(Request $request)
    {
        list($from,$to)=$this->dates($request);
        $projectId=$this->projectId($request);

        $q=$this->branch(Booking::with([
            'customer:id,name,phone',
            'property:id,project_id,block_id,property_number',
            'property.project:id,name',
            'property.block:id,name',
            'salesAgent:id,name'
        ]))->whereIn('status',['confirmed','completed'])
          ->whereBetween('booking_date',[$from,$to]);

        $this->applyBookingProject($q,$projectId);

        if($request->filled('customer_id')) {
            $q->where('customer_id',(int)$request->get('customer_id'));
        }

        return response()->json(
            $q->orderByDesc('booking_date')
              ->paginate(min(max((int)$request->get('per_page',25),1),100))
        );
    }

    public function collections(Request $request)
    {
        list($from,$to)=$this->dates($request);
        $projectId=$this->projectId($request);

        $q=$this->branch(
            Payment::with([
                'customer:id,name,phone',
                'booking:id,booking_number,property_id',
                'booking.property:id,property_number,project_id'
            ]),
            'booking.property.project'
        )->where('status','verified')
          ->whereBetween('payment_date',[$from,$to]);

        $this->applyPaymentProject($q,$projectId);

        if($request->filled('customer_id')) {
            $q->where('customer_id',(int)$request->get('customer_id'));
        }

        return response()->json(
            $q->orderByDesc('payment_date')
              ->paginate(min(max((int)$request->get('per_page',25),1),100))
        );
    }

    public function installments(Request $request)
    {
        list($from,$to)=$this->dates($request);
        $projectId=$this->projectId($request);

        $q=$this->branch(
            Installment::with([
                'booking.customer:id,name,phone',
                'booking.property:id,property_number,project_id'
            ]),
            'booking.property.project'
        )->whereBetween('due_date',[$from,$to]);

        $this->applyInstallmentProject($q,$projectId);

        if($request->boolean('overdue')) {
            $q->whereIn('status',['pending','partial','overdue'])
              ->where('due_date','<',now()->toDateString())
              ->where('remaining_amount','>',0);
        }

        if($request->filled('status')) {
            $q->where('status',$request->get('status'));
        }

        return response()->json(
            $q->orderBy('due_date')
              ->paginate(min(max((int)$request->get('per_page',25),1),100))
        );
    }

    public function expenses(Request $request)
    {
        list($from,$to)=$this->dates($request);
        $projectId=$this->projectId($request);

        $q=$this->applyExpenseProject(
            $this->branchExpenses(
                Expense::with(['branch:id,name','project:id,name','property:id,property_number'])
            )->whereBetween('expense_date',[$from,$to]),
            $projectId
        );

        return response()->json(
            $q->orderByDesc('expense_date')
              ->paginate(min(max((int)$request->get('per_page',25),1),100))
        );
    }

    public function commissions(Request $request)
    {
        list($from,$to)=$this->dates($request);
        $projectId=$this->projectId($request);

        $q=$this->branch(
            Commission::with([
                'agent:id,name',
                'booking:id,booking_number,booking_date,customer_id,property_id',
                'booking.customer:id,name'
            ]),
            'booking.property.project'
        )->whereHas('booking',function($x)use($from,$to){
            $x->whereBetween('booking_date',[$from,$to]);
        });

        $this->applyCommissionProject($q,$projectId);

        return response()->json(
            $q->orderByDesc('id')
              ->paginate(min(max((int)$request->get('per_page',25),1),100))
        );
    }

    public function export(Request $request, $type, $format)
    {
        if (!in_array($type, ['summary','sales','collections','installments','expenses','commissions'], true)) {
            abort(404, 'Unknown report type.');
        }
        if (!in_array($format, ['xls','pdf'], true)) {
            abort(404, 'Unknown export format.');
        }

        list($from,$to)=$this->dates($request);
        $rows=$type==='summary'
            ? $this->summaryRows($request,$from,$to)
            : $this->exportRows($type,$request,$from,$to);

        $title=$type==='summary' ? 'Financial Summary Report' : ucfirst($type).' Report';
        $file=$type.'-report-'.$from.'-to-'.$to;

        if ($format === 'pdf') {
            return Pdf::loadView('reports.financial', compact('title','rows','from','to'))
                ->setPaper('a4', $type==='summary' ? 'portrait' : 'landscape')
                ->download($file.'.pdf');
        }

        $html=view('reports.financial', compact('title','rows','from','to'))->render();

        return response("\xEF\xBB\xBF".$html, 200, [
            'Content-Type'=>'application/vnd.ms-excel; charset=UTF-8',
            'Content-Disposition'=>'attachment; filename="'.$file.'.xls"',
            'Cache-Control'=>'max-age=0',
        ]);
    }

    private function summaryRows(Request $request, $from, $to)
    {
        $projectId=$this->projectId($request);

        $sales=$this->applyBookingProject(
            $this->branch(Booking::query())
                ->whereIn('status',['confirmed','completed'])
                ->whereBetween('booking_date',[$from,$to]),
            $projectId
        );

        $collections=$this->applyPaymentProject(
            $this->branch(Payment::query(),'booking.property.project')
                ->where('status','verified')
                ->whereBetween('payment_date',[$from,$to]),
            $projectId
        );

        $expenses=$this->applyExpenseProject(
            $this->branchExpenses(Expense::query())
                ->whereBetween('expense_date',[$from,$to]),
            $projectId
        );

        $commissions=$this->applyCommissionProject(
            $this->branch(Commission::query(),'booking.property.project')
                ->whereIn('status',['approved','paid'])
                ->whereHas('booking',function($q)use($from,$to){
                    $q->whereBetween('booking_date',[$from,$to]);
                }),
            $projectId
        );

        $receivables=$this->applyBookingProject(
            $this->branch(Booking::query())->whereNotIn('status',['cancelled']),
            $projectId
        );

        $overdue=$this->applyInstallmentProject(
            $this->branch(Installment::query(),'booking.property.project')
                ->whereIn('status',['pending','partial','overdue'])
                ->where('due_date','<',now()->toDateString())
                ->where('remaining_amount','>',0),
            $projectId
        );

        $salesValue=(float)(clone $sales)->sum('final_price');
        $collectionTotal=(float)(clone $collections)->sum('amount');
        $expenseTotal=(float)(clone $expenses)->sum('amount');

        return collect([
            ['Metric'=>'Sales Count','Value'=>(clone $sales)->count()],
            ['Metric'=>'Sales Value','Value'=>'PKR '.number_format($salesValue,2)],
            ['Metric'=>'Collections','Value'=>'PKR '.number_format($collectionTotal,2)],
            ['Metric'=>'Expenses','Value'=>'PKR '.number_format($expenseTotal,2)],
            ['Metric'=>'Net Cash Flow','Value'=>'PKR '.number_format($collectionTotal-$expenseTotal,2)],
            ['Metric'=>'Commissions (Approved/Paid)','Value'=>'PKR '.number_format((float)(clone $commissions)->sum('commission_amount'),2)],
            ['Metric'=>'Receivables (Current)','Value'=>'PKR '.number_format((float)(clone $receivables)->sum('remaining_amount'),2)],
            ['Metric'=>'Overdue Installments','Value'=>(clone $overdue)->count()],
            ['Metric'=>'Overdue Amount','Value'=>'PKR '.number_format((float)(clone $overdue)->sum('remaining_amount'),2)],
        ]);
    }

    private function exportRows($type, Request $request, $from, $to)
    {
        $projectId=$this->projectId($request);

        if ($type === 'sales') {
            $q=$this->branch(Booking::with(['customer','property.project','salesAgent']))
                ->whereIn('status',['confirmed','completed'])
                ->whereBetween('booking_date',[$from,$to]);
            $this->applyBookingProject($q,$projectId);

            return $q->orderByDesc('booking_date')->get()->map(function($x){
                return [
                    'Booking'=>$x->booking_number,
                    'Customer'=>optional($x->customer)->name,
                    'Property'=>optional($x->property)->property_number,
                    'Project'=>optional(optional($x->property)->project)->name,
                    'Date'=>optional($x->booking_date)->format('Y-m-d'),
                    'Sale Value'=>$x->final_price,
                    'Paid'=>$x->paid_amount,
                    'Balance'=>$x->remaining_amount,
                    'Status'=>$x->status
                ];
            });
        }

        if ($type === 'collections') {
            $q=$this->branch(Payment::with(['customer','booking']),'booking.property.project')
                ->where('status','verified')
                ->whereBetween('payment_date',[$from,$to]);
            $this->applyPaymentProject($q,$projectId);

            return $q->orderByDesc('payment_date')->get()->map(function($x){
                return [
                    'Receipt'=>$x->receipt_number,
                    'Customer'=>optional($x->customer)->name,
                    'Booking'=>optional($x->booking)->booking_number,
                    'Date'=>optional($x->payment_date)->format('Y-m-d'),
                    'Method'=>$x->payment_method,
                    'Reference'=>$x->reference_number,
                    'Amount'=>$x->amount
                ];
            });
        }

        if ($type === 'installments') {
            $q=$this->branch(
                Installment::with(['booking.customer','booking.property']),
                'booking.property.project'
            )->whereBetween('due_date',[$from,$to]);

            $this->applyInstallmentProject($q,$projectId);

            if($request->boolean('overdue')) {
                $q->whereIn('status',['pending','partial','overdue'])
                  ->where('due_date','<',now()->toDateString())
                  ->where('remaining_amount','>',0);
            }

            return $q->orderBy('due_date')->get()->map(function($x){
                return [
                    'Installment'=>$x->installment_number,
                    'Customer'=>optional(optional($x->booking)->customer)->name,
                    'Property'=>optional(optional($x->booking)->property)->property_number,
                    'Due Date'=>optional($x->due_date)->format('Y-m-d'),
                    'Amount'=>$x->amount,
                    'Paid'=>$x->paid_amount,
                    'Remaining'=>$x->remaining_amount,
                    'Status'=>$x->status
                ];
            });
        }

        if ($type === 'expenses') {
            $q=$this->applyExpenseProject(
                $this->branchExpenses(Expense::with(['branch','project','property']))
                    ->whereBetween('expense_date',[$from,$to]),
                $projectId
            );

            return $q->orderByDesc('expense_date')->get()->map(function($x){
                return [
                    'Expense #'=>$x->expense_number,
                    'Branch'=>optional($x->branch)->name,
                    'Project'=>optional($x->project)->name,
                    'Property'=>optional($x->property)->property_number,
                    'Category'=>$x->category,
                    'Vendor'=>$x->vendor_name,
                    'Date'=>optional($x->expense_date)->format('Y-m-d'),
                    'Method'=>$x->payment_method,
                    'Amount'=>$x->amount
                ];
            });
        }

        $q=$this->branch(
            Commission::with(['agent','booking.customer']),
            'booking.property.project'
        )->whereHas('booking',function($booking)use($from,$to){
            $booking->whereBetween('booking_date',[$from,$to]);
        });

        $this->applyCommissionProject($q,$projectId);

        return $q->orderByDesc('id')->get()->map(function($x){
            return [
                'Booking'=>optional($x->booking)->booking_number,
                'Customer'=>optional(optional($x->booking)->customer)->name,
                'Agent'=>optional($x->agent)->name,
                'Percentage'=>$x->percentage,
                'Base Amount'=>$x->base_amount,
                'Commission'=>$x->commission_amount,
                'Status'=>$x->status
            ];
        });
    }
}
