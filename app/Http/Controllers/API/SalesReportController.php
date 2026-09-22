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
use Barryvdh\DomPDF\Facade\Pdf;

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
    public function export(Request $request, $type, $format)
    {
        if (!in_array($type, ['sales','collections','installments','expenses','commissions'])) {
            abort(404, 'Unknown report type.');
        }
        if (!in_array($format, ['xls','pdf'])) {
            abort(404, 'Unknown export format.');
        }

        list($from,$to)=$this->dates($request);
        $rows=$this->exportRows($type,$request,$from,$to);
        $title=ucfirst($type).' Report';
        $file=$type.'-report-'.$from.'-to-'.$to;

        if ($format === 'pdf') {
            return Pdf::loadView('reports.financial', compact('title','rows','from','to'))
                ->setPaper('a4', 'landscape')->download($file.'.pdf');
        }

        $html=view('reports.financial', compact('title','rows','from','to'))->render();
        return response("\xEF\xBB\xBF".$html, 200, [
            'Content-Type'=>'application/vnd.ms-excel; charset=UTF-8',
            'Content-Disposition'=>'attachment; filename="'.$file.'.xls"',
            'Cache-Control'=>'max-age=0',
        ]);
    }

    private function exportRows($type, Request $request, $from, $to)
    {
        if ($type === 'sales') {
            return Booking::with(['customer','property.project','salesAgent'])
                ->whereIn('status',['confirmed','completed'])->whereBetween('booking_date',[$from,$to])
                ->orderByDesc('booking_date')->get()->map(function($x){return [
                    'Booking'=>$x->booking_number,'Customer'=>optional($x->customer)->name,
                    'Property'=>optional($x->property)->property_number,'Project'=>optional(optional($x->property)->project)->name,
                    'Date'=>optional($x->booking_date)->format('Y-m-d'),'Sale Value'=>$x->final_price,
                    'Paid'=>$x->paid_amount,'Balance'=>$x->remaining_amount,'Status'=>$x->status
                ];});
        }
        if ($type === 'collections') {
            return Payment::with(['customer','booking'])->where('status','verified')->whereBetween('payment_date',[$from,$to])
                ->orderByDesc('payment_date')->get()->map(function($x){return [
                    'Receipt'=>$x->receipt_number,'Customer'=>optional($x->customer)->name,
                    'Booking'=>optional($x->booking)->booking_number,'Date'=>optional($x->payment_date)->format('Y-m-d'),
                    'Method'=>$x->payment_method,'Reference'=>$x->reference_number,'Amount'=>$x->amount
                ];});
        }
        if ($type === 'installments') {
            $q=Installment::with(['booking.customer','booking.property'])->whereBetween('due_date',[$from,$to]);
            if($request->boolean('overdue')) $q->where('due_date','<',now()->toDateString())->where('remaining_amount','>',0);
            return $q->orderBy('due_date')->get()->map(function($x){return [
                'Installment'=>$x->installment_number,'Customer'=>optional(optional($x->booking)->customer)->name,
                'Property'=>optional(optional($x->booking)->property)->property_number,'Due Date'=>optional($x->due_date)->format('Y-m-d'),
                'Amount'=>$x->amount,'Paid'=>$x->paid_amount,'Remaining'=>$x->remaining_amount,'Status'=>$x->status
            ];});
        }
        if ($type === 'expenses') {
            return Expense::with(['project','property'])->whereBetween('expense_date',[$from,$to])->orderByDesc('expense_date')->get()->map(function($x){return [
                'Expense #'=>$x->expense_number,'Project'=>optional($x->project)->name,'Property'=>optional($x->property)->property_number,
                'Category'=>$x->category,'Vendor'=>$x->vendor_name,'Date'=>optional($x->expense_date)->format('Y-m-d'),
                'Method'=>$x->payment_method,'Amount'=>$x->amount
            ];});
        }
        return Commission::with(['agent','booking.customer'])->whereHas('booking',function($q)use($from,$to){$q->whereBetween('booking_date',[$from,$to]);})
            ->orderByDesc('id')->get()->map(function($x){return [
                'Booking'=>optional($x->booking)->booking_number,'Customer'=>optional(optional($x->booking)->customer)->name,
                'Agent'=>optional($x->agent)->name,'Percentage'=>$x->percentage,'Base Amount'=>$x->base_amount,
                'Commission'=>$x->commission_amount,'Status'=>$x->status
            ];});
    }
}
