<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\ChecksBranchAccess;
use App\Models\Property;
use App\Models\Lead;
use App\Models\User;
use App\Models\SiteVisit;
use Illuminate\Http\Request;

class SiteVisitController extends Controller
{
    use ChecksBranchAccess;

    private function scopeBranch($query)
    {
        if (!$this->canAccessAllBranches()) {
            $branchId=auth()->user()->branch_id;
            $query->where(function($q) use($branchId){
                $q->whereHas('property.project',function($p) use($branchId){$p->where('branch_id',$branchId);})
                  ->orWhere(function($fallback) use($branchId){
                      $fallback->whereNull('property_id')->whereHas('lead',function($lead) use($branchId){
                          $lead->whereHas('project',function($p) use($branchId){$p->where('branch_id',$branchId);})
                               ->orWhere(function($l) use($branchId){
                                   $l->whereNull('project_id')->whereHas('assignee',function($u) use($branchId){$u->where('branch_id',$branchId);});
                               });
                      });
                  })
                  ->orWhere(function($fallback) use($branchId){
                      $fallback->whereNull('property_id')->whereNull('lead_id')
                               ->whereHas('assignee',function($u) use($branchId){$u->where('branch_id',$branchId);});
                  });
            });
        }
        return $query;
    }

    private function scopeLeadBranch($query)
    {
        if (!$this->canAccessAllBranches()) {
            $branchId = auth()->user()->branch_id;
            $query->where(function ($lead) use ($branchId) {
                $lead->whereHas('project', function ($project) use ($branchId) {
                    $project->where('branch_id', $branchId);
                })->orWhere(function ($fallback) use ($branchId) {
                    $fallback->whereNull('project_id')
                        ->whereHas('assignee', function ($user) use ($branchId) {
                            $user->where('branch_id', $branchId);
                        });
                });
            });
        }
        return $query;
    }

    private function validateBranchRefs(array &$data)
    {
        $branchId=null;
        if(!empty($data['property_id'])){
            $property=Property::with('project')->findOrFail($data['property_id']);
            $this->ensureBranchAccess($property->project->branch_id);
            $branchId=$property->project->branch_id;
        }
        if(!empty($data['lead_id'])){
            $lead=$this->scopeLeadBranch(Lead::with(['project','assignee']))->findOrFail($data['lead_id']);
            if ($lead->status === 'lost') abort(422,'Site visits cannot be scheduled against a lost lead. Reopen the lead first.');
            $leadBranch=$lead->project ? $lead->project->branch_id : optional($lead->assignee)->branch_id;
            if($branchId && $leadBranch && (int)$branchId !== (int)$leadBranch) abort(422,'Lead and property belong to different branches.');
            $branchId=$branchId ?: $leadBranch;
        }
        if(!empty($data['customer_id']) && !empty($data['lead_id']) && $lead->customer_id && (int)$lead->customer_id !== (int)$data['customer_id']) {
            abort(422,'Selected customer does not match the lead customer.');
        }
        if(!empty($data['assigned_to'])){
            $user=User::where('is_active', true)->findOrFail($data['assigned_to']);
            if(!$user->hasRole('Sales Agent')) abort(422,'Site visits can only be assigned to an active Sales Agent.');
            if(!$this->canAccessAllBranches()) $this->ensureBranchAccess($user->branch_id);
            if($branchId && (int)$branchId !== (int)$user->branch_id) abort(422,'Assigned user belongs to a different branch.');
        }
        if(!$this->canAccessAllBranches() && empty($data['property_id']) && empty($data['lead_id']) && empty($data['assigned_to'])){
            $current=auth()->user();
            if($current && $current->is_active && $current->hasRole('Sales Agent')){
                $data['assigned_to']=$current->id;
            }else{
                abort(422,'Select an active Sales Agent, lead, or property before scheduling this site visit.');
            }
        }
    }
    public function index(Request $request){
        $q=$this->scopeBranch(SiteVisit::with(['customer','lead','property.project','property.block','assignee:id,name']));
        foreach(['customer_id','lead_id','property_id','assigned_to','status'] as $f) if($request->filled($f)) $q->where($f,$request->$f);
        if($request->filled('from'))$q->whereDate('visit_at','>=',$request->from);
        if($request->filled('to'))$q->whereDate('visit_at','<=',$request->to);
        $perPage = min(max((int) $request->get('per_page', 15), 1), 100);
        return response()->json($q->orderBy('visit_at')->paginate($perPage));
    }
    public function store(Request $request){
        $data=$request->validate(['customer_id'=>'nullable|exists:customers,id','lead_id'=>'nullable|exists:leads,id','property_id'=>'nullable|exists:properties,id','assigned_to'=>'nullable|exists:users,id','visit_at'=>'required|date','status'=>'nullable|in:scheduled,completed,cancelled,no_show','feedback'=>'nullable|string','notes'=>'nullable|string']);
        $this->validateBranchRefs($data);
        $siteVisit = SiteVisit::create($data);
        if (!empty($data['lead_id'])) {
            Lead::where('id', $data['lead_id'])
                ->whereNotIn('status', ['converted', 'lost'])
                ->update(['status' => 'site_visit']);
        }
        return response()->json(['message'=>'Site visit scheduled.','site_visit'=>$siteVisit->load(['customer','lead','property','assignee'])],201);
    }
    public function show(SiteVisit $siteVisit){$this->scopeBranch(SiteVisit::query())->findOrFail($siteVisit->id);return response()->json($siteVisit->load(['customer','lead','property.project','property.block','assignee']));}
    public function update(Request $request, SiteVisit $siteVisit){$data=$request->validate(['customer_id'=>'nullable|exists:customers,id','lead_id'=>'nullable|exists:leads,id','property_id'=>'nullable|exists:properties,id','assigned_to'=>'nullable|exists:users,id','visit_at'=>'required|date','status'=>'nullable|in:scheduled,completed,cancelled,no_show','feedback'=>'nullable|string','notes'=>'nullable|string']);$this->scopeBranch(SiteVisit::query())->findOrFail($siteVisit->id);$this->validateBranchRefs($data);$siteVisit->update($data);return response()->json(['message'=>'Site visit updated.','site_visit'=>$siteVisit->fresh()->load(['customer','lead','property','assignee'])]);}
    public function destroy(SiteVisit $siteVisit)
    {
        $siteVisit=$this->scopeBranch(SiteVisit::query())->findOrFail($siteVisit->id);
        if (in_array($siteVisit->status, ['completed','no_show'], true) || !empty($siteVisit->feedback)) {
            abort(422,'Completed/no-show site visits or visits with feedback cannot be deleted. Cancel the visit instead to preserve CRM history.');
        }
        $siteVisit->delete();
        return response()->json(['message'=>'Site visit deleted.']);
    }
}
