<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Commission;
use App\Models\Expense;
use App\Models\Installment;
use App\Models\Payment;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SalesReportController extends Controller
{
    private function dates(Request $request)
    {
        $from = $request->filled('from') ? Carbon::parse($request->get('from'))->toDateString() : now()->startOfYear()->toDateString();
        $to = $request->filled('to') ? Carbon::parse($request->get('to'))->toDateString() : now()->toDateString();
        return [$from, $to];
    }

    public function summary(Request $request)
    {
        list($from, $to) = $this->dates($request);

        $sales = Booking::whereIn('status', ['confirmed','completed'])->whereBetween('booking_date', [$from,$to]);
        $collections = Payment::where('status','verified')->whereBetween('payment_date', [$from,$to]);
        $expenses = Expense::whereBetween('expense_date', [$from,$to]);
        $commissions = Commission::whereIn('status',['approved','paid'])->whereHas('booking', function ($q) use ($from,$to) {
            $q->whereBetween('booking_date', [$from,$to]);
        });
        $overdue = Installment::whereIn('status',['pending','partial','overdue'])->where('due_date','<',now()->toDateString())->where('remaining_amount','>',0);

        return response()->json([
            'period'=>['from'=>$from,'to'=>$to],
            'metrics'=>[
                'sales_count'=>(clone $sales)->count(),
                'sales_value'=>(float)(clone $sales)->sum('final_price'),
                'collections'=>(float)(clone $collections)->sum('amount'),
                'expenses'=>(float)(clone $expenses)->sum('amount'),
                'commissions'=>(float)(clone $commissions)->sum('commission_amount'),
                'receivables'=>(float)Booking::whereNotIn('status',['cancelled'])->sum('remaining_amount'),
                'overdue_amount'=>(float)(clone $overdue)->sum('remaining_amount'),
                'overdue_count'=>(int)(clone $overdue)->count(),
            ],
            'monthly'=>Payment::selectRaw("DATE_FORMAT(payment_date, '%Y-%m') as month, SUM(amount) as amount")
                ->where('status','verified')->whereBetween('payment_date',[$from,$to])
                ->groupBy(DB::raw("DATE_FORMAT(payment_date, '%Y-%m')"))->orderBy('month')->get(),
        ]);
    }

    public function sales(Request $request)
    {
        list($from,$to)=$this->dates($request);
        $q=Booking::with(['customer:id,name,phone','property:id,project_id,block_id,property_number','property.project:id,name','property.block:id,name','salesAgent:id,name'])
            ->whereIn('status',['confirmed','completed'])->whereBetween('booking_date',[$from,$to]);
        if($request->filled('project_id')) $q->whereHas('property',function($x)use($request){$x->where('project_id',(int)$request->get('project_id'));});
        if($request->filled('customer_id')) $q->where('customer_id',(int)$request->get('customer_id'));
        return response()->json($q->orderByDesc('booking_date')->paginate(min(max((int)$request->get('per_page',25),1),100)));
    }

    public function collections(Request $request)
    {
        list($from,$to)=$this->dates($request);
        $q=Payment::with(['customer:id,name,phone','booking:id,booking_number,property_id','booking.property:id,property_number,project_id'])
            ->where('status','verified')->whereBetween('payment_date',[$from,$to]);
        if($request->filled('customer_id')) $q->where('customer_id',(int)$request->get('customer_id'));
        return response()->json($q->orderByDesc('payment_date')->paginate(min(max((int)$request->get('per_page',25),1),100)));
    }

    public function installments(Request $request)
    {
        list($from,$to)=$this->dates($request);
        $q=Installment::with(['booking.customer:id,name,phone','booking.property:id,property_number,project_id'])
            ->whereBetween('due_date',[$from,$to]);
        if($request->boolean('overdue')) $q->where('due_date','<',now()->toDateString())->where('remaining_amount','>',0);
        if($request->filled('status')) $q->where('status',$request->get('status'));
        return response()->json($q->orderBy('due_date')->paginate(min(max((int)$request->get('per_page',25),1),100)));
    }

    public function expenses(Request $request)
    {
        list($from,$to)=$this->dates($request);
        $q=Expense::with(['project:id,name','property:id,property_number'])->whereBetween('expense_date',[$from,$to]);
        if($request->filled('project_id')) $q->where('project_id',(int)$request->get('project_id'));
        return response()->json($q->orderByDesc('expense_date')->paginate(min(max((int)$request->get('per_page',25),1),100)));
    }

    public function commissions(Request $request)
    {
        list($from,$to)=$this->dates($request);
        $q=Commission::with(['agent:id,name','booking:id,booking_number,booking_date,customer_id','booking.customer:id,name'])
            ->whereHas('booking',function($x)use($from,$to){$x->whereBetween('booking_date',[$from,$to]);});
        return response()->json($q->orderByDesc('id')->paginate(min(max((int)$request->get('per_page',25),1),100)));
    }
}
