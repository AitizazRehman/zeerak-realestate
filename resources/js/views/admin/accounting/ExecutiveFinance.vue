<template>
  <div class="executive-finance-page">
    <v-card flat class="hero pa-5 mb-4">
      <div class="d-flex align-center flex-wrap">
        <div>
          <div class="text-overline">EXECUTIVE FINANCE</div>
          <h1 class="text-h5 font-weight-bold mb-1">Financial Statements & Management KPIs</h1>
          <div class="grey--text">Ledger-backed company performance, financial position, control balances, and project recorded results.</div>
        </div>
        <v-spacer/>
        <v-chip outlined color="#165134">{{ filters.from }} → {{ filters.to }}</v-chip>
      </div>
    </v-card>

    <v-card flat class="filter-card pa-4 mb-4">
      <v-row dense>
        <v-col v-if="branches.length" cols="12" md="3">
          <v-select v-model="filters.branch_id" :items="branches" item-text="name" item-value="id" outlined dense hide-details clearable label="Branch" @change="branchChanged"/>
        </v-col>
        <v-col cols="12" md="3">
          <v-autocomplete v-model="filters.project_id" :items="projects" item-text="display" item-value="id" outlined dense hide-details clearable label="Project"/>
        </v-col>
        <v-col cols="6" md="2"><v-text-field v-model="filters.from" type="date" outlined dense hide-details label="From"/></v-col>
        <v-col cols="6" md="2"><v-text-field v-model="filters.to" type="date" outlined dense hide-details label="To"/></v-col>
        <v-col cols="12" md="2"><v-btn block color="#165134" dark depressed :loading="loading" @click="load">Apply</v-btn></v-col>
      </v-row>
    </v-card>

    <v-alert v-if="result && result.scope_note" type="info" text dense>{{ result.scope_note }}</v-alert>

    <v-row class="mb-1">
      <v-col cols="6" md="2"><v-card flat class="summary-card pa-4"><div class="caption grey--text">Revenue</div><div class="text-h6 font-weight-bold">PKR {{ money(pl.revenue) }}</div></v-card></v-col>
      <v-col cols="6" md="2"><v-card flat class="summary-card pa-4"><div class="caption grey--text">Gross Profit</div><div class="text-h6 font-weight-bold" :class="Number(pl.gross_profit)>=0?'success--text':'error--text'">PKR {{ money(pl.gross_profit) }}</div></v-card></v-col>
      <v-col cols="6" md="2"><v-card flat class="summary-card pa-4"><div class="caption grey--text">Net Result</div><div class="text-h6 font-weight-bold" :class="Number(pl.net_result)>=0?'success--text':'error--text'">PKR {{ money(pl.net_result) }}</div><div class="caption grey--text">{{ kpis.net_margin_percent===null?'—':kpis.net_margin_percent+'% margin' }}</div></v-card></v-col>
      <v-col cols="6" md="2"><v-card flat class="summary-card pa-4"><div class="caption grey--text">Cash / Bank</div><div class="text-h6 font-weight-bold">PKR {{ money(controls.cash_bank) }}</div></v-card></v-col>
      <v-col cols="6" md="2"><v-card flat class="summary-card pa-4"><div class="caption grey--text">Receivables</div><div class="text-h6 font-weight-bold">PKR {{ money(controls.accounts_receivable) }}</div></v-card></v-col>
      <v-col cols="6" md="2"><v-card flat class="summary-card pa-4"><div class="caption grey--text">Payables</div><div class="text-h6 font-weight-bold">PKR {{ money(controls.accounts_payable) }}</div></v-card></v-col>
    </v-row>

    <v-row class="mb-2">
      <v-col cols="12" md="4">
        <v-card flat class="control-card pa-4">
          <div class="caption grey--text">Operating Liquidity Position</div>
          <div class="text-h5 font-weight-bold" :class="Number(controls.operating_liquidity_position)>=0?'success--text':'error--text'">PKR {{ money(controls.operating_liquidity_position) }}</div>
          <div class="caption grey--text mt-1">Cash + AR − AP − Customer Advances</div>
        </v-card>
      </v-col>
      <v-col cols="12" md="4">
        <v-card flat class="control-card pa-4">
          <div class="caption grey--text">Cash + AR Coverage of AP</div>
          <div class="text-h5 font-weight-bold">{{ kpis.cash_plus_ar_to_ap_percent===null?'—':kpis.cash_plus_ar_to_ap_percent+'%' }}</div>
          <div class="caption grey--text mt-1">Operational coverage indicator, not a statutory liquidity ratio</div>
        </v-card>
      </v-col>
      <v-col cols="12" md="4">
        <v-card flat class="control-card pa-4">
          <div class="d-flex align-center">
            <div>
              <div class="caption grey--text">Accounting Controls</div>
              <div class="font-weight-bold">Trial Balance</div>
              <div class="caption" :class="kpis.trial_balance_balanced?'success--text':'error--text'">
                {{ kpis.trial_balance_balanced ? 'Balanced' : 'Difference PKR '+money(kpis.trial_balance_difference) }}
              </div>
              <div class="font-weight-bold mt-2">Balance Sheet</div>
              <div class="caption" :class="kpis.balance_sheet_balanced?'success--text':'error--text'">
                {{ kpis.balance_sheet_balanced ? 'Balanced' : 'Difference PKR '+money(kpis.balance_sheet_difference) }}
              </div>
            </div>
            <v-spacer/>
            <v-icon :color="kpis.trial_balance_balanced && kpis.balance_sheet_balanced ? 'success' : 'error'" size="42">
              {{ kpis.trial_balance_balanced && kpis.balance_sheet_balanced ? 'mdi-check-decagram-outline' : 'mdi-alert-octagon-outline' }}
            </v-icon>
          </div>
        </v-card>
      </v-col>
    </v-row>

    <v-card flat class="table-card">
      <v-tabs v-model="tab" color="#165134">
        <v-tab>Profit & Loss</v-tab>
        <v-tab>Balance Sheet</v-tab>
        <v-tab>Project Results</v-tab>
      </v-tabs>
      <v-divider/>

      <v-tabs-items v-model="tab">
        <v-tab-item>
          <div class="pa-4">
            <v-row>
              <v-col cols="12" md="4">
                <statement-section title="Revenue" :items="plAccounts.revenue || []" :total="pl.revenue"/>
              </v-col>
              <v-col cols="12" md="4">
                <statement-section title="Cost of Sales" :items="plAccounts.cost_of_sales || []" :total="pl.cost_of_sales"/>
              </v-col>
              <v-col cols="12" md="4">
                <statement-section title="Operating Expenses" :items="plAccounts.expense || []" :total="pl.operating_expenses"/>
              </v-col>
            </v-row>

            <v-simple-table class="mt-4">
              <tbody>
                <tr><td>Revenue</td><td class="text-right">PKR {{ money(pl.revenue) }}</td></tr>
                <tr><td>Less: Cost of Sales</td><td class="text-right">PKR {{ money(pl.cost_of_sales) }}</td></tr>
                <tr class="font-weight-bold"><td>Gross Profit</td><td class="text-right">PKR {{ money(pl.gross_profit) }}</td></tr>
                <tr><td>Less: Operating Expenses</td><td class="text-right">PKR {{ money(pl.operating_expenses) }}</td></tr>
                <tr class="font-weight-bold"><td>Net Result</td><td class="text-right">PKR {{ money(pl.net_result) }}</td></tr>
              </tbody>
            </v-simple-table>
          </div>
        </v-tab-item>

        <v-tab-item>
          <div class="pa-4">
            <v-row>
              <v-col cols="12" md="4">
                <statement-section title="Assets" :items="bsAccounts.asset || []" :total="bs.assets"/>
              </v-col>
              <v-col cols="12" md="4">
                <statement-section title="Liabilities" :items="bsAccounts.liability || []" :total="bs.liabilities"/>
              </v-col>
              <v-col cols="12" md="4">
                <statement-section title="Equity" :items="bsAccounts.equity || []" :total="bs.equity_before_unclosed_earnings"/>
                <v-card flat outlined class="pa-3 mt-3">
                  <div class="d-flex justify-space-between">
                    <span>Unclosed Earnings</span>
                    <strong>PKR {{ money(bs.unclosed_earnings) }}</strong>
                  </div>
                </v-card>
              </v-col>
            </v-row>

            <v-simple-table class="mt-4">
              <tbody>
                <tr><td>Total Assets</td><td class="text-right font-weight-bold">PKR {{ money(bs.assets) }}</td></tr>
                <tr><td>Liabilities</td><td class="text-right">PKR {{ money(bs.liabilities) }}</td></tr>
                <tr><td>Equity before Unclosed Earnings</td><td class="text-right">PKR {{ money(bs.equity_before_unclosed_earnings) }}</td></tr>
                <tr><td>Unclosed Earnings</td><td class="text-right">PKR {{ money(bs.unclosed_earnings) }}</td></tr>
                <tr><td>Liabilities + Equity</td><td class="text-right font-weight-bold">PKR {{ money(bs.liabilities_and_equity) }}</td></tr>
                <tr><td>Difference</td><td class="text-right" :class="bs.balanced?'success--text':'error--text'">PKR {{ money(bs.difference) }}</td></tr>
              </tbody>
            </v-simple-table>
          </div>
        </v-tab-item>

        <v-tab-item>
          <div class="pa-4">
            <v-alert type="info" text dense>
              Project result is posted revenue less posted cost-of-sales and expenses. It is not a complete economic profitability calculation where costs remain unposted or unallocated.
            </v-alert>
            <v-data-table :headers="projectHeaders" :items="projectPerformance" :items-per-page="25">
              <template v-slot:item.project="{item}">
                <div class="font-weight-medium">{{ item.project_name }}</div>
                <div class="caption grey--text">{{ item.project_code || 'No project code' }}</div>
              </template>
              <template v-slot:item.revenue="{item}">PKR {{ money(item.revenue) }}</template>
              <template v-slot:item.recorded_costs="{item}">PKR {{ money(item.recorded_costs) }}</template>
              <template v-slot:item.recorded_result="{item}">
                <strong :class="Number(item.recorded_result)>=0?'success--text':'error--text'">PKR {{ money(item.recorded_result) }}</strong>
              </template>
              <template v-slot:item.recorded_margin_percent="{item}">{{ item.recorded_margin_percent===null?'—':item.recorded_margin_percent+'%' }}</template>
            </v-data-table>
          </div>
        </v-tab-item>
      </v-tabs-items>
    </v-card>
  </div>
</template>

<script>
import api from '../../../services/api'

const StatementSection={
  props:['title','items','total'],
  methods:{
    money(v){return new Intl.NumberFormat('en-PK',{minimumFractionDigits:2,maximumFractionDigits:2}).format(Number(v||0))}
  },
  template:`
    <v-card flat outlined class="statement-section pa-3">
      <div class="subtitle-2 font-weight-bold mb-2">{{title}}</div>
      <div v-if="!items.length" class="caption grey--text">No posted balance.</div>
      <div v-for="item in items" :key="item.id" class="d-flex justify-space-between py-1">
        <span class="mr-3">{{item.code}} · {{item.name}}</span>
        <span>PKR {{money(item.amount)}}</span>
      </div>
      <v-divider class="my-2"/>
      <div class="d-flex justify-space-between font-weight-bold">
        <span>Total</span><span>PKR {{money(total)}}</span>
      </div>
    </v-card>
  `
}

export default {
  name:'ExecutiveFinance',
  components:{StatementSection},
  data(){
    const now=new Date(),offset=now.getTimezoneOffset(),today=new Date(now.getTime()-offset*60000).toISOString().slice(0,10)
    return{
      loading:false,tab:0,result:null,branches:[],projects:[],
      filters:{branch_id:null,project_id:null,from:today.slice(0,8)+'01',to:today},
      projectHeaders:[
        {text:'Project',value:'project'},{text:'Revenue',value:'revenue',align:'right'},
        {text:'Recorded Costs',value:'recorded_costs',align:'right'},{text:'Recorded Result',value:'recorded_result',align:'right'},
        {text:'Margin',value:'recorded_margin_percent',align:'right'}
      ]
    }
  },
  computed:{
    pl(){return this.result&&this.result.profit_loss||{}},
    plAccounts(){return this.pl.accounts||{}},
    bs(){return this.result&&this.result.balance_sheet||{}},
    bsAccounts(){return this.bs.accounts||{}},
    controls(){return this.result&&this.result.control_balances||{}},
    kpis(){return this.result&&this.result.kpis||{}},
    projectPerformance(){return this.result&&this.result.project_performance||[]}
  },
  async mounted(){await this.loadOptions();await this.load()},
  methods:{
    async loadOptions(){
      const r=await api.get('/accounting/executive-finance/options',{skipGlobalLoader:true})
      this.branches=r.data.branches||[]
      this.projects=(r.data.projects||[]).map(p=>Object.assign({},p,{display:(p.code?p.code+' · ':'')+p.name}))
    },
    async branchChanged(){
      this.filters.project_id=null
      await this.loadOptions()
      if(this.filters.branch_id)this.projects=this.projects.filter(p=>Number(p.branch_id)===Number(this.filters.branch_id))
    },
    async load(){
      this.loading=true
      try{
        const r=await api.get('/accounting/executive-finance/dashboard',{params:this.filters,skipGlobalLoader:true})
        this.result=r.data
      }catch(e){this.$root.$emit('show-error',this.errorText(e,'Unable to load executive finance dashboard.'))}
      finally{this.loading=false}
    },
    money(v){return new Intl.NumberFormat('en-PK',{minimumFractionDigits:2,maximumFractionDigits:2}).format(Number(v||0))},
    errorText(e,fallback){if(e.response&&e.response.data){const er=e.response.data.errors||{},k=Object.keys(er)[0];if(k&&er[k]&&er[k][0])return er[k][0];if(e.response.data.message)return e.response.data.message}return fallback}
  }
}
</script>

<style scoped>
.hero{border-left:4px solid #165134}
.filter-card,.summary-card,.control-card,.table-card,.statement-section{border:1px solid rgba(22,81,52,.08);border-radius:15px!important}
</style>
