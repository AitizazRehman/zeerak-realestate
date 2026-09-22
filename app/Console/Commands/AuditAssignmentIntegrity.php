<?php

namespace App\Console\Commands;

use App\Models\Lead;
use App\Models\Property;
use App\Models\SiteVisit;
use Illuminate\Console\Command;

class AuditAssignmentIntegrity extends Command
{
    protected $signature = 'zeerak:audit-assignments';
    protected $description = 'Read-only audit of sales-agent assignments and branch consistency';

    public function handle()
    {
        $issues = [];

        Property::with(['project','assignedAgent.roles'])->whereNotNull('assigned_agent_id')->chunkById(200, function ($items) use (&$issues) {
            foreach ($items as $item) {
                $this->check($issues, 'Property', $item->id, $item->assignedAgent, optional($item->project)->branch_id);
            }
        });

        Lead::with(['project','assignee.roles'])->chunkById(200, function ($items) use (&$issues) {
            foreach ($items as $item) {
                $branch = optional($item->project)->branch_id ?: optional($item->assignee)->branch_id;
                if (!$item->project_id && !$item->assigned_to) $issues[] = ['Lead',$item->id,'Orphaned: no project or assignee'];
                if ($item->assigned_to) $this->check($issues, 'Lead', $item->id, $item->assignee, $branch);
            }
        });

        SiteVisit::with(['property.project','lead.project','lead.assignee','assignee.roles'])->chunkById(200, function ($items) use (&$issues) {
            foreach ($items as $item) {
                $branch = optional(optional($item->property)->project)->branch_id
                    ?: optional(optional($item->lead)->project)->branch_id
                    ?: optional(optional($item->lead)->assignee)->branch_id;
                if (!$item->property_id && !$item->lead_id && !$item->assigned_to) $issues[] = ['SiteVisit',$item->id,'Orphaned: no property, lead or assignee'];
                if ($item->assigned_to) $this->check($issues, 'SiteVisit', $item->id, $item->assignee, $branch);
            }
        });

        if (!$issues) {
            $this->info('No assignment integrity issues found.');
            return 0;
        }

        $this->table(['Type','ID','Issue'], $issues);
        $this->warn(count($issues).' assignment integrity issue(s) found. No data was changed.');
        return 1;
    }

    private function check(array &$issues, $type, $id, $agent, $branchId)
    {
        if (!$agent) {
            $issues[] = [$type,$id,'Assigned user is missing'];
            return;
        }
        if (!$agent->is_active) $issues[] = [$type,$id,'Assigned user is inactive'];
        if (!$agent->hasRole('Sales Agent')) $issues[] = [$type,$id,'Assigned user does not have Sales Agent role'];
        if ($branchId && (int)$agent->branch_id !== (int)$branchId) $issues[] = [$type,$id,'Assigned user belongs to a different branch'];
    }
}
