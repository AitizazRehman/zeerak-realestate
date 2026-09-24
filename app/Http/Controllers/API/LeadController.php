<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\ChecksBranchAccess;
use App\Models\Project;
use App\Models\User;
use App\Models\Lead;
use App\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Http\Exceptions\HttpResponseException;
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

    private function validateBranchRefs(array &$data, Lead $lead = null)
    {
        $projectId = array_key_exists('project_id', $data)
            ? $data['project_id']
            : ($lead ? $lead->project_id : null);

        $assignedTo = array_key_exists('assigned_to', $data)
            ? $data['assigned_to']
            : ($lead ? $lead->assigned_to : null);

        $customerId = array_key_exists('customer_id', $data)
            ? $data['customer_id']
            : ($lead ? $lead->customer_id : null);

        $project = null;
        $user = null;

        if (!empty($projectId)) {
            $project = Project::findOrFail($projectId);
            $this->ensureBranchAccess($project->branch_id);
        }

        if (!empty($assignedTo)) {
            $user = User::where('is_active', true)->findOrFail($assignedTo);
            if (!$user->hasRole('Sales Agent')) {
                abort(422, 'Leads can only be assigned to an active Sales Agent.');
            }

            if (!$this->canAccessAllBranches()) {
                $this->ensureBranchAccess($user->branch_id);
            }

            if ($project && (int) $user->branch_id !== (int) $project->branch_id) {
                abort(422, 'Assigned user belongs to a different branch than the selected project.');
            }
        }

        if (!$this->canAccessAllBranches() && empty($projectId) && empty($assignedTo)) {
            $current = auth()->user();

            if ($current && $current->is_active && $current->hasRole('Sales Agent')) {
                $data['assigned_to'] = $current->id;
                $assignedTo = $current->id;
                $user = $current;
            } else {
                abort(422, 'Select an active Sales Agent or a project before creating this lead.');
            }
        }

        if (!empty($customerId)) {
            $customer = Customer::findOrFail($customerId);

            if (!$this->canAccessAllBranches() && $customer->branch_id) {
                $this->ensureBranchAccess($customer->branch_id);
            }

            $leadBranchId = $project
                ? $project->branch_id
                : ($user ? $user->branch_id : null);

            if ($leadBranchId && $customer->branch_id && (int) $customer->branch_id !== (int) $leadBranchId) {
                abort(422, 'Selected customer belongs to a different branch than this lead.');
            }
        }
    }

    private function branchIdForLead(Lead $lead)
    {
        if ($lead->project) {
            return $lead->project->branch_id;
        }

        return $lead->assignee ? $lead->assignee->branch_id : null;
    }

    private function linkCustomerToLead(Lead $lead, Customer $customer)
    {
        $branchId = $this->branchIdForLead($lead);

        if (!$branchId) {
            abort(422, 'This lead must have a project or assigned Sales Agent before conversion.');
        }

        $this->ensureBranchAccess($branchId);

        if ($customer->branch_id && (int) $customer->branch_id !== (int) $branchId) {
            abort(422, 'The existing customer belongs to a different branch and cannot be linked to this lead.');
        }

        if (!$customer->branch_id) {
            $customer->update(['branch_id' => $branchId]);
        }

        $lead->update([
            'customer_id' => $customer->id,
            'status' => 'converted',
            'next_follow_up' => null,
        ]);

        $lead->siteVisits()->whereNull('customer_id')->update(['customer_id' => $customer->id]);

        return $customer;
    }

    private function duplicateCustomerForLead(Lead $lead)
    {
        $phone = trim((string) $lead->phone);
        $email = trim((string) $lead->email);

        $query = Customer::query()->where(function ($customerQuery) use ($phone, $email) {
            if ($phone !== '') {
                $customerQuery->where('phone', $phone);
            }

            if ($email !== '') {
                if ($phone !== '') {
                    $customerQuery->orWhereRaw('LOWER(email) = ?', [strtolower($email)]);
                } else {
                    $customerQuery->whereRaw('LOWER(email) = ?', [strtolower($email)]);
                }
            }
        });

        if ($phone === '' && $email === '') {
            return null;
        }

        $branchId = $this->branchIdForLead($lead);

        if ($branchId) {
            $query->where(function ($branchQuery) use ($branchId) {
                $branchQuery->where('branch_id', $branchId)
                    ->orWhereNull('branch_id');
            });
        }

        return $query->orderByDesc('id')->first();
    }
    public function index(Request $request)
    {
        $baseQuery = $this->scopeBranch(Lead::query());

        if ($request->filled('search')) {
            $search = $request->search;
            $baseQuery->where(function ($query) use ($search) {
                $query->where('name', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")
                    ->orWhere('lead_number', 'like', "%{$search}%");
            });
        }

        $pipelineQuery = clone $baseQuery;
        foreach (['priority','assigned_to','project_id'] as $field) {
            if ($request->filled($field)) {
                $pipelineQuery->where($field, $request->$field);
            }
        }

        $pipelineCounts = $pipelineQuery
            ->select('status', DB::raw('COUNT(*) as aggregate'))
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->map(function ($count) {
                return (int) $count;
            });

        $query = (clone $baseQuery)->with(['customer','assignee:id,name','project:id,name']);

        if ($request->filled('lead_id')) {
            $query->where('id', (int) $request->get('lead_id'));
        }

        foreach (['status','priority','assigned_to','project_id'] as $field) {
            if ($request->filled($field)) {
                $query->where($field, $request->$field);
            }
        }

        $perPage = min(max((int) $request->get('per_page', 15), 1), 100);
        $paginator = $query->latest()->paginate($perPage);
        $response = $paginator->toArray();
        $response['pipeline_counts'] = $pipelineCounts;

        return response()->json($response);
    }

    public function store(Request $request)
    {
        $data=$request->validate(['customer_id'=>'nullable|exists:customers,id','assigned_to'=>'nullable|exists:users,id','name'=>'required|string|max:150','phone'=>'required|string|max:30','email'=>'nullable|email|max:150','source'=>'nullable|string|max:100','status'=>'nullable|in:new,contacted,qualified,site_visit,negotiation,converted,lost','priority'=>'nullable|in:low,medium,high','project_id'=>'nullable|exists:projects,id','budget'=>'nullable|numeric|min:0','next_follow_up'=>'nullable|date','notes'=>'nullable|string']);
        if (($data['status'] ?? 'new') === 'converted') {
            abort(422, 'Use Convert to Customer to mark a lead as converted.');
        }
        $this->validateBranchRefs($data);
        do {
            $data['lead_number']='LEAD-'.now()->format('Ym').'-'.strtoupper(Str::random(10));
        } while (Lead::withTrashed()->where('lead_number',$data['lead_number'])->exists());
        $lead=Lead::create($data);
        return response()->json(['message'=>'Lead created successfully.','lead'=>$lead->load(['customer','assignee','project'])],201);
    }
    public function show(Lead $lead){ $this->scopeBranch(Lead::query())->findOrFail($lead->id); return response()->json($lead->load(['customer','assignee','project'])); }
    public function update(Request $request, Lead $lead)
    {
        $data=$request->validate(['customer_id'=>'nullable|exists:customers,id','assigned_to'=>'nullable|exists:users,id','name'=>'required|string|max:150','phone'=>'required|string|max:30','email'=>'nullable|email|max:150','source'=>'nullable|string|max:100','status'=>'nullable|in:new,contacted,qualified,site_visit,negotiation,converted,lost','priority'=>'nullable|in:low,medium,high','project_id'=>'nullable|exists:projects,id','budget'=>'nullable|numeric|min:0','next_follow_up'=>'nullable|date','notes'=>'nullable|string']);
        $lead = $this->scopeBranch(Lead::query())->findOrFail($lead->id);

        $requestedStatus = $data['status'] ?? $lead->status;

        if ($lead->status === 'converted') {
            if ($requestedStatus !== 'converted') {
                abort(422, 'Converted leads cannot be moved back to another status. Retain the conversion as CRM history.');
            }

            if (array_key_exists('customer_id', $data) && (int) $data['customer_id'] !== (int) $lead->customer_id) {
                abort(422, 'The customer linked to a converted lead cannot be changed.');
            }

            $data['status'] = 'converted';
            $data['customer_id'] = $lead->customer_id;
            $data['next_follow_up'] = null;
        } elseif ($requestedStatus === 'converted') {
            abort(422, 'Use Convert to Customer to mark a lead as converted.');
        }

        $this->validateBranchRefs($data, $lead);
        $lead->update($data);

        return response()->json([
            'message'=>'Lead updated successfully.',
            'lead'=>$lead->fresh()->load(['customer','assignee','project'])
        ]);
    }
    public function convert(Request $request, Lead $lead)
    {
        $data = $request->validate([
            'customer_id' => 'nullable|exists:customers,id',
        ]);

        $customer = DB::transaction(function () use ($lead, $data) {
            $lead = $this->scopeBranch(
                Lead::with(['project','assignee'])->lockForUpdate()
            )->findOrFail($lead->id);

            if ($lead->status === 'lost') {
                abort(422, 'A lost lead must be reopened before it can be converted.');
            }

            if ($lead->customer_id) {
                $existing = Customer::lockForUpdate()->findOrFail($lead->customer_id);
                return $this->linkCustomerToLead($lead, $existing);
            }

            if (!empty($data['customer_id'])) {
                $existing = Customer::lockForUpdate()->findOrFail($data['customer_id']);
                return $this->linkCustomerToLead($lead, $existing);
            }

            $duplicate = $this->duplicateCustomerForLead($lead);

            if ($duplicate) {
                throw new HttpResponseException(response()->json([
                    'message' => 'A customer with the same phone or email already exists. Review and link the existing customer instead of creating a duplicate.',
                    'existing_customer' => [
                        'id' => $duplicate->id,
                        'customer_number' => $duplicate->customer_number,
                        'name' => $duplicate->name,
                        'phone' => $duplicate->phone,
                        'email' => $duplicate->email,
                    ],
                ], 409));
            }

            do {
                $number = 'CUS-' . now()->format('Ym') . '-' . strtoupper(Str::random(10));
            } while (Customer::withTrashed()->where('customer_number', $number)->exists());

            $branchId = $this->branchIdForLead($lead);

            if (!$branchId) {
                abort(422, 'This lead must have a project or assigned Sales Agent before conversion.');
            }

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

            return $this->linkCustomerToLead($lead, $customer);
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
