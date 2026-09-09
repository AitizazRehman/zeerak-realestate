<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Lead;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class LeadController extends Controller
{
    public function index(Request $request)
    {
        $query = Lead::with(['customer','assignee:id,name','project:id,name']);
        if ($request->filled('search')) {
            $s=$request->search;
            $query->where(function($q) use($s){$q->where('name','like',"%{$s}%")->orWhere('phone','like',"%{$s}%")->orWhere('lead_number','like',"%{$s}%");});
        }
        foreach(['status','priority','assigned_to','project_id'] as $field) if($request->filled($field)) $query->where($field,$request->$field);
        return response()->json($query->latest()->paginate($request->integer('per_page',15)));
    }

    public function store(Request $request)
    {
        $data=$request->validate([
            'customer_id'=>'nullable|exists:customers,id','assigned_to'=>'nullable|exists:users,id','name'=>'required|string|max:150','phone'=>'required|string|max:30','email'=>'nullable|email|max:150','source'=>'nullable|string|max:100','status'=>'nullable|in:new,contacted,qualified,site_visit,negotiation,converted,lost','priority'=>'nullable|in:low,medium,high','project_id'=>'nullable|exists:projects,id','budget'=>'nullable|numeric|min:0','next_follow_up'=>'nullable|date','notes'=>'nullable|string'
        ]);
        $data['lead_number']='LEAD-'.now()->format('Ym').'-'.strtoupper(Str::random(6));
        $lead=Lead::create($data);
        return response()->json(['message'=>'Lead created successfully.','lead'=>$lead->load(['customer','assignee','project'])],201);
    }

    public function show(Lead $lead){ return response()->json($lead->load(['customer','assignee','project'])); }
    public function update(Request $request, Lead $lead){
        $data=$request->validate(['customer_id'=>'nullable|exists:customers,id','assigned_to'=>'nullable|exists:users,id','name'=>'required|string|max:150','phone'=>'required|string|max:30','email'=>'nullable|email|max:150','source'=>'nullable|string|max:100','status'=>'nullable|in:new,contacted,qualified,site_visit,negotiation,converted,lost','priority'=>'nullable|in:low,medium,high','project_id'=>'nullable|exists:projects,id','budget'=>'nullable|numeric|min:0','next_follow_up'=>'nullable|date','notes'=>'nullable|string']);
        $lead->update($data); return response()->json(['message'=>'Lead updated successfully.','lead'=>$lead->fresh()->load(['customer','assignee','project'])]);
    }
    public function destroy(Lead $lead){$lead->delete();return response()->json(['message'=>'Lead deleted successfully.']);}
}
