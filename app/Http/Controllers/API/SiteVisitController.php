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
