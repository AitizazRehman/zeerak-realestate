<?php
namespace App\Http\Controllers\API;
use App\Http\Controllers\Controller;
use App\Models\Commission;
use Illuminate\Http\Request;
class CommissionController extends Controller{
 public function index(Request $r){$q=Commission::with(['booking.customer','booking.property','agent:id,name']);if($r->filled('agent_id'))$q->where('agent_id',$r->agent_id);if($r->filled('status'))$q->where('status',$r->status);return response()->json($q->latest()->paginate($r->integer('per_page',15)));}
 public function store(Request $r){$d=$r->validate(['booking_id'=>'required|exists:bookings,id','agent_id'=>'required|exists:users,id','percentage'=>'required|numeric|min:0|max:100','notes'=>'nullable|string']);$booking=\App\Models\Booking::findOrFail($d['booking_id']);$base=(float)$booking->final_price;$d['base_amount']=$base;$d['commission_amount']=round($base*(float)$d['percentage']/100,2);$c=Commission::create($d);return response()->json(['message'=>'Commission created.','commission'=>$c->load(['booking.customer','booking.property','agent'])],201);}
 public function update(Request $r,Commission $commission){$d=$r->validate(['status'=>'required|in:pending,approved,paid,cancelled','notes'=>'nullable|string']);if($d['status']==='approved')$d['approved_date']=now()->toDateString();if($d['status']==='paid')$d['paid_date']=now()->toDateString();$commission->update($d);return response()->json(['message'=>'Commission updated.','commission'=>$commission->fresh()->load(['booking','agent'])]);}
}
