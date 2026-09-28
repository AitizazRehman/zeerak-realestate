<template>
  <div class="ap-control-page">
    <v-card flat class="hero pa-5 mb-4">
      <div class="d-flex align-center flex-wrap">
        <div>
          <div class="text-overline">AP CONTROL CENTER</div>
          <h1 class="text-h5 font-weight-bold mb-1">Vendor Statements, Reconciliation & Cash Forecast</h1>
          <div class="grey--text">Reconcile the vendor subledger to 2100 Accounts Payable and plan upcoming vendor cash requirements.</div>
        </div>
        <v-spacer/>
        <v-btn text color="#165134" to="/admin/accounting/accounts-payable">
          <v-icon left>mdi-arrow-left</v-icon>Accounts Payable
        </v-btn>
      </div>
    </v-card>

    <v-card flat class="filter-card pa-4 mb-4">
      <v-row dense>
        <v-col v-if="branches.length" cols="12" md="3">
          <v-select
            v-model="filters.branch_id"
            :items="branches"
            item-text="name"
            item-value="id"
            outlined dense hide-details clearable
            label="Branch"
            @change="branchChanged"
          />
        </v-col>
        <v-col cols="12" md="3">
          <v-autocomplete
            v-model="filters.vendor_id"
            :items="vendors"
            item-text="display"
            item-value="id"
            outlined dense hide-details clearable
            label="Vendor"
          />
        </v-col>
        <v-col cols="12" md="3">
          <v-autocomplete
            v-model="filters.project_id"
            :items="projects"
            item-text="display"
            item-value="id"
            outlined dense hide-details clearable
            label="Project"
          />
        </v-col>
        <v-col cols="12" md="2">
          <v-text-field v-model="filters.as_of" type="date" outlined dense hide-details label="As of"/>
        </v-col>
        <v-col cols="12" md="1">
          <v-btn block color="#165134" dark depressed :loading="loading" @click="refresh">Apply</v-btn>
        </v-col>
      </v-row>
    </v-card>

    <v-row class="mb-1">
      <v-col cols="6" md="3">
        <v-card flat class="summary-card pa-4">
          <div class="caption grey--text">Vendor Subledger</div>
          <div class="text-h6 font-weight-bold">PKR {{ money(reconciliation.subledger_balance) }}</div>
          <div class="caption grey--text">{{ reconciliation.bill_count || 0 }} open bill(s)</div>
        </v-card>
      </v-col>
      <v-col cols="6" md="3">
        <v-card flat class="summary-card pa-4">
          <div class="caption grey--text">Managed GL 2100</div>
          <div class="text-h6 font-weight-bold">PKR {{ money(reconciliation.managed_gl_balance) }}</div>
          <div class="caption" :class="reconciliation.managed_balanced ? 'success--text' : 'error--text'">
            Difference PKR {{ money(reconciliation.managed_difference) }}
          </div>
        </v-card>
      </v-col>
      <v-col cols="6" md="3">
        <v-card flat class="summary-card pa-4">
          <div class="caption grey--text">Overdue Payables</div>
          <div class="text-h6 font-weight-bold error--text">PKR {{ money(forecast.overdue) }}</div>
          <div class="caption grey--text">Past due at {{ filters.as_of }}</div>
        </v-card>
      </v-col>
      <v-col cols="6" md="3">
        <v-card flat class="summary-card pa-4">
          <div class="caption grey--text">Due Next 7 Days</div>
          <div class="text-h6 font-weight-bold warning--text">PKR {{ money(next7Total) }}</div>
          <div class="caption grey--text">Today + next 7 days</div>
        </v-card>
      </v-col>
    </v-row>

    <v-alert
      v-if="reconciliation.managed_balanced"
      type="success"
      text
      dense
      class="mb-4"
    >
      Vendor subledger agrees with AP journal activity in this scope as of {{ filters.as_of }}.
    </v-alert>
    <v-alert v-else type="error" text dense class="mb-4">
      AP reconciliation difference detected: PKR {{ money(reconciliation.managed_difference) }}. Review missing, reversed, or manually altered postings.
    </v-alert>

    <v-alert v-if="reconciliation.scope_is_global && reconciliation.total_gl_balance !== null" type="info" text dense class="mb-4">
      Full 2100 GL balance: <strong>PKR {{ money(reconciliation.total_gl_balance) }}</strong>.
      AP-managed activity: <strong>PKR {{ money(reconciliation.managed_gl_balance) }}</strong>.
      Other 2100 activity: <strong>PKR {{ money(reconciliation.other_gl_activity) }}</strong>.
    </v-alert>

    <v-card flat class="table-card">
      <v-tabs v-model="tab" color="#165134">
        <v-tab>Cash Forecast</v-tab>
        <v-tab>Vendor Statement</v-tab>
      </v-tabs>
      <v-divider/>

      <v-tabs-items v-model="tab">
        <v-tab-item>
          <div class="pa-4">
            <v-row dense class="mb-3">
              <v-col v-for="bucket in forecastCards" :key="bucket.key" cols="6" md="2">
                <div class="forecast-box pa-3">
                  <div class="caption grey--text">{{ bucket.label }}</div>
                  <div class="font-weight-bold">PKR {{ money(bucket.amount) }}</div>
                </div>
              </v-col>
            </v-row>

            <div class="subtitle-2 font-weight-bold mb-2">Vendor Cash Requirement</div>
            <v-data-table :headers="vendorHeaders" :items="forecastVendors" :loading="loading" :items-per-page="25">
              <template v-slot:item.vendor="{item}">
                <div class="font-weight-medium">{{ item.vendor_name }}</div>
                <div class="caption grey--text">{{ item.vendor_number }}</div>
              </template>
              <template v-slot:item.total="{item}"><strong>PKR {{ money(item.total) }}</strong></template>
              <template v-slot:item.overdue="{item}"><span :class="Number(item.overdue)>0?'error--text font-weight-bold':''">PKR {{ money(item.overdue) }}</span></template>
              <template v-slot:item.next_7="{item}">PKR {{ money(Number(item.due_today||0)+Number(item.next_7||0)) }}</template>
              <template v-slot:item.days_8_30="{item}">PKR {{ money(item.days_8_30) }}</template>
              <template v-slot:item.days_31_60="{item}">PKR {{ money(item.days_31_60) }}</template>
              <template v-slot:item.days_61_90="{item}">PKR {{ money(item.days_61_90) }}</template>
              <template v-slot:item.later="{item}">PKR {{ money(item.later) }}</template>
              <template v-slot:item.next_due_date="{item}">{{ dateLabel(item.next_due_date) }}</template>
            </v-data-table>

            <div class="subtitle-2 font-weight-bold mt-5 mb-2">Bill-Level Forecast</div>
            <v-data-table :headers="billHeaders" :items="forecastBills" :loading="loading" :items-per-page="25">
              <template v-slot:item.bill="{item}">
                <div class="font-weight-medium">{{ item.bill_number }}</div>
                <div class="caption grey--text">{{ item.vendor_invoice_number || 'No invoice #' }}</div>
              </template>
              <template v-slot:item.vendor="{item}">{{ item.vendor_name }}</template>
              <template v-slot:item.project="{item}">{{ item.project_name || 'General / Unassigned' }}</template>
              <template v-slot:item.balance="{item}"><strong>PKR {{ money(item.balance) }}</strong></template>
              <template v-slot:item.due_date="{item}">
                <div>{{ dateLabel(item.due_date) }}</div>
                <div class="caption" :class="item.days_to_due<0?'error--text':'grey--text'">{{ dueText(item) }}</div>
              </template>
              <template v-slot:item.forecast_bucket="{item}">
                <v-chip x-small :color="forecastColor(item.forecast_bucket)" dark>{{ forecastLabel(item.forecast_bucket) }}</v-chip>
              </template>
            </v-data-table>
          </div>
        </v-tab-item>

        <v-tab-item>
          <div class="pa-4">
            <v-row dense>
              <v-col cols="12" md="4">
                <v-autocomplete
                  v-model="statement.vendor_id"
                  :items="vendors"
                  item-text="display"
                  item-value="id"
                  outlined dense
                  label="Vendor *"
                />
              </v-col>
              <v-col cols="6" md="3"><v-text-field v-model="statement.from" type="date" outlined dense label="From *"/></v-col>
              <v-col cols="6" md="3"><v-text-field v-model="statement.to" type="date" outlined dense label="To *"/></v-col>
              <v-col cols="12" md="2"><v-btn block color="#165134" dark depressed :loading="statementLoading" @click="loadStatement">View Statement</v-btn></v-col>
            </v-row>

            <v-alert v-if="statementError" type="error" text dense>{{ statementError }}</v-alert>

            <template v-if="statementResult">
              <div class="d-flex flex-wrap statement-summary pa-3 mb-3">
                <span>Opening: <strong>PKR {{ money(statementResult.summary.opening) }}</strong></span>
                <span>Debits: <strong>PKR {{ money(statementResult.summary.debit) }}</strong></span>
                <span>Credits: <strong>PKR {{ money(statementResult.summary.credit) }}</strong></span>
                <span>Closing Payable: <strong>PKR {{ money(statementResult.summary.closing) }}</strong></span>
              </div>

              <v-data-table :headers="statementHeaders" :items="statementResult.entries || []" :items-per-page="50">
                <template v-slot:item.date="{item}">{{ dateLabel(item.date) }}</template>
                <template v-slot:item.type="{item}">{{ statementType(item.type) }}</template>
                <template v-slot:item.debit="{item}">PKR {{ money(item.debit) }}</template>
                <template v-slot:item.credit="{item}">PKR {{ money(item.credit) }}</template>
                <template v-slot:item.balance="{item}"><strong>PKR {{ money(item.balance) }}</strong></template>
              </v-data-table>
            </template>
          </div>
        </v-tab-item>
      </v-tabs-items>
    </v-card>
  </div>
</template>

<script>
import api from '../../../services/api'

export default {
  name:'AccountsPayableControl',

  data(){
    const today=this.localToday()
    const first=today.slice(0,8)+'01'
    return{
      tab:0,
      loading:false,
      statementLoading:false,
      statementError:'',
      branches:[],
      vendors:[],
      projects:[],
      reconciliation:{},
      forecast:{},
      forecastVendors:[],
      forecastBills:[],
      statementResult:null,
      filters:{branch_id:null,vendor_id:null,project_id:null,as_of:today},
      statement:{vendor_id:null,from:first,to:today},
      vendorHeaders:[
        {text:'Vendor',value:'vendor'},
        {text:'Bills',value:'bill_count'},
        {text:'Total',value:'total',align:'right'},
        {text:'Overdue',value:'overdue',align:'right'},
        {text:'Due ≤7d',value:'next_7',align:'right'},
        {text:'8–30d',value:'days_8_30',align:'right'},
        {text:'31–60d',value:'days_31_60',align:'right'},
        {text:'61–90d',value:'days_61_90',align:'right'},
        {text:'Later',value:'later',align:'right'},
        {text:'Next Due',value:'next_due_date'}
      ],
      billHeaders:[
        {text:'Bill',value:'bill'},
        {text:'Vendor',value:'vendor'},
        {text:'Project',value:'project'},
        {text:'Due',value:'due_date'},
        {text:'Bucket',value:'forecast_bucket'},
        {text:'Outstanding',value:'balance',align:'right'}
      ],
      statementHeaders:[
        {text:'Date',value:'date'},
        {text:'Type',value:'type'},
        {text:'Reference',value:'reference'},
        {text:'Project',value:'project_name'},
        {text:'Description',value:'description'},
        {text:'Debit',value:'debit',align:'right'},
        {text:'Credit',value:'credit',align:'right'},
        {text:'Balance',value:'balance',align:'right'}
      ]
    }
  },

  computed:{
    next7Total(){
      return Number(this.forecast.due_today||0)+Number(this.forecast.next_7||0)
    },
    forecastCards(){
      return[
        {key:'overdue',label:'Overdue',amount:this.forecast.overdue},
        {key:'due_today',label:'Due Today',amount:this.forecast.due_today},
        {key:'next_7',label:'Next 7 Days',amount:this.forecast.next_7},
        {key:'days_8_30',label:'8–30 Days',amount:this.forecast.days_8_30},
        {key:'days_31_60',label:'31–60 Days',amount:this.forecast.days_31_60},
        {key:'days_61_90',label:'61–90 Days',amount:this.forecast.days_61_90}
      ]
    }
  },

  async mounted(){
    await this.loadOptions()
    await this.refresh()
  },

  methods:{
    localToday(){
      const d=new Date(),offset=d.getTimezoneOffset()
      return new Date(d.getTime()-offset*60000).toISOString().slice(0,10)
    },

    async loadOptions(branchId){
      const selected=branchId===undefined?this.filters.branch_id:branchId
      try{
        const r=await api.get('/accounting/accounts-payable/options',{
          params:{branch_id:selected||undefined},
          skipGlobalLoader:true
        })
        this.branches=r.data.branches||this.branches
        this.vendors=(r.data.vendors||[]).map(x=>Object.assign({},x,{display:x.vendor_number+' · '+x.name}))
        this.projects=(r.data.projects||[]).map(x=>Object.assign({},x,{display:(x.code?x.code+' · ':'')+x.name}))
      }catch(e){
        this.$root.$emit('show-error',this.errorText(e,'Unable to load AP control options.'))
      }
    },

    async branchChanged(){
      this.filters.vendor_id=null
      this.filters.project_id=null
      this.statement.vendor_id=null
      this.statementResult=null
      await this.loadOptions(this.filters.branch_id)
    },

    async refresh(){
      this.loading=true
      const params={
        as_of:this.filters.as_of,
        branch_id:this.filters.branch_id||undefined,
        vendor_id:this.filters.vendor_id||undefined,
        project_id:this.filters.project_id||undefined
      }

      try{
        const results=await Promise.all([
          api.get('/accounting/accounts-payable-control/reconciliation',{params,skipGlobalLoader:true}),
          api.get('/accounting/accounts-payable-control/forecast',{params,skipGlobalLoader:true})
        ])
        this.reconciliation=results[0].data||{}
        this.forecast=results[1].data.summary||{}
        this.forecastVendors=results[1].data.vendors||[]
        this.forecastBills=results[1].data.bills||[]
      }catch(e){
        this.$root.$emit('show-error',this.errorText(e,'Unable to load AP control reports.'))
      }finally{
        this.loading=false
      }
    },

    async loadStatement(){
      this.statementError=''
      this.statementResult=null

      if(!this.statement.vendor_id || !this.statement.from || !this.statement.to || this.statement.from>this.statement.to){
        this.statementError='Select a vendor and a valid date range.'
        return
      }

      this.statementLoading=true
      try{
        const r=await api.get('/accounting/accounts-payable-control/vendor-statement',{
          params:{
            vendor_id:this.statement.vendor_id,
            from:this.statement.from,
            to:this.statement.to,
            branch_id:this.filters.branch_id||undefined,
            project_id:this.filters.project_id||undefined
          },
          skipGlobalLoader:true
        })
        this.statementResult=r.data
      }catch(e){
        this.statementError=this.errorText(e,'Unable to load vendor statement.')
      }finally{
        this.statementLoading=false
      }
    },

    statementType(type){
      const labels={bill:'Bill',payment:'Payment',payment_reversal:'Payment Reversal',bill_cancellation:'Bill Cancellation'}
      return labels[type]||type
    },

    forecastLabel(value){
      const labels={overdue:'Overdue',due_today:'Due Today',next_7:'Next 7 Days',days_8_30:'8–30 Days',days_31_60:'31–60 Days',days_61_90:'61–90 Days',later:'Later'}
      return labels[value]||value
    },

    forecastColor(value){
      if(value==='overdue')return'error'
      if(value==='due_today'||value==='next_7')return'warning'
      if(value==='later')return'grey'
      return'info'
    },

    dueText(item){
      const n=Number(item.days_to_due||0)
      if(n<0)return Math.abs(n)+' days overdue'
      if(n===0)return'Due today'
      return'Due in '+n+' days'
    },

    money(value){
      return new Intl.NumberFormat('en-PK',{minimumFractionDigits:2,maximumFractionDigits:2}).format(Number(value||0))
    },

    dateLabel(value){
      if(!value)return'—'
      const parts=String(value).slice(0,10).split('-')
      if(parts.length!==3)return value
      return new Intl.DateTimeFormat('en-PK',{day:'2-digit',month:'short',year:'numeric'}).format(new Date(Number(parts[0]),Number(parts[1])-1,Number(parts[2])))
    },

    errorText(e,fallback){
      if(e.response&&e.response.data){
        const errors=e.response.data.errors||{}
        const key=Object.keys(errors)[0]
        if(key&&errors[key]&&errors[key][0])return errors[key][0]
        if(e.response.data.message)return e.response.data.message
      }
      return fallback
    }
  }
}
</script>

<style scoped>
.hero{border-left:4px solid #165134}
.filter-card,.summary-card,.table-card,.forecast-box{border:1px solid rgba(22,81,52,.08);border-radius:15px!important}
.statement-summary{gap:16px 28px;background:rgba(22,81,52,.04);border-radius:12px}
</style>
