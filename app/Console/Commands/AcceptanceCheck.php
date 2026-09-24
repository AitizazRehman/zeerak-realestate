<?php

namespace App\Console\Commands;

use App\Models\FinancialDocument;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

class AcceptanceCheck extends Command
{
    protected $signature = 'zeerak:acceptance-check {--strict : Treat warnings as failures}';
    protected $description = 'Run non-destructive ZeeraK pre-deployment workflow and data-integrity acceptance checks';

    private $failures = 0;
    private $warnings = 0;

    public function handle()
    {
        $this->info('ZeeraK acceptance check');
        $this->line(str_repeat('-', 42));

        $this->checkCoreTables();
        $this->checkCriticalRoutes();
        $this->checkLeadIntegrity();
        $this->checkSalesIntegrity();
        $this->checkFinancialDocumentIntegrity();

        $this->newLine();
        $this->info('Failures: '.$this->failures.' | Warnings: '.$this->warnings);

        if ($this->failures > 0 || ($this->option('strict') && $this->warnings > 0)) {
            $this->error('Acceptance check failed.');
            return 1;
        }

        $this->info('Acceptance check completed successfully.');
        return 0;
    }

    private function checkCoreTables()
    {
        $tables = [
            'users',
            'branches',
            'projects',
            'properties',
            'customers',
            'leads',
            'site_visits',
            'bookings',
            'installment_plans',
            'installments',
            'payments',
            'commissions',
            'expenses',
            'financial_documents',
            'financial_audits',
        ];

        foreach ($tables as $table) {
            $this->check(
                Schema::hasTable($table),
                'Core table exists: '.$table,
                'Missing core table: '.$table,
                true
            );
        }
    }

    private function checkCriticalRoutes()
    {
        $expected = [
            ['POST', 'api/auth/login'],
            ['GET', 'api/auth/me'],
            ['GET', 'api/app-settings'],
            ['GET', 'api/leads'],
            ['POST', 'api/leads/{lead}/convert'],
            ['GET', 'api/customers'],
            ['GET', 'api/site-visits'],
            ['GET', 'api/bookings'],
            ['POST', 'api/payments'],
            ['POST', 'api/payments/{payment}/reverse'],
            ['GET', 'api/installments'],
            ['GET', 'api/commissions'],
            ['GET', 'api/expenses'],
            ['GET', 'api/financial-documents/{type}/{id}'],
            ['POST', 'api/financial-documents/{type}/{id}'],
            ['GET', 'api/reports/summary'],
            ['GET', 'api/sales/dashboard'],
        ];

        $registered = [];

        foreach (Route::getRoutes() as $route) {
            $uri = $route->uri();
            foreach ($route->methods() as $method) {
                $registered[$method.' '.$uri] = true;
            }
        }

        foreach ($expected as $route) {
            $key = $route[0].' '.$route[1];
            $this->check(
                isset($registered[$key]),
                'Route registered: '.$key,
                'Critical route is missing: '.$key,
                true
            );
        }
    }

    private function checkLeadIntegrity()
    {
        if (!Schema::hasTable('leads')) {
            return;
        }

        $convertedMissingCustomer = DB::table('leads')
            ->whereNull('deleted_at')
            ->where('status', 'converted')
            ->whereNull('customer_id')
            ->count();

        $this->check(
            $convertedMissingCustomer === 0,
            'Converted leads all have customers',
            $convertedMissingCustomer.' converted lead(s) have no customer_id',
            true
        );

        if (Schema::hasTable('customers')) {
            $missingCustomer = DB::table('leads as l')
                ->leftJoin('customers as c', 'c.id', '=', 'l.customer_id')
                ->whereNull('l.deleted_at')
                ->whereNotNull('l.customer_id')
                ->whereNull('c.id')
                ->count();

            $this->check(
                $missingCustomer === 0,
                'Lead customer references are valid',
                $missingCustomer.' lead(s) reference a missing customer',
                true
            );
        }

        if (Schema::hasTable('site_visits')) {
            $visitMismatch = DB::table('site_visits as sv')
                ->join('leads as l', 'l.id', '=', 'sv.lead_id')
                ->whereNull('l.deleted_at')
                ->where('l.status', 'converted')
                ->whereNotNull('l.customer_id')
                ->where(function ($query) {
                    $query->whereNull('sv.customer_id')
                        ->orWhereRaw('sv.customer_id <> l.customer_id');
                })
                ->count();

            $this->check(
                $visitMismatch === 0,
                'Converted lead site visits are linked to the same customer',
                $visitMismatch.' site visit(s) do not match their converted lead customer',
                true
            );
        }
    }

    private function checkSalesIntegrity()
    {
        if (Schema::hasTable('payments') && Schema::hasTable('bookings')) {
            $paymentCustomerMismatch = DB::table('payments as p')
                ->join('bookings as b', 'b.id', '=', 'p.booking_id')
                ->whereNull('p.deleted_at')
                ->whereNull('b.deleted_at')
                ->whereRaw('p.customer_id <> b.customer_id')
                ->count();

            $this->check(
                $paymentCustomerMismatch === 0,
                'Payment customers match their bookings',
                $paymentCustomerMismatch.' payment(s) reference a different customer than the booking',
                true
            );
        }

        if (Schema::hasTable('payments') && Schema::hasTable('installments')) {
            $paymentInstallmentMismatch = DB::table('payments as p')
                ->join('installments as i', 'i.id', '=', 'p.installment_id')
                ->whereNull('p.deleted_at')
                ->whereNotNull('p.installment_id')
                ->whereRaw('p.booking_id <> i.booking_id')
                ->count();

            $this->check(
                $paymentInstallmentMismatch === 0,
                'Payment installments belong to their bookings',
                $paymentInstallmentMismatch.' payment(s) reference an installment from another booking',
                true
            );
        }

        if (Schema::hasTable('installments') && Schema::hasTable('installment_plans')) {
            $planMismatch = DB::table('installments as i')
                ->join('installment_plans as ip', 'ip.id', '=', 'i.installment_plan_id')
                ->whereRaw('i.booking_id <> ip.booking_id')
                ->count();

            $this->check(
                $planMismatch === 0,
                'Installments match their installment-plan bookings',
                $planMismatch.' installment(s) have a booking mismatch with the installment plan',
                true
            );
        }

        if (Schema::hasTable('expenses') && Schema::hasTable('projects')) {
            $expenseBranchMismatch = DB::table('expenses as e')
                ->join('projects as p', 'p.id', '=', 'e.project_id')
                ->whereNull('e.deleted_at')
                ->whereNull('p.deleted_at')
                ->whereNotNull('e.branch_id')
                ->whereNotNull('p.branch_id')
                ->whereRaw('e.branch_id <> p.branch_id')
                ->count();

            $this->check(
                $expenseBranchMismatch === 0,
                'Expense branch ownership matches projects',
                $expenseBranchMismatch.' expense(s) have a branch different from their project',
                true
            );
        }

        if (Schema::hasTable('customers') && Schema::hasColumn('customers', 'branch_id')) {
            $unassignedCustomers = DB::table('customers')
                ->whereNull('deleted_at')
                ->whereNull('branch_id')
                ->count();

            if ($unassignedCustomers > 0) {
                $this->warnCheck($unassignedCustomers.' active customer(s) have no branch_id');
            } else {
                $this->pass('Active customers have explicit branch ownership');
            }
        }

        if (Schema::hasTable('expenses') && Schema::hasColumn('expenses', 'branch_id')) {
            $unassignedExpenses = DB::table('expenses')
                ->whereNull('deleted_at')
                ->whereNull('branch_id')
                ->count();

            if ($unassignedExpenses > 0) {
                $this->warnCheck($unassignedExpenses.' active expense(s) have no branch_id');
            } else {
                $this->pass('Active expenses have explicit branch ownership');
            }
        }
    }

    private function checkFinancialDocumentIntegrity()
    {
        if (!Schema::hasTable('financial_documents')) {
            return;
        }

        $supportedTypes = ['payment', 'installment', 'commission', 'expense'];
        $invalidTypeCount = DB::table('financial_documents')
            ->whereNull('deleted_at')
            ->whereNotIn('entity_type', $supportedTypes)
            ->count();

        $this->check(
            $invalidTypeCount === 0,
            'Financial documents use supported entity types',
            $invalidTypeCount.' financial document(s) use an unsupported entity_type',
            true
        );

        $missingFiles = 0;
        $unsafePaths = 0;

        FinancialDocument::query()
            ->orderBy('id')
            ->chunkById(200, function ($documents) use (&$missingFiles, &$unsafePaths) {
                foreach ($documents as $document) {
                    $path = str_replace('\\', '/', (string) $document->file_path);
                    $expectedPrefix = 'financial-documents/'.$document->entity_type.'/'.$document->entity_id.'/';

                    if (
                        $path === ''
                        || strpos($path, $expectedPrefix) !== 0
                        || strpos($path, '../') !== false
                    ) {
                        $unsafePaths++;
                        continue;
                    }

                    if (!Storage::disk('local')->exists($path)) {
                        $missingFiles++;
                    }
                }
            });

        $this->check(
            $unsafePaths === 0,
            'Financial document paths are constrained to private storage',
            $unsafePaths.' active financial document(s) have an unsafe or unexpected file path',
            true
        );

        $this->check(
            $missingFiles === 0,
            'Financial document database records have physical files',
            $missingFiles.' active financial document(s) are missing their stored file',
            true
        );
    }

    private function check($condition, $success, $problem, $critical)
    {
        if ($condition) {
            $this->pass($success);
            return;
        }

        if ($critical) {
            $this->fail($problem);
        } else {
            $this->warnCheck($problem);
        }
    }

    private function pass($message)
    {
        $this->line('<info>PASS</info> '.$message);
    }

    private function fail($message)
    {
        $this->failures++;
        $this->line('<error>FAIL</error> '.$message);
    }

    private function warnCheck($message)
    {
        $this->warnings++;
        $this->line('<comment>WARN</comment> '.$message);
    }
}
