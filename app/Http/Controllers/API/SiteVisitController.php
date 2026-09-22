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

    private function validateBranchRefs(array &$data)
    {
        $branchId=null;
        if(!empty($data['property_id'])){
            $property=Property::with('project')->findOrFail($data['property_id']);
            $this->ensureBranchAccess($property->project->branch_id);
            $branchId=$property->project->branch_id;
        }
        if(!empty($data['lead_id'])){
            $lead=$this->scopeBranch(Lead::with(['project','assignee']))->findOrFail($data['lead_id']);
            $leadBranch=$lead->project ? $lead->project->branch_id : optional($lead->assignee)->branch_id;
            if($branchId && $leadBranch && (int)$branchId !== (int)$leadBranch) abort(422,'Lead and property belong to different branches.');
            $branchId=$branchId ?: $leadBranch;
        }
        if(!empty($data['assigned_to'])){
            $user=User::findOrFail($data['assigned_to']);
            if(!$this->canAccessAllBranches()) $this->ensureBranchAccess($user->branch_id);
            if($branchId && $user->branch_id && (int)$branchId !== (int)$user->branch_id) abort(422,'Assigned user belongs to a different branch.');
        }
        if(!$this->canAccessAllBranches() && empty($data['property_id']) && empty($data['lead_id']) && empty($data['assigned_to'])){
            $data['assigned_to']=auth()->id();
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
        return response()->json(['message'=>'Site visit scheduled.','site_visit'=>SiteVisit::create($data)->load(['customer','lead','property','assignee'])],201);
    }
    public function show(SiteVisit $siteVisit){$this->scopeBranch(SiteVisit::query())->findOrFail($siteVisit->id);return response()->json($siteVisit->load(['customer','lead','property.project','property.block','assignee']));}
    public function update(Request $request, SiteVisit $siteVisit){$data=$request->validate(['customer_id'=>'nullable|exists:customers,id','lead_id'=>'nullable|exists:leads,id','property_id'=>'nullable|exists:properties,id','assigned_to'=>'nullable|exists:users,id','visit_at'=>'required|date','status'=>'nullable|in:scheduled,completed,cancelled,no_show','feedback'=>'nullable|string','notes'=>'nullable|string']);$this->scopeBranch(SiteVisit::query())->findOrFail($siteVisit->id);$this->validateBranchRefs($data);$siteVisit->update($data);return response()->json(['message'=>'Site visit updated.','site_visit'=>$siteVisit->fresh()->load(['customer','lead','property','assignee'])]);}
    public function destroy(SiteVisit $siteVisit){$this->scopeBranch(SiteVisit::query())->findOrFail($siteVisit->id);$siteVisit->delete();return response()->json(['message'=>'Site visit deleted.']);}
}
