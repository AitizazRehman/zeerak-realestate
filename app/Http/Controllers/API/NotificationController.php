<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\ChecksBranchAccess;
use App\Models\Commission;
use App\Models\Installment;
use App\Models\Lead;
use App\Models\SiteVisit;

class NotificationController extends Controller
{
    use ChecksBranchAccess;

    private function branch($query, $relation)
    {
        if (!$this->canAccessAllBranches()) {
            $query->whereHas($relation, function ($relationQuery) {
                $relationQuery->where('branch_id', auth()->user()->branch_id);
            });
        }

        return $query;
    }

    private function branchLeads($query)
    {
        if (!$this->canAccessAllBranches()) {
            $branchId = auth()->user()->branch_id;

            $query->where(function ($lead) use ($branchId) {
                $lead->whereHas('project', function ($project) use ($branchId) {
                    $project->where('branch_id', $branchId);
                })->orWhere(function ($fallback) use ($branchId) {
                    $fallback->whereNull('project_id')
                        ->whereHas('assignee', function ($agent) use ($branchId) {
                            $agent->where('branch_id', $branchId);
                        });
                });
            });
        }

        return $query;
    }

    private function branchSiteVisits($query)
    {
        if (!$this->canAccessAllBranches()) {
            $branchId = auth()->user()->branch_id;

            $query->where(function ($visit) use ($branchId) {
                $visit->whereHas('property.project', function ($project) use ($branchId) {
                    $project->where('branch_id', $branchId);
                })->orWhere(function ($viaLead) use ($branchId) {
                    $viaLead->whereNull('property_id')
                        ->whereHas('lead.project', function ($project) use ($branchId) {
                            $project->where('branch_id', $branchId);
                        });
                })->orWhere(function ($viaLeadAgent) use ($branchId) {
                    $viaLeadAgent->whereNull('property_id')
                        ->whereHas('lead', function ($lead) use ($branchId) {
                            $lead->whereNull('project_id')
                                ->whereHas('assignee', function ($agent) use ($branchId) {
                                    $agent->where('branch_id', $branchId);
                                });
                        });
                })->orWhere(function ($viaVisitAgent) use ($branchId) {
                    $viaVisitAgent->whereNull('property_id')
                        ->whereNull('lead_id')
                        ->whereHas('assignee', function ($agent) use ($branchId) {
                            $agent->where('branch_id', $branchId);
                        });
                });
            });
        }

        return $query;
    }

    public function index()
    {
        $user = auth()->user();
        $now = now();
        $today = $now->copy()->startOfDay();
        $next7 = $now->copy()->addDays(7)->endOfDay();
        $items = [];

        if ($user->can('installments.view')) {
            $overdue = $this->branch(
                Installment::with('booking.customer'),
                'booking.property.project'
            )
                ->whereIn('status', ['pending','partial','overdue'])
                ->where('remaining_amount', '>', 0)
                ->whereHas('booking', function ($booking) {
                    $booking->whereNotIn('status', ['cancelled','completed']);
                })
                ->where(function ($query) {
                    $query->whereDoesntHave('plan')
                        ->orWhereHas('plan', function ($plan) {
                            $plan->where('status', 'active');
                        });
                })
                ->whereDate('due_date', '<', $today->toDateString())
                ->orderBy('due_date')
                ->limit(10)
                ->get();

            foreach ($overdue as $installment) {
                $items[] = [
                    'type' => 'overdue',
                    'priority' => 1,
                    'icon' => 'mdi-alert-circle',
                    'color' => 'error',
                    'title' => 'Overdue installment',
                    'message' => '#'.$installment->installment_number.' · '
                        .(optional(optional($installment->booking)->customer)->name ?: 'Customer')
                        .' · PKR '.number_format((float) $installment->remaining_amount),
                    'date' => optional($installment->due_date)->toDateString(),
                    'route' => '/admin/installments?status=overdue',
                ];
            }

            $dueSoon = $this->branch(
                Installment::with('booking.customer'),
                'booking.property.project'
            )
                ->whereIn('status', ['pending','partial'])
                ->where('remaining_amount', '>', 0)
                ->whereHas('booking', function ($booking) {
                    $booking->whereNotIn('status', ['cancelled','completed']);
                })
                ->where(function ($query) {
                    $query->whereDoesntHave('plan')
                        ->orWhereHas('plan', function ($plan) {
                            $plan->where('status', 'active');
                        });
                })
                ->whereBetween('due_date', [$today->toDateString(), $next7->toDateString()])
                ->orderBy('due_date')
                ->limit(10)
                ->get();

            foreach ($dueSoon as $installment) {
                $items[] = [
                    'type' => 'due',
                    'priority' => 3,
                    'icon' => 'mdi-calendar-clock',
                    'color' => 'warning',
                    'title' => 'Installment due soon',
                    'message' => '#'.$installment->installment_number.' · '
                        .(optional(optional($installment->booking)->customer)->name ?: 'Customer')
                        .' · PKR '.number_format((float) $installment->remaining_amount),
                    'date' => optional($installment->due_date)->toDateString(),
                    'route' => '/admin/installments?from='.$today->toDateString().'&to='.$next7->toDateString(),
                ];
            }
        }

        if ($user->can('leads.view')) {
            $followUps = $this->branchLeads(
                Lead::with(['project:id,name','assignee:id,name'])
            )
                ->whereNotIn('status', ['converted','lost'])
                ->whereNotNull('next_follow_up')
                ->whereDate('next_follow_up', '<=', $next7->toDateString())
                ->orderBy('next_follow_up')
                ->limit(10)
                ->get();

            foreach ($followUps as $lead) {
                $isLate = $lead->next_follow_up && $lead->next_follow_up->lt($today);
                $items[] = [
                    'type' => 'lead_follow_up',
                    'priority' => $isLate ? 2 : 4,
                    'icon' => $isLate ? 'mdi-account-alert' : 'mdi-account-clock',
                    'color' => $isLate ? 'error' : 'warning',
                    'title' => $isLate ? 'Lead follow-up overdue' : 'Lead follow-up due',
                    'message' => $lead->name.' · '.$lead->phone,
                    'date' => optional($lead->next_follow_up)->toDateString(),
                    'route' => '/admin/leads?lead_id='.$lead->id,
                ];
            }
        }

        if ($user->can('site_visits.view')) {
            $visits = $this->branchSiteVisits(
                SiteVisit::with(['customer:id,name','lead:id,name'])
            )
                ->where('status', 'scheduled')
                ->whereBetween('visit_at', [$now, $next7])
                ->orderBy('visit_at')
                ->limit(10)
                ->get();

            foreach ($visits as $visit) {
                $name = optional($visit->customer)->name ?: optional($visit->lead)->name ?: 'Customer / Lead';
                $items[] = [
                    'type' => 'visit',
                    'priority' => 4,
                    'icon' => 'mdi-map-marker-clock',
                    'color' => 'info',
                    'title' => 'Upcoming site visit',
                    'message' => $name.' · '.$visit->visit_at->format('d M Y h:i A'),
                    'date' => $visit->visit_at->toDateTimeString(),
                    'route' => '/admin/site-visits?status=scheduled',
                ];
            }
        }

        if ($user->can('commissions.view')) {
            $commissions = $this->branch(
                Commission::with('agent:id,name'),
                'booking.property.project'
            )
                ->where('status', 'pending')
                ->whereHas('booking', function ($booking) {
                    $booking->whereNotIn('status', ['cancelled']);
                })
                ->orderByDesc('id')
                ->limit(10)
                ->get();

            foreach ($commissions as $commission) {
                $items[] = [
                    'type' => 'commission',
                    'priority' => 5,
                    'icon' => 'mdi-account-cash',
                    'color' => 'primary',
                    'title' => 'Pending commission',
                    'message' => (optional($commission->agent)->name ?: 'Agent')
                        .' · PKR '.number_format((float) $commission->commission_amount),
                    'date' => $commission->created_at ? $commission->created_at->toDateTimeString() : null,
                    'route' => '/admin/commissions?status=pending',
                ];
            }
        }

        usort($items, function ($a, $b) {
            if ($a['priority'] === $b['priority']) {
                return strcmp((string) ($a['date'] ?: ''), (string) ($b['date'] ?: ''));
            }

            return $a['priority'] <=> $b['priority'];
        });

        return response()->json([
            'count' => count($items),
            'items' => array_slice($items, 0, 25),
        ]);
    }
}
