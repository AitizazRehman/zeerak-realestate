<template>
  <div class="page">
    <v-card flat class="hero pa-5 mb-4">
      <div class="d-flex flex-wrap align-center">
        <div>
          <div class="text-overline">FINANCIAL CONTROL</div>
          <h1 class="text-h5 font-weight-bold">Financial Audit Trail</h1>
          <div class="grey--text">Review protected financial changes across payments, bookings, installment plans, commissions, expenses and uploaded documents.</div>
        </div>
        <v-spacer/>
        <div class="d-flex flex-wrap audit-actions">
          <v-btn outlined color="#165134" :to="{name:'reports'}"><v-icon left>mdi-file-chart-outline</v-icon>Reports</v-btn>
          <v-menu offset-y>
            <template v-slot:activator="{on,attrs}">
              <v-btn color="#165134" dark depressed v-bind="attrs" v-on="on"><v-icon left>mdi-download</v-icon>Export</v-btn>
            </template>
            <v-list dense>
              <v-list-item @click="exportAudit('pdf')"><v-list-item-icon><v-icon color="error">mdi-file-pdf-box</v-icon></v-list-item-icon><v-list-item-title>PDF</v-list-item-title></v-list-item>
              <v-list-item @click="exportAudit('xls')"><v-list-item-icon><v-icon color="green">mdi-microsoft-excel</v-icon></v-list-item-icon><v-list-item-title>Excel</v-list-item-title></v-list-item>
            </v-list>
          </v-menu>
        </div>
      </div>
    </v-card>

    <v-row class="mb-1">
      <v-col cols="6" md="3"><v-card flat class="summary-card pa-4"><div class="caption grey--text">Total Matching</div><div class="text-h6 font-weight-bold">{{total}}</div></v-card></v-col>
      <v-col cols="6" md="3"><v-card flat class="summary-card pa-4"><div class="caption grey--text">Reversal Events Shown</div><div class="text-h6 font-weight-bold error--text">{{reversalCount}}</div></v-card></v-col>
      <v-col cols="6" md="3"><v-card flat class="summary-card pa-4"><div class="caption grey--text">Document Events Shown</div><div class="text-h6 font-weight-bold blue--text">{{documentCount}}</div></v-card></v-col>
      <v-col cols="6" md="3"><v-card flat class="summary-card pa-4"><div class="caption grey--text">Users Shown</div><div class="text-h6 font-weight-bold">{{userCount}}</div></v-card></v-col>
    </v-row>

    <v-alert v-if="error" type="error" dense text>{{error}}</v-alert>

    <v-card flat class="filter-card pa-4 mb-4">
      <v-row dense align="center">
        <v-col cols="12" md="3"><v-text-field v-model="filters.search" clearable dense outlined hide-details prepend-inner-icon="mdi-magnify" label="Search action, reason or user" @keyup.enter="applyFilters" @click:clear="applyFilters"/></v-col>
        <v-col cols="12" sm="6" md="2"><v-select v-model="filters.entity_type" :items="entityTypes" item-text="text" item-value="value" clearable dense outlined hide-details label="Entity" @change="applyFilters"/></v-col>
        <v-col cols="12" sm="6" md="2"><v-select v-model="filters.action" :items="actions" item-text="text" item-value="value" clearable dense outlined hide-details label="Action" @change="applyFilters"/></v-col>
        <v-col cols="6" md="2"><v-text-field v-model="filters.from" clearable dense outlined hide-details label="From" type="date" @change="applyFilters"/></v-col>
        <v-col cols="6" md="2"><v-text-field v-model="filters.to" clearable dense outlined hide-details label="To" type="date" @change="applyFilters"/></v-col>
        <v-col cols="12" md="1" class="text-md-right"><v-btn icon @click="load"><v-icon>mdi-refresh</v-icon></v-btn></v-col>
        <v-col cols="12" md="3"><v-text-field v-model="filters.entity_id" clearable dense outlined hide-details label="Entity ID" type="number" @keyup.enter="applyFilters"/></v-col>
        <v-col cols="12" md="9" class="text-md-right"><v-btn text color="grey darken-1" @click="reset"><v-icon left>mdi-filter-remove</v-icon>Reset Filters</v-btn><v-btn color="#165134" dark depressed @click="applyFilters"><v-icon left>mdi-filter</v-icon>Apply</v-btn></v-col>
      </v-row>
    </v-card>

    <v-card flat class="table-card">
      <v-card-title>
        <v-icon left color="#165134">mdi-shield-search-outline</v-icon>Audit Events
        <v-spacer/><v-chip small outlined>{{total}} records</v-chip>
      </v-card-title>
      <v-divider/>
      <v-data-table :headers="headers" :items="items" :server-items-length="total" :options.sync="options" @update:options="load">
        <template v-slot:item.id="{item}"><span class="font-weight-bold">#{{item.id}}</span></template>
        <template v-slot:item.entity_type="{item}">
          <div class="d-flex align-center py-2">
            <v-avatar size="32" class="soft-grey mr-2"><v-icon small :color="entityColor(item.entity_type)">{{entityIcon(item.entity_type)}}</v-icon></v-avatar>
            <div><div class="font-weight-medium">{{entityLabel(item.entity_type)}}</div><div class="caption grey--text">#{{item.entity_id}}</div></div>
          </div>
        </template>
        <template v-slot:item.action="{item}"><v-chip x-small dark :color="actionColor(item.action)">{{actionLabel(item.action)}}</v-chip></template>
        <template v-slot:item.user="{item}">{{item.user ? item.user.name : 'System'}}</template>
        <template v-slot:item.reason="{item}"><div class="reason-cell text-truncate">{{item.reason || '—'}}</div></template>
        <template v-slot:item.created_at="{item}">{{formatDate(item.created_at)}}</template>
        <template v-slot:item.details="{item}"><v-btn small text color="#165134" @click="openDetails(item)"><v-icon left small>mdi-eye-outline</v-icon>View</v-btn></template>
        <template v-slot:no-data><div class="pa-10 text-center grey--text"><v-icon size="52" color="grey lighten-1">mdi-shield-search-outline</v-icon><div class="mt-2">No audit records match the selected filters.</div></div></template>
      </v-data-table>
    </v-card>

    <v-dialog v-model="details" max-width="980" persistent>
      <v-card v-if="selected">
        <v-card-title>
          <div><div class="text-h6 font-weight-bold">Audit Event #{{selected.id}}</div><div class="caption grey--text">{{entityLabel(selected.entity_type)}} #{{selected.entity_id}}</div></div>
          <v-spacer/><v-btn icon @click="details=false"><v-icon>mdi-close</v-icon></v-btn>
        </v-card-title>
        <v-divider/>
        <v-card-text class="pt-5">
          <v-row>
            <v-col cols="6" md="3"><div class="field-label">Entity</div><div class="field-value">{{entityLabel(selected.entity_type)}} #{{selected.entity_id}}</div></v-col>
            <v-col cols="6" md="3"><div class="field-label">Action</div><v-chip x-small dark :color="actionColor(selected.action)">{{actionLabel(selected.action)}}</v-chip></v-col>
            <v-col cols="6" md="3"><div class="field-label">User</div><div class="field-value">{{selected.user ? selected.user.name : 'System'}}</div></v-col>
            <v-col cols="6" md="3"><div class="field-label">Date</div><div class="field-value">{{formatDate(selected.created_at)}}</div></v-col>
          </v-row>

          <div v-if="selected.reason" class="mt-4">
            <div class="field-label">Reason / Context</div>
            <div class="audit-box">{{selected.reason}}</div>
          </div>

          <v-row class="mt-2">
            <v-col cols="12" md="6">
              <div class="d-flex align-center mb-1"><strong>Before</strong><v-spacer/><v-chip x-small outlined>Previous state</v-chip></div>
              <pre class="audit-box audit-json">{{pretty(selected.before_data)}}</pre>
            </v-col>
            <v-col cols="12" md="6">
              <div class="d-flex align-center mb-1"><strong>After</strong><v-spacer/><v-chip x-small outlined color="#165134">New state</v-chip></div>
              <pre class="audit-box audit-json">{{pretty(selected.after_data)}}</pre>
            </v-col>
          </v-row>
        </v-card-text>
      </v-card>
    </v-dialog>
  </div>
</template>

<script>
import api from '../../../services/api'

export default {
  name:'FinancialAudit',
  data:()=>({
    loading:false,exporting:false,error:'',items:[],total:0,selected:null,details:false,
    options:{page:1,itemsPerPage:25,sortBy:[],sortDesc:[]},
    filters:{search:'',entity_type:null,action:null,entity_id:'',from:'',to:''},
    entityTypes:[
      {text:'Payment',value:'payment'},{text:'Booking',value:'booking'},{text:'Installment Plan',value:'installment_plan'},
      {text:'Commission',value:'commission'},{text:'Expense',value:'expense'},{text:'Financial Document',value:'financial_document'}
    ],
    actions:[
      {text:'Created',value:'created'},{text:'Updated',value:'updated'},{text:'Status Changed',value:'status_changed'},
      {text:'Reversed',value:'reversed'},{text:'Payment Reversed',value:'payment_reversed'},{text:'Deleted',value:'deleted'}
    ],
    headers:[
      {text:'Audit',value:'id',width:85},{text:'Entity',value:'entity_type'},{text:'Action',value:'action'},
      {text:'User',value:'user'},{text:'Reason',value:'reason'},{text:'Date',value:'created_at'},{text:'',value:'details',sortable:false,align:'right'}
    ]
  }),
  computed:{
    reversalCount(){return this.items.filter(function(x){return x.action==='reversed'||x.action==='payment_reversed'}).length},
    documentCount(){return this.items.filter(function(x){return x.entity_type==='financial_document'}).length},
    userCount(){return new Set(this.items.map(function(x){return x.user&&x.user.id?x.user.id:null}).filter(Boolean)).size}
  },
  mounted(){this.load()},
  methods:{
    params(){return Object.assign({},this.filters,{page:this.options.page,per_page:this.options.itemsPerPage})},
    async load(){
      this.loading=true;this.error=''
      try{
        const r=await api.get('/financial-audits',{params:this.params()})
        this.items=r.data.data||[]
        this.total=r.data.total||0
      }catch(e){
        this.error=(e.response&&e.response.data&&e.response.data.message)||'Unable to load financial audit records.'
      }finally{this.loading=false}
    },
    applyFilters(){this.options.page=1;this.load()},
    reset(){this.filters={search:'',entity_type:null,action:null,entity_id:'',from:'',to:''};this.options.page=1;this.load()},
    openDetails(item){this.selected=item;this.details=true},
    async exportAudit(format){
      this.exporting=true;this.error=''
      try{
        const p=Object.assign({},this.filters)
        const r=await api.get('/financial-audits/export/'+format,{params:p,responseType:'blob'})
        const blob=new Blob([r.data],{type:format==='pdf'?'application/pdf':'application/vnd.ms-excel'})
        const url=window.URL.createObjectURL(blob)
        const a=document.createElement('a')
        a.href=url
        a.download='financial-audit.'+format
        document.body.appendChild(a);a.click();a.remove();window.URL.revokeObjectURL(url)
      }catch(e){
        this.error='Unable to export the financial audit trail.'
      }finally{this.exporting=false}
    },
    entityLabel(v){const x=this.entityTypes.find(function(i){return i.value===v});return x?x.text:String(v||'').replace(/_/g,' ')},
    entityIcon(v){return{payment:'mdi-cash-check',booking:'mdi-bookmark-check-outline',installment_plan:'mdi-calendar-month-outline',commission:'mdi-percent-outline',expense:'mdi-cash-minus',financial_document:'mdi-file-document-outline'}[v]||'mdi-database-outline'},
    entityColor(v){return{payment:'green',booking:'blue',installment_plan:'purple',commission:'teal',expense:'orange',financial_document:'blue-grey'}[v]||'grey'},
    actionLabel(v){return v?String(v).replace(/_/g,' ').replace(/\b\w/g,function(x){return x.toUpperCase()}):'—'},
    actionColor(v){return{created:'success',updated:'blue',status_changed:'purple',reversed:'error',payment_reversed:'error',deleted:'grey darken-1'}[v]||'blue-grey'},
    formatDate(v){return v?new Date(v).toLocaleString('en-PK',{dateStyle:'medium',timeStyle:'short'}):'—'},
    pretty(v){return v?JSON.stringify(v,null,2):'No data'}
  }
}
</script>

<style scoped>
.page{width:100%}.hero{border-left:4px solid #165134}.summary-card,.filter-card,.table-card{border:1px solid rgba(22,81,52,.08);border-radius:15px!important}.audit-actions{gap:8px}.soft-grey{background:#f1f3f2!important}.reason-cell{max-width:260px}.field-label{font-size:12px;color:#7a847f;margin-bottom:4px}.field-value{font-weight:500;color:#26332d}.audit-box{margin-top:6px;padding:12px;background:rgba(128,128,128,.08);border-radius:8px;white-space:pre-wrap;word-break:break-word}.audit-json{max-height:360px;overflow:auto;font-size:11px}.page ::v-deep .v-data-table__wrapper{overflow-x:auto}
</style>