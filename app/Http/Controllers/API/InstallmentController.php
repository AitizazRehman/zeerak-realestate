<?php
namespace App\Http\Controllers\API;
use App\Http\Controllers\Controller;
use App\Models\Installment;
use Illuminate\Http\Request;
class InstallmentController extends Controller
{
 public function index(Request $request){$q=Installment::with(['plan','booking.customer','booking.property']);foreach(['booking_id','installment_plan_id','status'] as $f)if($request->filled($f))$q->where($f,$request->$f);if($request->filled('from'))$q->whereDate('due_date','>=',$request->from);if($request->filled('to'))$q->whereDate('due_date','<=',$request->to);return response()->json($q->orderBy('due_date')->paginate($request->integer('per_page',20)));}
 public function show(Installment $installment){return response()->json($installment->load(['plan','booking.customer','booking.property.project','payments']));}
}
