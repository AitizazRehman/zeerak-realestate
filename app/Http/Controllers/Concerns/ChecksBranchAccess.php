<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Support\Facades\Auth;

trait ChecksBranchAccess
{
    protected function canAccessAllBranches()
    {
        $user = Auth::user();

        return $user && $user->hasAnyRole(['Super Admin', 'Admin']);
    }

    protected function ensureBranchAccess($branchId)
    {
        if ($this->canAccessAllBranches()) {
            return;
        }

        $user = Auth::user();

        if (!$user || !$user->branch_id || (int) $user->branch_id !== (int) $branchId) {
            abort(403, 'You are not authorized to access this branch.');
        }
    }

    protected function enforceUserBranchOnCreate(array &$data, $field = 'branch_id')
    {
        if ($this->canAccessAllBranches()) {
            return;
        }

        $user = Auth::user();

        if (!$user || !$user->branch_id) {
            abort(403, 'Your user account is not assigned to a branch.');
        }

        $data[$field] = $user->branch_id;
    }
}
