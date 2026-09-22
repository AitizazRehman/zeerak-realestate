<?php
namespace App\Http\Controllers\API;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\ChecksBranchAccess;
use App\Models\Installment;
use App\Models\SiteVisit;
use App\Models\Commission;
class NotificationController extends Controller {
 use ChecksBranchAccess;
 private function branch($q,$relation){if(!$this->canAccessAllBranches())$q->whereHas($relation,function($x){$x->where('branch_id',auth()->user()->branch_id);});return $q;}
 public function index(){
  $today=now()->startOfDay(); $next7=now()->addDays(7)->endOfDay(); $items=[];
  $overdue=$this->branch(Installment::with('booking.customer'),'booking.property.project')->whereIn('status',['pending','partial','overdue'])->where('remaining_amount','>',0)->whereDate('due_date','<',$today)->orderBy('due_date')->limit(10)->get();
  foreach($overdue as $x)$items[]=['type'=>'overdue','icon'=>'mdi-alert-circle','color'=>'error','title'=>'Overdue installment','message'=>'#'.$x->installment_number.' · '.optional(optional($x->booking)->customer)->name.' · PKR '.number_format((float)$x->remaining_amount),'date'=>$x->due_date->toDateString(),'route'=>'/admin/installments'];
  $due=$this->branch(Installment::with('booking.customer'),'booking.property.project')->whereIn('status',['pending','partial'])->where('remaining_amount','>',0)->whereBetween('due_date',[$today,$next7])->orderBy('due_date')->limit(10)->get();
  foreach($due as $x)$items[]=['type'=>'due','icon'=>'mdi-calendar-clock','color'=>'warning','title'=>'Installment due soon','message'=>'#'.$x->installment_number.' · '.optional(optional($x->booking)->customer)->name.' · PKR '.number_format((float)$x->remaining_amount),'date'=>$x->due_date->toDateString(),'route'=>'/admin/installments'];
  $visits=$this->branch(SiteVisit::with('customer'),'property.project')->where('status','scheduled')->whereBetween('visit_at',[now(),now()->addDays(7)])->orderBy('visit_at')->limit(10)->get();
  foreach($visits as $x)$items[]=['type'=>'visit','icon'=>'mdi-map-marker-clock','color'=>'info','title'=>'Upcoming site visit','message'=>(optional($x->customer)->name ?: 'Customer').' · '.$x->visit_at->format('d M Y h:i A'),'date'=>$x->visit_at->toDateTimeString(),'route'=>'/admin/site-visits'];
  $commissions=$this->branch(Commission::with('agent'),'booking.property.project')->where('status','pending')->orderByDesc('id')->limit(10)->get();
  foreach($commissions as $x)$items[]=['type'=>'commission','icon'=>'mdi-account-cash','color'=>'primary','title'=>'Pending commission','message'=>(optional($x->agent)->name ?: 'Agent').' · PKR '.number_format((float)$x->commission_amount),'date'=>$x->created_at ? $x->created_at->toDateTimeString() : null,'route'=>'/admin/commissions'];
  return response()->json(['count'=>count($items),'items'=>array_slice($items,0,25)]);
 }
}