<?php
namespace App\Http\Controllers\API;
use App\Http\Controllers\Controller;
use App\Models\InstallmentPlan;
use App\Models\Booking;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
class InstallmentPlanController extends Controller{
 public function index(Request $r){$q=InstallmentPlan::with(['booking.customer','booking.property']);if($r->filled('booking_id'))$q->where('booking_id',$r->booking_id);return response()->json($q->latest()->paginate($r->integer('per_page',15)));}
 public function store(Request $r){$d=$r->validate(['booking_id'=>'required|exists:bookings,id','plan_name'=>'required|string|max:100','frequency'=>'required|in:monthly,quarterly,half_yearly,yearly,custom','total_amount'=>'required|numeric|min:0','down_payment'=>'nullable|numeric|min:0','installment_amount'=>'required|numeric|min:0','number_of_installments'=>'required|integer|min:1','start_date'=>'required|date','end_date'=>'nullable|date|after_or_equal:start_date','status'=>'nullable|in:active,completed,cancelled','notes'=>'nullable|string']);$plan=DB::transaction(function()use($d){$b=Booking::findOrFail($d['booking_id']);if((float)$d['total_amount']>(float)$b->final_price)abort(422,'Installment plan cannot exceed booking price.');$p=InstallmentPlan::create($d);$date=\Carbon\Carbon::parse($d['start_date']);$freq=['monthly'=>1,'quarterly'=>3,'half_yearly'=>6,'yearly'=>12,'custom'=>1][$d['frequency']];for($i=1;$i<=$d['number_of_installments'];$i++){$due=$date->copy()->addMonths($freq*($i-1));\App\Models\Installment::create(['installment_plan_id'=>$p->id,'booking_id'=>$b->id,'installment_number'=>$i,'due_date'=>$due,'amount'=>$d['installment_amount'],'paid_amount'=>0,'remaining_amount'=>$d['installment_amount'],'status'=>'pending']);}return $p;});return response()->json(['message'=>'Installment plan created.','plan'=>$plan->load('installments')],201);}
 public function show(InstallmentPlan $installmentPlan){return response()->json($installmentPlan->load(['booking.customer','booking.property','installments.payments']));}
 public function update(Request $r,InstallmentPlan $installmentPlan){$d=$r->validate(['plan_name'=>'required|string|max:100','status'=>'required|in:active,completed,cancelled','notes'=>'nullable|string']);$installmentPlan->update($d);return response()->json(['message'=>'Installment plan updated.','plan'=>$installmentPlan->fresh()->load('installments')]);}
 public function destroy(InstallmentPlan $installmentPlan){$installmentPlan->delete();return response()->json(['message'=>'Installment plan deleted.']);}
}
