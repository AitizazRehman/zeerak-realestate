<?php

namespace Tests\Feature;

use App\Http\Controllers\API\FinancialStatementController;
use App\Models\{ChartOfAccount, JournalEntry};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\AccountingTestCase;

class FinancialStatementTest extends AccountingTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        DB::statement('ALTER TABLE projects ADD COLUMN name TEXT');
        DB::statement('ALTER TABLE customers ADD COLUMN customer_number TEXT');
        DB::statement('ALTER TABLE customers ADD COLUMN branch_id INTEGER');
        DB::table('projects')->where('id',1)->update(['name'=>'Project One']);
        DB::table('customers')->where('id',1)->update(['branch_id'=>1,'customer_number'=>'C-1']);
        DB::table('projects')->insert(['id'=>2,'branch_id'=>2,'name'=>'Other Branch']);
        DB::table('customers')->insert(['id'=>2,'branch_id'=>2,'name'=>'Other Customer','customer_number'=>'C-2']);
        foreach ([['1200','Receivable','asset'],['4100','Sales','revenue'],['6500','Commission','expense']] as $a) {
            ChartOfAccount::create(['code'=>$a[0],'name'=>$a[1],'account_type'=>$a[2],'normal_balance'=>$a[2]==='revenue'?'credit':'debit','is_system'=>true]);
        }
    }

    private function controller($all = true)
    {
        return new class($all) extends FinancialStatementController {
            private $all;
            public function __construct($all) { $this->all = $all; }
            protected function canAccessAllBranches() { return $this->all; }
        };
    }

    private function journal($date, $debitCode, $creditCode, $amount, $project = 1, $customer = 1, $status = 'posted', $description = 'Test')
    {
        $entry = JournalEntry::create(['entry_number'=>'JE-'.(JournalEntry::count()+1),'entry_date'=>$date,'description'=>$description,'status'=>$status,'created_by'=>1]);
        foreach ([$debitCode,$creditCode] as $index=>$code) $entry->lines()->create([
            'chart_of_account_id'=>ChartOfAccount::where('code',$code)->value('id'), 'debit'=>$index===0?$amount:0, 'credit'=>$index===1?$amount:0,
            'project_id'=>$project,'customer_id'=>$customer,
        ]);
        return $entry;
    }

    private function request($filters = [])
    {
        return Request::create('/statement', 'GET', $filters + ['type'=>'customer','customer_id'=>1,'from'=>'2026-09-01','to'=>'2026-09-30']);
    }

    private function report($filters = [], $all = true)
    {
        return $this->controller($all)->show($this->request($filters))->getData(true);
    }

    public function test_customer_consolidates_advances_and_receivables_without_counting_balancing_or_commission_lines()
    {
        $this->journal('2026-08-31','1101','2300','200.00');
        $this->journal('2026-09-01','1200','4100','1000.00');
        $this->journal('2026-09-01','2300','1200','200.00');
        $this->journal('2026-09-02','6500','1101','50.00');
        $this->journal('2026-09-03','1200','4100','900.00',1,1,'draft');
        $this->journal('2026-10-01','1200','4100','900.00');
        $r = $this->report();
        $this->assertSame('800.00',$r['summary']['receivable']);
        $this->assertSame('0.00',$r['summary']['advances']);
        $this->assertSame('800.00',$r['summary']['net_position']);
        $this->assertSame(['1200','2300'],array_column($r['accounts'],'code'));
        $this->assertSame('-200.00',$r['accounts'][1]['opening']);
        $this->assertSame(3,$r['entries']['total']);
    }

    public function test_project_summary_uses_period_revenue_and_costs_and_preserves_reversals_and_archived_accounts()
    {
        $this->journal('2026-08-31','1200','4100','700.00');
        $original = $this->journal('2026-09-01','1200','4100','1000.00');
        $reversal = $this->journal('2026-09-02','4100','1200','100.00');
        $reversal->update(['reverses_entry_id'=>$original->id]);
        $this->journal('2026-09-03','6500','1101','75.25');
        $this->journal('2026-09-03','6500','1101','999.00',null,null);
        ChartOfAccount::where('code','6500')->update(['is_active'=>false,'deleted_at'=>now()]);
        $r = $this->report(['type'=>'project','project_id'=>1,'customer_id'=>null]);
        $this->assertSame('900.00',$r['summary']['revenue']);
        $this->assertSame('75.25',$r['summary']['costs']);
        $this->assertSame('824.75',$r['summary']['recorded_result']);
        $this->assertSame('1600.00',$r['summary']['receivable']);
        $this->assertSame(6,$r['entries']['total']);
        $this->assertContains('6500',array_column($r['accounts'],'code'));
    }

    public function test_running_balances_continue_per_account_across_pages_and_export_includes_every_row()
    {
        $this->journal('2026-08-31','1200','4100','10.00');
        for ($i=0;$i<51;$i++) $this->journal('2026-09-01','1200','4100','1.25');
        $r = $this->report(['page'=>2]);
        $this->assertSame(51,$r['entries']['total']);
        $this->assertCount(1,$r['entries']['data']);
        $this->assertSame('73.75',$r['entries']['data'][0]['balance']);
        $this->assertSame('73.75',$r['accounts'][0]['closing']);
        $response = $this->controller()->export($this->request(['page'=>2]));
        ob_start(); $response->sendContent(); $csv = ob_get_clean();
        $this->assertSame(51,substr_count($csv,'2026-09-01,JE-'));
        $this->assertStringContainsString('73.75',$csv);
    }

    public function test_branch_scope_covers_options_filters_lines_and_exports()
    {
        auth()->user()->branch_id = 1;
        $this->journal('2026-09-01','1200','4100','10.00');
        $this->journal('2026-09-01','1200','4100','999.00',2,1);
        $this->journal('2026-09-01','1200','4100','800.00',1,2);
        $this->journal('2026-09-01','1200','4100','5.00',null,1);
        $options = $this->controller(false)->options()->getData(true);
        $this->assertSame([1],array_column($options['projects'],'id'));
        $this->assertSame([1],array_column($options['customers'],'id'));
        $this->assertSame('15.00',$this->report([],false)['summary']['receivable']);
        $this->assertSame('10.00',$this->report(['type'=>'project','project_id'=>1,'customer_id'=>null],false)['summary']['receivable']);
        foreach (['show','export'] as $method) {
            try { $this->controller(false)->$method($this->request(['customer_id'=>2])); $this->fail('Cross-branch entity allowed.'); }
            catch (HttpException $e) { $this->assertSame(403,$e->getStatusCode()); }
        }
        $response = $this->controller(false)->export($this->request());
        ob_start(); $response->sendContent(); $csv = ob_get_clean();
        $this->assertStringNotContainsString('999.00',$csv);
        $this->assertStringNotContainsString('Other Branch',$csv);
        $this->assertSame('1014.00',$this->report()['summary']['receivable']);
    }

    public function test_empty_opening_only_and_intersection_filters()
    {
        $this->assertSame([],$this->report()['accounts']);
        $this->journal('2026-08-31','1101','2300','15.25',2,1);
        $r = $this->report();
        $this->assertSame('15.25',$r['summary']['advances']);
        $this->assertSame('-15.25',$r['summary']['net_position']);
        $this->assertSame([],$r['entries']['data']);
        $this->assertSame([],$this->report(['project_id'=>1])['accounts']);
        DB::table('customers')->where('id',1)->update(['deleted_at'=>now()]);
        $this->assertSame('15.25',$this->report()['summary']['advances']);
    }

    public function test_csv_neutralizes_formula_text_and_retains_numeric_negative_balances()
    {
        $this->journal('2026-09-01','1101','2300','50.25',1,1,'posted','=HYPERLINK("bad")');
        DB::table('customers')->where('id',1)->update(['name'=>'@SUM(1)']);
        $response = $this->controller()->export($this->request());
        ob_start(); $response->sendContent(); $csv = ob_get_clean();
        $this->assertStringContainsString("'@SUM(1)",$csv);
        $this->assertStringContainsString("'=HYPERLINK",$csv);
        $this->assertStringContainsString(',-50.25',$csv);
        $this->assertStringNotContainsString("'-50.25",$csv);
    }

    public function test_invalid_range_missing_entity_and_unassigned_branch_are_rejected()
    {
        foreach ([['to'=>'2026-08-01'],['customer_id'=>null],['type'=>'invalid']] as $filters) {
            try { $this->report($filters); $this->fail('Invalid statement accepted.'); }
            catch (ValidationException $e) { $this->assertNotEmpty($e->errors()); }
        }
        try { $this->controller(false)->options(); $this->fail('Unassigned branch allowed.'); }
        catch (HttpException $e) { $this->assertSame(403,$e->getStatusCode()); }
    }
}
