<template>
<div class="page">
  <v-card flat class="hero pa-5 mb-4">
    <div class="d-flex flex-wrap align-center">
      <div>
        <div class="text-overline">BUSINESS INTELLIGENCE</div>
        <h1 class="text-h5 font-weight-bold">Sales & Financial Reports</h1>
        <div class="grey--text">Analyze sales, collections, receivables, installments, expenses, commissions and cash flow.</div>
      </div>
      <v-spacer/>
      <div class="d-flex flex-wrap report-actions">
        <v-btn v-if="$can('reports.view')" outlined color="#165134" :to="{name:'financial-audit'}"><v-icon left>mdi-shield-search-outline</v-icon>Audit Trail</v-btn>
        <v-menu offset-y>
          <template v-slot:activator="{on,attrs}">
            <v-btn color="#165134" dark depressed v-bind="attrs" v-on="on"><v-icon left>mdi-download</v-icon>Summary Export</v-btn>
          </template>
          <v-list dense>
            <v-list-item @click="exportSummary('pdf')"><v-list-item-icon><v-icon color="error">mdi-file-pdf-box</v-icon></v-list-item-icon><v-list-item-title>Financial Summary PDF</v-list-item-title></v-list-item>
            <v-list-item @click="exportSummary('xls')"><v-list-item-icon><v-icon color="green">mdi-microsoft-excel</v-icon></v-list-item-icon><v-list-item-title>Financial Summary Excel</v-list-item-title></v-list-item>
          </v-list>
        </v-menu>
      </div>
    </div>
  </v-card>

  <v-card flat class="filter-card pa-4 mb-4">
    <v-row dense align="center">
      <v-col cols="12" md="4">
        <v-autocomplete v-model="filters.project_id" :items="projects" item-text="name" item-value="id" outlined dense hide-details clearable label="Project" prepend-inner-icon="mdi-office-building"/>
      </v-col>
      <v-col cols="12" sm="6" md="3"><v-text-field v-model="filters.from" type="date" outlined dense hide-details label="From"/></v-col>
      <v-col cols="12" sm="6" md="3"><v-text-field v-model="filters.to" type="date" outlined dense hide-details label="To"/></v-col>
      <v-col cols="12" md="2" class="text-md-right">
        <v-btn text color="grey darken-1" @click="resetFilters"><v-icon left>mdi-filter-remove</v-icon>Reset</v-btn>
        <v-btn color="#165134" dark depressed @click="applyFilters"><v-icon left>mdi-filter</v-icon>Apply</v-btn>
      </v-col>
    </v-row>
  </v-card>

  <v-alert v-if="error" type="error" dense text>{{error}}</v-alert>

  <v-row class="mb-1">
    <v-col v-for="card in cards" :key="card.key" cols="6" sm="4" lg="3">
      <v-card flat class="metric-card fill-height pa-4">
        <div class="d-flex align-center">
          <v-avatar size="44" :class="card.softClass" class="mr-3"><v-icon :color="card.color">{{card.icon}}</v-icon></v-avatar>
          <div class="min-width-0">
            <div class="caption grey--text text-truncate">{{card.label}}</div>
            <div class="text-h6 font-weight-bold text-truncate">{{formatMetric(card)}}</div>
          </div>
        </div>
      </v-card>
    </v-col>
  </v-row>

  <v-row class="mt-1">
    <v-col cols="12" lg="8">
      <v-card flat class="section-card fill-height">
        <v-card-title><v-icon left color="#165134">mdi-chart-line</v-icon>Monthly Collections</v-card-title>
        <v-divider/>
        <v-card-text>
          <div v-if="!monthly.length" class="empty-state">No verified collections in the selected period.</div>
          <div v-else>
            <div v-for="item in monthly" :key="item.month" class="mb-4">
              <div class="d-flex justify-space-between caption mb-1"><span>{{formatMonth(item.month)}}</span><strong>PKR {{number(item.amount)}}</strong></div>
              <v-progress-linear :value="monthlyPercent(item.amount)" height="9" rounded color="#165134"/>
            </div>
          </div>
        </v-card-text>
      </v-card>
    </v-col>
    <v-col cols="12" lg="4">
      <v-card flat class="section-card fill-height">
        <v-card-title><v-icon left color="#165134">mdi-cash-flow</v-icon>Finance Snapshot</v-card-title>
        <v-divider/>
        <v-card-text>
          <div class="snapshot-row"><span>Collections</span><strong class="success--text">PKR {{number(metrics.collections)}}</strong></div>
          <div class="snapshot-row"><span>Expenses</span><strong class="error--text">PKR {{number(metrics.expenses)}}</strong></div>
          <div class="snapshot-row"><span>Net Cash Flow</span><strong :class="Number(metrics.net_cash_flow||0)>=0?'success--text':'error--text'">PKR {{number(metrics.net_cash_flow)}}</strong></div>
          <v-divider class="my-3"/>
          <div class="snapshot-row"><span>Receivables</span><strong>PKR {{number(metrics.receivables)}}</strong></div>
          <div class="snapshot-row"><span>Overdue</span><strong class="error--text">PKR {{number(metrics.overdue_amount)}}</strong></div>
          <div class="snapshot-row"><span>Commissions</span><strong>PKR {{number(metrics.commissions)}}</strong></div>
        </v-card-text>
      </v-card>
    </v-col>
  </v-row>

  <v-card flat class="report-card mt-4">
    <v-tabs v-model="tab" color="#165134" show-arrows>
      <v-tab>Sales</v-tab>
      <v-tab>Collections</v-tab>
      <v-tab>Installments</v-tab>
      <v-tab>Expenses</v-tab>
      <v-tab>Commissions</v-tab>
    </v-tabs>
    <v-divider/>

    <div class="pa-3 d-flex flex-wrap align-center">
      <div>
        <div class="font-weight-medium">{{reportTitle}}</div>
        <div class="caption grey--text">{{currentRows.length}} records loaded</div>
      </div>
      <v-spacer/>
      <v-btn small outlined color="#165134" class="mr-2" @click="exportReport('xls')"><v-icon left small>mdi-microsoft-excel</v-icon>Excel</v-btn>
      <v-btn small outlined color="error" @click="exportReport('pdf')"><v-icon left small>mdi-file-pdf-box</v-icon>PDF</v-btn>
    </div>
    <v-divider/>

    <v-tabs-items v-model="tab">
      <v-tab-item>
        <v-data-table :headers="salesHeaders" :items="sales">
          <template v-slot:item.booking_date="{item}">{{date(item.booking_date)}}</template>
          <template v-slot:item.final_price="{item}">PKR {{number(item.final_price)}}</template>
          <template v-slot:item.paid_amount="{item}">PKR {{number(item.paid_amount)}}</template>
          <template v-slot:item.remaining_amount="{item}"><strong :class="Number(item.remaining_amount)>0?'orange--text':''">PKR {{number(item.remaining_amount)}}</strong></template>
          <template v-slot:no-data><div class="empty-table">No sales found for this period.</div></template>
        </v-data-table>
      </v-tab-item>

      <v-tab-item>
        <v-data-table :headers="collectionHeaders" :items="collections">
          <template v-slot:item.payment_date="{item}">{{date(item.payment_date)}}</template>
          <template v-slot:item.payment_method="{item}">{{text(item.payment_method)}}</template>
          <template v-slot:item.amount="{item}"><strong class="success--text">PKR {{number(item.amount)}}</strong></template>
          <template v-slot:no-data><div class="empty-table">No verified collections found.</div></template>
        </v-data-table>
      </v-tab-item>

      <v-tab-item>
        <div class="pa-3 d-flex align-center">
          <v-switch v-model="overdueOnly" dense inset hide-details label="Overdue only" @change="loadInstallments"/>
          <v-spacer/><span class="caption grey--text">Overdue is determined from due date and outstanding balance.</span>
        </div>
        <v-divider/>
        <v-data-table :headers="installmentHeaders" :items="installments">
          <template v-slot:item.due_date="{item}"><span :class="isOverdue(item)?'error--text font-weight-bold':''">{{date(item.due_date)}}</span></template>
          <template v-slot:item.amount="{item}">PKR {{number(item.amount)}}</template>
          <template v-slot:item.remaining_amount="{item}"><strong :class="isOverdue(item)?'error--text':''">PKR {{number(item.remaining_amount)}}</strong></template>
          <template v-slot:item.status="{item}"><v-chip x-small dark :color="isOverdue(item)?'error':statusColor(item.status)">{{isOverdue(item)?'overdue':item.status}}</v-chip></template>
          <template v-slot:no-data><div class="empty-table">No installments found.</div></template>
        </v-data-table>
      </v-tab-item>

      <v-tab-item>
        <v-data-table :headers="expenseHeaders" :items="expenses">
          <template v-slot:item.expense_date="{item}">{{date(item.expense_date)}}</template>
          <template v-slot:item.amount="{item}"><strong class="error--text">PKR {{number(item.amount)}}</strong></template>
          <template v-slot:no-data><div class="empty-table">No expenses found.</div></template>
        </v-data-table>
      </v-tab-item>

      <v-tab-item>
        <v-data-table :headers="commissionHeaders" :items="commissions">
          <template v-slot:item.percentage="{item}">{{item.percentage}}%</template>
          <template v-slot:item.commission_amount="{item}"><strong>PKR {{number(item.commission_amount)}}</strong></template>
          <template v-slot:item.status="{item}"><v-chip x-small dark :color="commissionColor(item.status)">{{item.status}}</v-chip></template>
          <template v-slot:no-data><div class="empty-table">No commissions found.</div></template>
        </v-data-table>
      </v-tab-item>
    </v-tabs-items>
  </v-card>
</div>
</template>

<script>
import api from '../../../services/api'

export default {
  name:'Reports',
  data(){
    return{
      loading:false,exporting:'',error:'',tab:0,overdueOnly:false,metrics:{},monthly:[],
      projects:[],sales:[],collections:[],installments:[],expenses:[],commissions:[],
      filters:{project_id:null,from:new Date(new Date().getFullYear(),0,1).toISOString().slice(0,10),to:new Date().toISOString().slice(0,10)},
      cards:[
        {label:'Sales Value',key:'sales_value',money:true,icon:'mdi-cash-multiple',color:'green darken-2',softClass:'soft-green'},
        {label:'Collections',key:'collections',money:true,icon:'mdi-bank-check',color:'teal darken-2',softClass:'soft-green'},
        {label:'Receivables',key:'receivables',money:true,icon:'mdi-cash-clock',color:'orange darken-2',softClass:'soft-amber'},
        {label:'Overdue Amount',key:'overdue_amount',money:true,icon:'mdi-alert-decagram-outline',color:'red darken-2',softClass:'soft-red'},
        {label:'Expenses',key:'expenses',money:true,icon:'mdi-cash-minus',color:'purple',softClass:'soft-purple'},
        {label:'Net Cash Flow',key:'net_cash_flow',money:true,icon:'mdi-chart-areaspline',color:'#165134',softClass:'soft-green'},
        {label:'Commissions',key:'commissions',money:true,icon:'mdi-percent-outline',color:'blue',softClass:'soft-blue'},
        {label:'Sales Count',key:'sales_count',money:false,icon:'mdi-home-check-outline',color:'#165134',softClass:'soft-green'}
      ],
      salesHeaders:[
        {text:'Booking',value:'booking_number'},{text:'Customer',value:'customer.name'},{text:'Property',value:'property.property_number'},
        {text:'Project',value:'property.project.name'},{text:'Date',value:'booking_date'},{text:'Sale Value',value:'final_price',align:'right'},
        {text:'Paid',value:'paid_amount',align:'right'},{text:'Balance',value:'remaining_amount',align:'right'}
      ],
      collectionHeaders:[
        {text:'Receipt',value:'receipt_number'},{text:'Customer',value:'customer.name'},{text:'Booking',value:'booking.booking_number'},
        {text:'Date',value:'payment_date'},{text:'Method',value:'payment_method'},{text:'Amount',value:'amount',align:'right'}
      ],
      installmentHeaders:[
        {text:'#',value:'installment_number'},{text:'Customer',value:'booking.customer.name'},{text:'Property',value:'booking.property.property_number'},
        {text:'Due Date',value:'due_date'},{text:'Amount',value:'amount',align:'right'},{text:'Remaining',value:'remaining_amount',align:'right'},{text:'Status',value:'status'}
      ],
      expenseHeaders:[
        {text:'Expense #',value:'expense_number'},{text:'Branch',value:'branch.name'},{text:'Project',value:'project.name'},{text:'Category',value:'category'},
        {text:'Vendor',value:'vendor_name'},{text:'Date',value:'expense_date'},{text:'Amount',value:'amount',align:'right'}
      ],
      commissionHeaders:[
        {text:'Booking',value:'booking.booking_number'},{text:'Customer',value:'booking.customer.name'},{text:'Agent',value:'agent.name'},
        {text:'Percentage',value:'percentage'},{text:'Commission',value:'commission_amount',align:'right'},{text:'Status',value:'status'}
      ]
    }
  },
  computed:{
    reportType(){return['sales','collections','installments','expenses','commissions'][this.tab]||'sales'},
    reportTitle(){return['Sales Report','Collections Report','Installment Report','Expense Report','Commission Report'][this.tab]||'Report'},
    currentRows(){return[this.sales,this.collections,this.installments,this.expenses,this.commissions][this.tab]||[]},
    maxMonthly(){return Math.max.apply(null,this.monthly.map(function(x){return Number(x.amount||0)}).concat([1]))}
  },
  mounted(){this.loadProjects();this.loadAll()},
  methods:{
    async loadProjects(){try{const r=await api.get('/reports/projects');this.projects=r.data||[]}catch(e){this.projects=[]}},
    params(){const p={from:this.filters.from,to:this.filters.to,per_page:100};if(this.filters.project_id)p.project_id=this.filters.project_id;return p},
    async loadAll(){
      if(this.filters.from&&this.filters.to&&this.filters.from>this.filters.to){this.error='The From date cannot be later than the To date.';return}
      this.loading=true;this.error=''
      try{
        const p=this.params()
        const r=await Promise.all([
          api.get('/reports/summary',{params:p}),
          api.get('/reports/sales',{params:p}),
          api.get('/reports/collections',{params:p}),
          api.get('/reports/installments',{params:Object.assign({},p,{overdue:this.overdueOnly})}),
          api.get('/reports/expenses',{params:p}),
          api.get('/reports/commissions',{params:p})
        ])
        this.metrics=r[0].data.metrics||{}
        this.monthly=r[0].data.monthly||[]
        this.sales=r[1].data.data||[]
        this.collections=r[2].data.data||[]
        this.installments=r[3].data.data||[]
        this.expenses=r[4].data.data||[]
        this.commissions=r[5].data.data||[]
      }catch(e){
        this.error=(e.response&&e.response.data&&e.response.data.message)||'Unable to load reports.'
      }finally{this.loading=false}
    },
    applyFilters(){this.loadAll()},
    resetFilters(){this.filters={project_id:null,from:new Date(new Date().getFullYear(),0,1).toISOString().slice(0,10),to:new Date().toISOString().slice(0,10)};this.overdueOnly=false;this.loadAll()},
    async loadInstallments(){try{const r=await api.get('/reports/installments',{params:Object.assign({},this.params(),{overdue:this.overdueOnly})});this.installments=r.data.data||[]}catch(e){this.error='Unable to load installment report.'}},
    async exportSummary(format){await this.downloadExport('summary',format)},
    async exportReport(format){await this.downloadExport(this.reportType,format)},
    async downloadExport(type,format){
      this.exporting=format;this.error=''
      try{
        const p=Object.assign({},this.params(),{overdue:this.overdueOnly})
        const r=await api.get('/reports/export/'+type+'/'+format,{params:p,responseType:'blob'})
        const blob=new Blob([r.data],{type:format==='pdf'?'application/pdf':'application/vnd.ms-excel'})
        const url=window.URL.createObjectURL(blob)
        const a=document.createElement('a')
        a.href=url
        a.download=type+'-report-'+this.filters.from+'-to-'+this.filters.to+'.'+format
        document.body.appendChild(a);a.click();a.remove();window.URL.revokeObjectURL(url)
      }catch(e){this.error='Unable to export report.'}finally{this.exporting=''}
    },
    formatMetric(card){return card.money?'PKR '+this.number(this.metrics[card.key]):this.number(this.metrics[card.key])},
    number(v){return new Intl.NumberFormat('en-PK',{maximumFractionDigits:0}).format(Number(v||0))},
    date(v){return v?String(v).slice(0,10):'—'},
    text(v){return v?String(v).replace(/_/g,' ').replace(/\b\w/g,function(x){return x.toUpperCase()}):'—'},
    isOverdue(i){return i&&i.due_date&&String(i.due_date).slice(0,10)<new Date().toISOString().slice(0,10)&&Number(i.remaining_amount)>0},
    statusColor(s){return s==='paid'?'success':s==='partial'?'orange':s==='pending'?'blue':'grey'},
    commissionColor(s){return{pending:'orange',approved:'blue',paid:'success',cancelled:'grey'}[s]||'grey'},
    formatMonth(v){const p=String(v).split('-');return p.length===2?p[1]+'/'+p[0]:v},
    monthlyPercent(v){return Number(v||0)/this.maxMonthly*100}
  }
}
</script>

<style scoped>
.page{width:100%}.hero{border-left:4px solid #165134}.metric-card,.filter-card,.section-card,.report-card{border:1px solid rgba(22,81,52,.08);border-radius:15px!important}.report-actions{gap:8px}.snapshot-row{display:flex;justify-content:space-between;align-items:center;padding:9px 0}.empty-state,.empty-table{padding:28px;text-align:center;color:#888}.soft-green{background:#e8f5ee!important}.soft-amber{background:#fff5df!important}.soft-red{background:#fdecec!important}.soft-blue{background:#eaf2ff!important}.soft-purple{background:#f3eefe!important}.min-width-0{min-width:0}.page ::v-deep .v-data-table__wrapper{overflow-x:auto}
</style>