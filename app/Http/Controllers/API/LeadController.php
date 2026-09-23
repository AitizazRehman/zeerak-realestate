<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\ChecksBranchAccess;
use App\Models\Project;
use App\Models\User;
use App\Models\Lead;
use App\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class LeadController extends Controller
{
    use ChecksBranchAccess;

    private function scopeBranch($query)
    {
        if (!$this->canAccessAllBranches()) {
            $branchId = auth()->user()->branch_id;
            $query->where(function ($q) use ($branchId) {
                $q->whereHas('project', function ($p) use ($branchId) {
                    $p->where('branch_id', $branchId);
                })->orWhere(function ($fallback) use ($branchId) {
                    $fallback->whereNull('project_id')
                        ->whereHas('assignee', function ($u) use ($branchId) {
                            $u->where('branch_id', $branchId);
                        });
                });
            });
        }
        return $query;
    }

    private function validateBranchRefs(array &$data)
    {
        if (!empty($data['project_id'])) {
            $project = Project::findOrFail($data['project_id']);
            $this->ensureBranchAccess($project->branch_id);
        }

        if (!empty($data['assigned_to'])) {
            $user = User::where('is_active', true)->findOrFail($data['assigned_to']);
            if (!$user->hasRole('Sales Agent')) abort(422, 'Leads can only be assigned to an active Sales Agent.');
            if (!$this->canAccessAllBranches()) $this->ensureBranchAccess($user->branch_id);
            if (!empty($data['project_id']) && (int)$user->branch_id !== (int)$project->branch_id) {
                abort(422, 'Assigned user belongs to a different branch than the selected project.');
            }
        }

        if (!$this->canAccessAllBranches() && empty($data['project_id']) && empty($data['assigned_to'])) {
            $current = auth()->user();
            if ($current && $current->is_active && $current->hasRole('Sales Agent')) {
                $data['assigned_to'] = $current->id;
            } else {
                abort(422, 'Select an active Sales Agent or a project before creating this lead.');
            }
        }
    }
    public function index(Request $request)
    {
        $query = $this->scopeBranch(Lead::with(['customer','assignee:id,name','project:id,name']));
        if ($request->filled('search')) {
            $s=$request->search;
            $query->where(function($q) use($s){$q->where('name','like',"%{$s}%")->orWhere('phone','like',"%{$s}%")->orWhere('lead_number','like',"%{$s}%");});
        }
        foreach(['status','priority','assigned_to','project_id'] as $field) if($request->filled($field)) $query->where($field,$request->$field);
        $perPage = min(max((int) $request->get('per_page', 15), 1), 100);
        return response()->json($query->latest()->paginate($perPage));
    }

    public function store(Request $request)
    {
        $data=$request->validate(['customer_id'=>'nullable|exists:customers,id','assigned_to'=>'nullable|exists:users,id','name'=>'required|string|max:150','phone'=>'required|string|max:30','email'=>'nullable|email|max:150','source'=>'nullable|string|max:100','status'=>'nullable|in:new,contacted,qualified,site_visit,negotiation,converted,lost','priority'=>'nullable|in:low,medium,high','project_id'=>'nullable|exists:projects,id','budget'=>'nullable|numeric|min:0','next_follow_up'=>'nullable|date','notes'=>'nullable|string']);
        if (($data['status'] ?? 'new') === 'converted' && empty($data['customer_id'])) abort(422, 'Use Convert to Customer to mark a lead as converted.');
        $this->validateBranchRefs($data);
        do {
            $data['lead_number']='LEAD-'.now()->format('Ym').'-'.strtoupper(Str::random(10));
        } while (Lead::withTrashed()->where('lead_number',$data['lead_number'])->exists());
        $lead=Lead::create($data);
        return response()->json(['message'=>'Lead created successfully.','lead'=>$lead->load(['customer','assignee','project'])],201);
    }
    public function show(Lead $lead){ $this->scopeBranch(Lead::query())->findOrFail($lead->id); return response()->json($lead->load(['customer','assignee','project'])); }
    public function update(Request $request, Lead $lead){
        $data=$request->validate(['customer_id'=>'nullable|exists:customers,id','assigned_to'=>'nullable|exists:users,id','name'=>'required|string|max:150','phone'=>'required|string|max:30','email'=>'nullable|email|max:150','source'=>'nullable|string|max:100','status'=>'nullable|in:new,contacted,qualified,site_visit,negotiation,converted,lost','priority'=>'nullable|in:low,medium,high','project_id'=>'nullable|exists:projects,id','budget'=>'nullable|numeric|min:0','next_follow_up'=>'nullable|date','notes'=>'nullable|string']);
        $this->scopeBranch(Lead::query())->findOrFail($lead->id);
        if (($data['status'] ?? $lead->status) === 'converted' && empty($data['customer_id']) && !$lead->customer_id) abort(422, 'Use Convert to Customer to mark a lead as converted.');
        $this->validateBranchRefs($data); $lead->update($data); return response()->json(['message'=>'Lead updated successfully.','lead'=>$lead->fresh()->load(['customer','assignee','project'])]);
    }
    public function convert(Lead $lead)
    {
        $customer = DB::transaction(function () use ($lead) {
            $lead = $this->scopeBranch(Lead::with(['project','assignee'])->lockForUpdate())->findOrFail($lead->id);

            if ($lead->status === 'lost') {
                abort(422, 'A lost lead must be reopened before it can be converted.');
            }

            if ($lead->customer_id) {
                $existing = Customer::findOrFail($lead->customer_id);
                $lead->siteVisits()->whereNull('customer_id')->update(['customer_id' => $existing->id]);
                if ($lead->status !== 'converted') $lead->update(['status' => 'converted']);
                return $existing;
            }

            do {
                $number = 'CUS-' . now()->format('Ym') . '-' . strtoupper(Str::random(10));
            } while (Customer::withTrashed()->where('customer_number', $number)->exists());

            $branchId = $lead->project
                ? $lead->project->branch_id
                : optional($lead->assignee)->branch_id;

            $customer = Customer::create([
                'customer_number' => $number,
                'branch_id' => $branchId,
                'name' => $lead->name,
                'phone' => $lead->phone,
                'email' => $lead->email,
                'source' => $lead->source,
                'notes' => $lead->notes,
                'is_active' => true,
            ]);

            $lead->update([
                'customer_id' => $customer->id,
                'status' => 'converted',
                'next_follow_up' => null,
            ]);

            $lead->siteVisits()->whereNull('customer_id')->update(['customer_id' => $customer->id]);

            return $customer;
        });

        return response()->json([
            'message' => 'Lead converted to customer successfully.',
            'customer' => $customer,
            'lead' => $lead->fresh()->load(['customer','assignee','project']),
        ]);
    }

    public function destroy(Lead $lead)
    {
        $lead = $this->scopeBranch(Lead::query())->findOrFail($lead->id);
        if ($lead->siteVisits()->exists()) {
            abort(422, 'Leads with site visit history cannot be deleted. Mark the lead as lost or converted instead.');
        }
        if (in_array($lead->status, ['converted'], true)) {
            abort(422, 'Converted leads cannot be deleted. Retain them as CRM history.');
        }
        $lead->delete();
        return response()->json(['message'=>'Lead deleted successfully.']);
    }
}
