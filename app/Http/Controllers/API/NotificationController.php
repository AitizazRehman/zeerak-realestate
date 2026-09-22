<?php
namespace App\Http\Controllers\API;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\ChecksBranchAccess;
use App\Models\Installment;
use App\Models\SiteVisit;
use App\Models\Commission;
use Carbon\Carbon;
class NotificationController extends Controller {
 use ChecksBranchAccess;
 private function branch($q,$relation){if(!$this->canAccessAllBranches())$q->whereHas($relation,function($x){$x->where('branch_id',auth()->user()->branch_id);});return $q;}
 private function branchSiteVisits($q){
  if(!$this->canAccessAllBranches()){
   $branchId=auth()->user()->branch_id;
   $q->where(function($x)use($branchId){
    $x->whereHas('property.project',function($project)use($branchId){$project->where('branch_id',$branchId);})
      ->orWhere(function($viaLead)use($branchId){$viaLead->whereNull('property_id')->whereHas('lead.project',function($project)use($branchId){$project->where('branch_id',$branchId);});})
      ->orWhere(function($viaLeadAgent)use($branchId){$viaLeadAgent->whereNull('property_id')->whereHas('lead',function($lead)use($branchId){$lead->whereNull('project_id')->whereHas('assignedAgent',function($agent)use($branchId){$agent->where('branch_id',$branchId);});});})
      ->orWhere(function($viaVisitAgent)use($branchId){$viaVisitAgent->whereNull('property_id')->whereNull('lead_id')->whereHas('assignedAgent',function($agent)use($branchId){$agent->where('branch_id',$branchId);});});
   });
  }
  return $q;
 }
 public function index(){
  $now=now(); $today=$now->copy()->startOfDay(); $next7=$now->copy()->addDays(7)->endOfDay(); $items=[];
  $overdue=$this->branch(Installment::with('booking.customer'),'booking.property.project')->whereIn('status',['pending','partial','overdue'])->where('remaining_amount','>',0)->whereHas('booking',function($q){$q->whereNotIn('status',['cancelled']);})->where(function($q){$q->whereDoesntHave('plan')->orWhereHas('plan',function($plan){$plan->where('status','active');});})->whereDate('due_date','<',$today)->orderBy('due_date')->limit(10)->get();
  foreach($overdue as $x)$items[]=['type'=>'overdue','icon'=>'mdi-alert-circle','color'=>'error','title'=>'Overdue installment','message'=>'#'.$x->installment_number.' · '.optional(optional($x->booking)->customer)->name.' · PKR '.number_format((float)$x->remaining_amount),'date'=>$x->due_date->toDateString(),'route'=>'/admin/installments'];
  $due=$this->branch(Installment::with('booking.customer'),'booking.property.project')->whereIn('status',['pending','partial'])->where('remaining_amount','>',0)->whereHas('booking',function($q){$q->whereNotIn('status',['cancelled']);})->where(function($q){$q->whereDoesntHave('plan')->orWhereHas('plan',function($plan){$plan->where('status','active');});})->whereBetween('due_date',[$today,$next7])->orderBy('due_date')->limit(10)->get();
  foreach($due as $x)$items[]=['type'=>'due','icon'=>'mdi-calendar-clock','color'=>'warning','title'=>'Installment due soon','message'=>'#'.$x->installment_number.' · '.optional(optional($x->booking)->customer)->name.' · PKR '.number_format((float)$x->remaining_amount),'date'=>$x->due_date->toDateString(),'route'=>'/admin/installments'];
  $visits=$this->branchSiteVisits(SiteVisit::with('customer'))->where('status','scheduled')->whereBetween('visit_at',[$now,$next7])->orderBy('visit_at')->limit(10)->get();
  foreach($visits as $x)$items[]=['type'=>'visit','icon'=>'mdi-map-marker-clock','color'=>'info','title'=>'Upcoming site visit','message'=>(optional($x->customer)->name ?: 'Customer').' · '.$x->visit_at->format('d M Y h:i A'),'date'=>$x->visit_at->toDateTimeString(),'route'=>'/admin/site-visits'];
  $commissions=$this->branch(Commission::with('agent'),'booking.property.project')->where('status','pending')->whereHas('booking',function($q){$q->whereNotIn('status',['cancelled']);})->orderByDesc('id')->limit(10)->get();
  foreach($commissions as $x)$items[]=['type'=>'commission','icon'=>'mdi-account-cash','color'=>'primary','title'=>'Pending commission','message'=>(optional($x->agent)->name ?: 'Agent').' · PKR '.number_format((float)$x->commission_amount),'date'=>$x->created_at ? $x->created_at->toDateTimeString() : null,'route'=>'/admin/commissions'];
  return response()->json(['count'=>count($items),'items'=>array_slice($items,0,25)]);
 }
}