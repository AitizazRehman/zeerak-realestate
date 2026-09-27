<template>
  <div>
    <v-card flat class="pa-5 mb-4">
      <div class="text-overline">ACCOUNTING · PKR</div>
      <h1 class="text-h5 font-weight-bold">{{ isCustomer ? 'Customer Financial Statement' : 'Project Financial Statement' }}</h1>
      <p class="grey--text mb-0">Posted journals, including reversals. Opening balances include postings before the selected period.</p>
    </v-card>
    <v-card flat class="pa-4 mb-4">
      <v-form @submit.prevent="load(1)"><v-row dense>
        <v-col cols="12" md="4"><v-autocomplete v-model="filters.customer_id" :items="options.customers" item-text="label" item-value="id" :label="isCustomer ? 'Customer *' : 'All customers'" outlined dense clearable /></v-col>
        <v-col cols="12" md="4"><v-autocomplete v-model="filters.project_id" :items="options.projects" item-text="label" item-value="id" :label="isCustomer ? 'All projects' : 'Project *'" outlined dense clearable /></v-col>
        <v-col cols="6" md="2"><v-text-field v-model="filters.from" type="date" label="From *" outlined dense /></v-col>
        <v-col cols="6" md="2"><v-text-field v-model="filters.to" type="date" label="To *" outlined dense /></v-col>
      </v-row><v-btn type="submit" color="primary" :loading="loading" :disabled="optionsLoading || loading">View statement</v-btn>
      <v-btn class="ml-2" outlined :loading="exporting" :disabled="!result || loading || exporting" @click="download">Export CSV</v-btn></v-form>
    </v-card>
    <v-alert v-if="error" type="error" text>{{ error }}</v-alert>
    <v-alert v-if="!result && !loading && !error" type="info" text>Select {{ isCustomer ? 'a customer' : 'a project' }} and a date range.</v-alert>
    <template v-if="result">
      <v-card flat class="pa-4 mb-4">
        <div class="font-weight-bold mb-2">{{ scopeLabel }} · {{ applied.from }} to {{ applied.to }}</div>
        <div class="summary d-flex flex-wrap">
          <span>Closing receivable: <strong>{{ balance(result.summary.receivable) }}</strong></span>
          <span>Closing advances: <strong>{{ balance(negate(result.summary.advances)) }}</strong></span>
          <span v-if="isCustomer">Net position: <strong>{{ balance(result.summary.net_position) }}</strong></span>
          <template v-else>
            <span>Period revenue: <strong>{{ signed(result.summary.revenue) }}</strong></span>
            <span>Period costs: <strong>{{ signed(result.summary.costs) }}</strong></span>
            <span>Recorded result: <strong>{{ signed(result.summary.recorded_result) }}</strong></span>
          </template>
        </div>
      </v-card>
      <v-alert type="info" text dense>{{ isCustomer ? 'Receivable (1200) and advance (2300) accounts only. Net position is a comparison, not an automatic settlement across bookings. Unrecognized booking amounts are not ledger receivables.' : 'Only lines tagged to this project are included. Recorded result is posted revenue less posted costs for the period; it excludes unposted costs and unallocated overhead and is not a complete project profit calculation.' }}</v-alert>
      <v-card flat class="mb-4"><v-card-title class="subtitle-1">Account balances</v-card-title>
        <v-data-table :headers="accountHeaders" :items="result.accounts" :items-per-page="-1" hide-default-footer disable-sort>
          <template v-slot:item.opening="{item}">{{ balance(item.opening) }}</template>
          <template v-slot:item.debit="{item}">{{ money(item.debit) }}</template>
          <template v-slot:item.credit="{item}">{{ money(item.credit) }}</template>
          <template v-slot:item.closing="{item}">{{ balance(item.closing) }}</template>
        </v-data-table>
      </v-card>
      <v-card flat><v-card-title class="subtitle-1">Journal activity · {{ result.entries.total }} lines</v-card-title>
        <v-data-table :headers="entryHeaders" :items="result.entries.data" :items-per-page="50" hide-default-footer disable-sort no-data-text="No posted activity in this period.">
          <template v-slot:item.account_name="{item}">{{item.code}} — {{item.account_name}}</template>
          <template v-slot:item.description="{item}">{{item.description || item.entry_description}}</template>
          <template v-slot:item.debit="{item}">{{money(item.debit)}}</template>
          <template v-slot:item.credit="{item}">{{money(item.credit)}}</template>
          <template v-slot:item.balance="{item}">{{balance(item.balance)}}</template>
        </v-data-table>
        <v-pagination v-if="result.entries.last_page > 1" :value="result.entries.current_page" :length="result.entries.last_page" :total-visible="7" :disabled="loading" class="pa-4" @input="load" />
      </v-card>
      <p class="caption grey--text mt-3">Running balances are per account, across all pages. Historical accounts and archived entities remain available. CSV includes all matching activity, not just this page. Lines tagged outside your branch are excluded.</p>
    </template>
  </div>
</template>
<script>
import api from '../../../services/api'
export default {
  name:'FinancialStatement',
  props:{type:{type:String,required:true}},
  data(){
    const d=new Date(), y=d.getFullYear(), m=String(d.getMonth()+1).padStart(2,'0'), day=String(d.getDate()).padStart(2,'0')
    return {filters:{customer_id:null,project_id:null,from:`${y}-${m}-01`,to:`${y}-${m}-${day}`},options:{customers:[],projects:[]},optionsLoading:false,loading:false,exporting:false,error:'',result:null,applied:null,requestId:0,
      accountHeaders:[{text:'Code',value:'code'},{text:'Account',value:'name'},...['opening','debit','credit','closing'].map(v=>({text:{opening:'Opening',debit:'Period debit',credit:'Period credit',closing:'Closing'}[v],value:v,align:'end'}))],
      entryHeaders:[{text:'Date',value:'entry_date'},{text:'Journal',value:'entry_number'},{text:'Account',value:'account_name'},{text:'Project',value:'project_name'},{text:'Customer',value:'customer_name'},{text:'Description',value:'description'},...['debit','credit','balance'].map(v=>({text:{debit:'Debit',credit:'Credit',balance:'Account balance'}[v],value:v,align:'end'}))]}
  },
  computed:{isCustomer(){return this.type==='customer'},scopeLabel(){if(!this.result)return '';return [this.result.filters.customer&&this.result.filters.customer.name,this.result.filters.project&&this.result.filters.project.name].filter(Boolean).join(' / ')}},
  watch:{type(){this.requestId++;this.result=null;this.applied=null;this.loading=false;this.error=''}},
  async mounted(){
    this.optionsLoading=true
    try{const r=await api.get('/accounting/statement-options');this.options={customers:r.data.customers.map(c=>({...c,label:`${c.customer_number} — ${c.name}${c.deleted_at?' (archived)':''}`})),projects:r.data.projects.map(p=>({...p,label:p.name+(p.deleted_at?' (archived)':'')}))}}
    catch(e){this.error=this.errorText(e)}finally{this.optionsLoading=false}
  },
  methods:{
    money(v){const parts=String(v||'0.00').replace(/^-/,'').split('.');return parts[0].replace(/\B(?=(\d{3})+(?!\d))/g,',')+'.'+(parts[1]||'').padEnd(2,'0')},
    signed(v){return (String(v).startsWith('-')?'-':'')+this.money(v)},
    negate(v){const s=String(v);return s==='0.00'?s:(s.startsWith('-')?s.slice(1):'-'+s)},
    balance(v){return this.money(v)+(String(v).startsWith('-')?' Cr':' Dr')},
    errorText(e){const d=e.response&&e.response.data;if(d&&d.errors){const k=Object.keys(d.errors)[0];if(k)return d.errors[k][0]}return d&&d.message||'Unable to load the statement.'},
    async load(page=1){
      const id=++this.requestId, params=page===1||!this.applied?{...this.filters,type:this.type}:{...this.applied}
      this.error='';this.result=null
      if(!params.from||!params.to||params.from>params.to||!(params.type==='customer'?params.customer_id:params.project_id)){this.error='Select the required customer/project and a valid date range.';this.loading=false;return}
      this.loading=true
      try{const r=await api.get('/accounting/statements',{params:{...params,page}});if(id===this.requestId){this.result=r.data;this.applied=params}}
      catch(e){if(id===this.requestId)this.error=this.errorText(e)}finally{if(id===this.requestId)this.loading=false}
    },
    async download(){
      if(!this.applied||this.exporting)return
      this.exporting=true;this.error='';const params={...this.applied}
      try{const r=await api.get('/accounting/statements/export',{params,responseType:'blob'});const url=URL.createObjectURL(new Blob([r.data],{type:'text/csv;charset=utf-8'}));const a=document.createElement('a');a.href=url;a.download=params.type+'-statement-'+params.to+'.csv';document.body.appendChild(a);a.click();a.remove();URL.revokeObjectURL(url)}
      catch(e){this.error='Unable to export the statement. Please retry.'}finally{this.exporting=false}
    }
  }
}
</script>
<style scoped>.summary{gap:16px 32px}</style>
