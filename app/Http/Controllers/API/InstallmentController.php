<?php
namespace App\Http\Controllers\API;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\ChecksBranchAccess;
use App\Models\Installment;
use Illuminate\Http\Request;
class InstallmentController extends Controller {
 use ChecksBranchAccess;
 private function scopeBranch($q){if(!$this->canAccessAllBranches())$q->whereHas('booking.property.project',fn($x)=>$x->where('branch_id',auth()->user()->branch_id));return $q;}
 public function index(Request $r){$q=$this->scopeBranch(Installment::with(['plan','booking.customer','booking.property']));foreach(['booking_id','installment_plan_id','status'] as $f)if($r->filled($f))$q->where($f,$r->$f);if($r->filled('from'))$q->whereDate('due_date','>=',$r->from);if($r->filled('to'))$q->whereDate('due_date','<=',$r->to);return response()->json($q->orderBy('due_date')->paginate(min(max((int)$r->get('per_page',20),1),100)));}
 public function show(Installment $installment){$this->scopeBranch(Installment::query())->findOrFail($installment->id);return response()->json($installment->load(['plan','booking.customer','booking.property.project','payments']));}
}