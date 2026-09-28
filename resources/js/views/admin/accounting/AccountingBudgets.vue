<template>
  <div class="accounting-budget-page">
    <v-card flat class="hero pa-5 mb-4">
      <div class="d-flex align-center flex-wrap">
        <div>
          <div class="text-overline">PLANNING & CONTROL</div>
          <h1 class="text-h5 font-weight-bold mb-1">Accounting Budgets</h1>
          <div class="grey--text">Fiscal-year budgets with monthly allocations and ledger-backed budget-vs-actual analysis.</div>
        </div>
        <v-spacer/>
        <v-btn v-if="$can('accounting.create')" color="#165134" dark depressed @click="openCreate">
          <v-icon left>mdi-plus-circle-outline</v-icon>New Budget
        </v-btn>
      </div>
    </v-card>

    <v-card flat class="filter-card pa-4 mb-4">
      <v-row dense>
        <v-col cols="12" md="3">
          <v-select v-model="filters.fiscal_year_id" :items="years" item-text="name" item-value="id" clearable outlined dense hide-details label="Fiscal Year"/>
        </v-col>
        <v-col v-if="branches.length" cols="12" md="3">
          <v-select v-model="filters.branch_id" :items="branches" item-text="name" item-value="id" clearable outlined dense hide-details label="Branch"/>
        </v-col>
        <v-col cols="12" md="3">
          <v-autocomplete v-model="filters.project_id" :items="projects" item-text="display" item-value="id" clearable outlined dense hide-details label="Project"/>
        </v-col>
        <v-col cols="12" md="2">
          <v-select v-model="filters.status" :items="statusOptions" clearable outlined dense hide-details label="Status"/>
        </v-col>
        <v-col cols="12" md="1">
          <v-btn block color="#165134" dark depressed :loading="loading" @click="loadBudgets">Apply</v-btn>
        </v-col>
      </v-row>
    </v-card>

    <v-card flat class="table-card">
      <v-data-table
        :headers="headers"
        :items="items"
        :loading="loading"
        :server-items-length="total"
        :page.sync="page"
        :items-per-page.sync="perPage"
        :footer-props="{'items-per-page-options':[10,25,50]}"
        @update:page="loadBudgets"
      >
        <template v-slot:item.name="{item}">
          <div class="font-weight-medium">{{ item.name }}</div>
          <div class="caption grey--text">{{ item.fiscal_year ? item.fiscal_year.name : '—' }}</div>
        </template>
        <template v-slot:item.scope="{item}">
          <div>{{ item.branch ? item.branch.name : 'All / Unassigned' }}</div>
          <div class="caption grey--text">{{ item.project ? item.project.name : 'No project restriction' }}</div>
        </template>
        <template v-slot:item.budget_total="{item}"><strong>PKR {{ money(item.budget_total) }}</strong></template>
        <template v-slot:item.status="{item}">
          <v-chip x-small :color="item.status==='approved'?'success':'warning'" dark>{{ item.status }}</v-chip>
        </template>
        <template v-slot:item.actions="{item}">
          <v-btn icon small color="#165134" title="Budget vs actual" @click="openVariance(item)"><v-icon small>mdi-chart-box-outline</v-icon></v-btn>
          <v-btn v-if="$can('accounting.edit') && item.status==='draft'" icon small title="Edit" @click="openEdit(item)"><v-icon small>mdi-pencil-outline</v-icon></v-btn>
          <v-btn v-if="$can('accounting.edit') && item.status==='draft'" icon small color="success" title="Approve & lock" @click="approve(item)"><v-icon small>mdi-lock-check-outline</v-icon></v-btn>
        </template>
      </v-data-table>
    </v-card>

    <v-dialog v-model="dialog" max-width="1100" persistent scrollable>
      <v-card>
        <v-card-title>{{ form.id ? 'Edit Budget' : 'New Budget' }}</v-card-title>
        <v-divider/>
        <v-card-text class="pt-4">
          <v-row>
            <v-col cols="12" md="3">
              <v-select
                v-model="form.fiscal_year_id"
                :items="years"
                item-text="name"
                item-value="id"
                outlined dense
                label="Fiscal Year *"
                @change="yearChanged"
              />
            </v-col>
            <v-col v-if="branches.length" cols="12" md="3">
              <v-select
                v-model="form.branch_id"
                :items="branches"
                item-text="name"
                item-value="id"
                outlined dense
                label="Branch *"
                @change="branchChanged"
              />
            </v-col>
            <v-col cols="12" md="3">
              <v-autocomplete
                v-model="form.project_id"
                :items="projectsForForm"
                item-text="display"
                item-value="id"
                outlined dense clearable
                label="Project"
              />
            </v-col>
            <v-col cols="12" md="3">
              <v-text-field v-model="form.name" outlined dense label="Budget Name *"/>
            </v-col>
            <v-col cols="12">
              <v-textarea v-model="form.notes" outlined dense rows="2" label="Notes"/>
            </v-col>
          </v-row>

          <div class="d-flex align-center mb-2">
            <div class="subtitle-2 font-weight-bold">Budget Lines</div>
            <v-spacer/>
            <v-btn small text color="#165134" @click="addLine"><v-icon left small>mdi-plus</v-icon>Add Line</v-btn>
          </div>

          <v-card v-for="(line,index) in form.lines" :key="index" flat outlined class="pa-3 mb-2">
            <v-row dense align="center">
              <v-col cols="12" md="3">
                <v-select
                  v-model="line.accounting_period_id"
                  :items="periodsForForm"
                  item-text="name"
                  item-value="id"
                  outlined dense hide-details
                  label="Period *"
                />
              </v-col>
              <v-col cols="12" md="4">
                <v-autocomplete
                  v-model="line.chart_of_account_id"
                  :items="accounts"
                  item-text="display"
                  item-value="id"
                  outlined dense hide-details
                  label="P&L Account *"
                />
              </v-col>
              <v-col cols="10" md="2">
                <v-text-field v-model.number="line.amount" type="number" min="0" step="0.01" outlined dense hide-details label="Amount *"/>
              </v-col>
              <v-col cols="12" md="2">
                <v-text-field v-model="line.notes" outlined dense hide-details label="Notes"/>
              </v-col>
              <v-col cols="2" md="1" class="text-right">
                <v-btn icon small color="error" :disabled="form.lines.length===1" @click="form.lines.splice(index,1)"><v-icon small>mdi-delete-outline</v-icon></v-btn>
              </v-col>
            </v-row>
          </v-card>

          <div class="text-right subtitle-1 font-weight-bold">Total Budget: PKR {{ money(formTotal) }}</div>
        </v-card-text>
        <v-divider/>
        <v-card-actions>
          <v-spacer/>
          <v-btn text @click="dialog=false">Cancel</v-btn>
          <v-btn color="#165134" dark depressed :loading="saving" @click="save">Save Draft</v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>

    <v-dialog v-model="varianceDialog" max-width="1200" scrollable>
      <v-card>
        <v-card-title>
          <div>
            <div class="text-h6 font-weight-bold">{{ varianceBudget ? varianceBudget.name : 'Budget Variance' }}</div>
            <div class="caption grey--text">Positive variance = favorable</div>
          </div>
          <v-spacer/>
          <v-btn icon @click="varianceDialog=false"><v-icon>mdi-close</v-icon></v-btn>
        </v-card-title>
        <v-divider/>
        <v-card-text class="pt-4">
          <v-row dense class="mb-2">
            <v-col cols="12" md="4">
              <v-select
                v-model="throughPeriodId"
                :items="variancePeriods"
                item-text="name"
                item-value="id"
                outlined dense hide-details
                label="Through Period"
                @change="loadVariance"
              />
            </v-col>
          </v-row>

          <v-row class="mb-2">
            <v-col cols="6" md="2"><v-card flat class="summary-card pa-3"><div class="caption grey--text">Budget Revenue</div><strong>PKR {{ money(varianceSummary.budget_revenue) }}</strong></v-card></v-col>
            <v-col cols="6" md="2"><v-card flat class="summary-card pa-3"><div class="caption grey--text">Actual Revenue</div><strong>PKR {{ money(varianceSummary.actual_revenue) }}</strong></v-card></v-col>
            <v-col cols="6" md="2"><v-card flat class="summary-card pa-3"><div class="caption grey--text">Budget Costs</div><strong>PKR {{ money(varianceSummary.budget_costs) }}</strong></v-card></v-col>
            <v-col cols="6" md="2"><v-card flat class="summary-card pa-3"><div class="caption grey--text">Actual Costs</div><strong>PKR {{ money(varianceSummary.actual_costs) }}</strong></v-card></v-col>
            <v-col cols="6" md="2"><v-card flat class="summary-card pa-3"><div class="caption grey--text">Budget Result</div><strong>PKR {{ money(varianceSummary.budget_result) }}</strong></v-card></v-col>
            <v-col cols="6" md="2"><v-card flat class="summary-card pa-3"><div class="caption grey--text">Actual Result</div><strong>PKR {{ money(varianceSummary.actual_result) }}</strong></v-card></v-col>
          </v-row>

          <v-tabs v-model="varianceTab" color="#165134">
            <v-tab>By Account</v-tab>
            <v-tab>By Period</v-tab>
          </v-tabs>

          <v-tabs-items v-model="varianceTab">
            <v-tab-item>
              <v-data-table :headers="varianceHeaders" :items="varianceAccounts" :loading="varianceLoading" :items-per-page="25">
                <template v-slot:item.account="{item}">
                  <div class="font-weight-medium">{{ item.code }} · {{ item.name }}</div>
                  <div class="caption grey--text">{{ item.account_type }}</div>
                </template>
                <template v-slot:item.budget="{item}">PKR {{ money(item.budget) }}</template>
                <template v-slot:item.actual="{item}">PKR {{ money(item.actual) }}</template>
                <template v-slot:item.variance="{item}">
                  <strong :class="item.favorable?'success--text':'error--text'">PKR {{ money(item.variance) }}</strong>
                </template>
                <template v-slot:item.variance_percent="{item}">{{ item.variance_percent===null?'—':item.variance_percent+'%' }}</template>
              </v-data-table>
            </v-tab-item>

            <v-tab-item>
              <v-data-table :headers="periodHeaders" :items="varianceByPeriod" :loading="varianceLoading" :items-per-page="-1" hide-default-footer>
                <template v-slot:item.budget_revenue="{item}">PKR {{ money(item.budget_revenue) }}</template>
                <template v-slot:item.actual_revenue="{item}">PKR {{ money(item.actual_revenue) }}</template>
                <template v-slot:item.budget_costs="{item}">PKR {{ money(item.budget_costs) }}</template>
                <template v-slot:item.actual_costs="{item}">PKR {{ money(item.actual_costs) }}</template>
                <template v-slot:item.budget_result="{item}">PKR {{ money(item.budget_result) }}</template>
                <template v-slot:item.actual_result="{item}">PKR {{ money(item.actual_result) }}</template>
              </v-data-table>
            </v-tab-item>
          </v-tabs-items>
        </v-card-text>
      </v-card>
    </v-dialog>
  </div>
</template>

<script>
import api from '../../../services/api'

export default {
  name:'AccountingBudgets',
  data(){
    return{
      loading:false,saving:false,varianceLoading:false,dialog:false,varianceDialog:false,
      items:[],total:0,page:1,perPage:25,
      years:[],branches:[],projects:[],accounts:[],
      filters:{fiscal_year_id:null,branch_id:null,project_id:null,status:null},
      statusOptions:['draft','approved'],
      form:{},
      varianceBudget:null,throughPeriodId:null,variancePeriods:[],varianceSummary:{},varianceAccounts:[],varianceByPeriod:[],varianceTab:0,
      headers:[
        {text:'Budget',value:'name'},{text:'Scope',value:'scope'},{text:'Total',value:'budget_total',align:'right'},
        {text:'Status',value:'status'},{text:'Approved By',value:'approved_by.name'},{text:'',value:'actions',sortable:false}
      ],
      varianceHeaders:[
        {text:'Account',value:'account'},{text:'Budget',value:'budget',align:'right'},{text:'Actual',value:'actual',align:'right'},
        {text:'Variance',value:'variance',align:'right'},{text:'Variance %',value:'variance_percent',align:'right'}
      ],
      periodHeaders:[
        {text:'Period',value:'period_name'},{text:'Budget Revenue',value:'budget_revenue',align:'right'},{text:'Actual Revenue',value:'actual_revenue',align:'right'},
        {text:'Budget Costs',value:'budget_costs',align:'right'},{text:'Actual Costs',value:'actual_costs',align:'right'},
        {text:'Budget Result',value:'budget_result',align:'right'},{text:'Actual Result',value:'actual_result',align:'right'}
      ]
    }
  },
  computed:{
    formTotal(){return (this.form.lines||[]).reduce((sum,line)=>sum+Number(line.amount||0),0)},
    periodsForForm(){
      const year=this.years.find(y=>Number(y.id)===Number(this.form.fiscal_year_id))
      return year&&year.periods?year.periods:[]
    },
    projectsForForm(){
      if(!this.form.branch_id)return this.projects
      return this.projects.filter(p=>Number(p.branch_id)===Number(this.form.branch_id))
    }
  },
  async mounted(){await this.loadOptions();await this.loadBudgets()},
  methods:{
    async loadOptions(){
      const r=await api.get('/accounting/budgets/options',{skipGlobalLoader:true})
      this.years=r.data.fiscal_years||[]
      this.branches=r.data.branches||[]
      this.projects=(r.data.projects||[]).map(p=>Object.assign({},p,{display:(p.code?p.code+' · ':'')+p.name}))
      this.accounts=(r.data.accounts||[]).map(a=>Object.assign({},a,{display:a.code+' · '+a.name+' ('+a.account_type+')'}))
    },
    async loadBudgets(){
      this.loading=true
      try{
        const r=await api.get('/accounting/budgets',{params:{...this.filters,page:this.page,per_page:this.perPage},skipGlobalLoader:true})
        this.items=r.data.data||[];this.total=Number(r.data.total||0)
      }finally{this.loading=false}
    },
    blankLine(){return{accounting_period_id:null,chart_of_account_id:null,amount:null,notes:''}},
    blank(){return{id:null,fiscal_year_id:this.filters.fiscal_year_id||null,branch_id:this.filters.branch_id||null,project_id:this.filters.project_id||null,name:'',notes:'',lines:[this.blankLine()]}},
    openCreate(){this.form=this.blank();this.dialog=true},
    yearChanged(){
      const valid=new Set(this.periodsForForm.map(p=>Number(p.id)))
      this.form.lines=(this.form.lines||[]).map(line=>Object.assign({},line,{accounting_period_id:valid.has(Number(line.accounting_period_id))?line.accounting_period_id:null}))
    },
    branchChanged(){if(this.form.project_id&&!this.projectsForForm.some(p=>Number(p.id)===Number(this.form.project_id)))this.form.project_id=null},
    addLine(){this.form.lines.push(this.blankLine())},
    async openEdit(item){
      try{
        const r=await api.get('/accounting/budgets/'+item.id,{skipGlobalLoader:true})
        const b=r.data
        this.form={id:b.id,fiscal_year_id:b.fiscal_year_id,branch_id:b.branch_id,project_id:b.project_id,name:b.name,notes:b.notes||'',lines:(b.lines||[]).map(line=>({accounting_period_id:line.accounting_period_id,chart_of_account_id:line.chart_of_account_id,amount:Number(line.amount),notes:line.notes||''}))}
        this.dialog=true
      }catch(e){this.$root.$emit('show-error',this.errorText(e,'Unable to load budget.'))}
    },
    async save(){
      this.saving=true
      try{
        const payload={fiscal_year_id:this.form.fiscal_year_id,branch_id:this.form.branch_id,project_id:this.form.project_id,name:this.form.name,notes:this.form.notes,lines:this.form.lines}
        if(this.form.id)await api.put('/accounting/budgets/'+this.form.id,payload,{skipGlobalError:true})
        else await api.post('/accounting/budgets',payload,{skipGlobalError:true})
        this.dialog=false;await this.loadBudgets()
      }catch(e){this.$root.$emit('show-error',this.errorText(e,'Unable to save budget.'))}
      finally{this.saving=false}
    },
    async approve(item){
      try{
        await api.post('/accounting/budgets/'+item.id+'/approve',{}, {skipGlobalError:true})
        await this.loadBudgets()
      }catch(e){this.$root.$emit('show-error',this.errorText(e,'Unable to approve budget.'))}
    },
    async openVariance(item){
      this.varianceBudget=item;this.varianceDialog=true;this.varianceLoading=true
      try{
        const r=await api.get('/accounting/budgets/'+item.id,{skipGlobalLoader:true})
        const year=r.data.fiscal_year
        this.variancePeriods=year&&year.periods?year.periods:[]
        this.throughPeriodId=this.variancePeriods.length?this.variancePeriods[this.variancePeriods.length-1].id:null
        await this.loadVariance()
      }catch(e){this.$root.$emit('show-error',this.errorText(e,'Unable to load budget variance.'))}
      finally{this.varianceLoading=false}
    },
    async loadVariance(){
      if(!this.varianceBudget)return
      this.varianceLoading=true
      try{
        const r=await api.get('/accounting/budgets/'+this.varianceBudget.id+'/variance',{params:{through_period_id:this.throughPeriodId||undefined},skipGlobalLoader:true})
        this.varianceSummary=r.data.summary||{}
        this.varianceAccounts=r.data.accounts||[]
        this.varianceByPeriod=r.data.periods||[]
      }catch(e){this.$root.$emit('show-error',this.errorText(e,'Unable to load budget variance.'))}
      finally{this.varianceLoading=false}
    },
    money(v){return new Intl.NumberFormat('en-PK',{minimumFractionDigits:2,maximumFractionDigits:2}).format(Number(v||0))},
    errorText(e,fallback){if(e.response&&e.response.data){const er=e.response.data.errors||{},k=Object.keys(er)[0];if(k&&er[k]&&er[k][0])return er[k][0];if(e.response.data.message)return e.response.data.message}return fallback}
  }
}
</script>

<style scoped>
.hero{border-left:4px solid #165134}
.filter-card,.table-card,.summary-card{border:1px solid rgba(22,81,52,.08);border-radius:15px!important}
</style>
