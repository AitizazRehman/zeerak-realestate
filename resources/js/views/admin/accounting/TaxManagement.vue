<template>
  <div class="tax-management-page">
    <v-card flat class="hero pa-5 mb-4">
      <div class="d-flex align-center flex-wrap">
        <div>
          <div class="text-overline">TAX CONTROL</div>
          <h1 class="text-h5 font-weight-bold mb-1">Tax & Withholding Management</h1>
          <div class="grey--text">Configurable tax codes, vendor withholding, certificates, tax register, and mapped GL positions.</div>
        </div>
        <v-spacer/>
        <v-btn v-if="$can('accounting.create')" color="#165134" dark depressed @click="openCodeCreate">
          <v-icon left>mdi-plus-circle-outline</v-icon>New Tax Code
        </v-btn>
      </div>
    </v-card>

    <v-card flat class="filter-card pa-4 mb-4">
      <v-row dense>
        <v-col cols="6" md="2"><v-text-field v-model="filters.from" type="date" outlined dense hide-details label="From"/></v-col>
        <v-col cols="6" md="2"><v-text-field v-model="filters.to" type="date" outlined dense hide-details label="To"/></v-col>
        <v-col v-if="branches.length" cols="12" md="2"><v-select v-model="filters.branch_id" :items="branches" item-text="name" item-value="id" clearable outlined dense hide-details label="Branch" @change="branchChanged"/></v-col>
        <v-col cols="12" md="2"><v-autocomplete v-model="filters.vendor_id" :items="vendors" item-text="display" item-value="id" clearable outlined dense hide-details label="Vendor"/></v-col>
        <v-col cols="12" md="2"><v-autocomplete v-model="filters.project_id" :items="projectsForFilter" item-text="display" item-value="id" clearable outlined dense hide-details label="Project"/></v-col>
        <v-col cols="12" md="2"><v-btn block color="#165134" dark depressed :loading="loading" @click="loadRegister">Apply</v-btn></v-col>
        <v-col cols="12" md="3"><v-autocomplete v-model="filters.tax_code_id" :items="taxCodes" item-text="display" item-value="id" clearable outlined dense hide-details label="Tax Code"/></v-col>
        <v-col cols="12" md="3"><v-select v-model="filters.tax_type" :items="taxTypes" item-text="text" item-value="value" clearable outlined dense hide-details label="Tax Type"/></v-col>
        <v-col cols="12" md="3"><v-select v-model="filters.status" :items="['active','reversed']" clearable outlined dense hide-details label="Transaction Status"/></v-col>
        <v-col cols="12" md="3"><v-select v-model="filters.certificate_status" :items="certificateStatuses" item-text="text" item-value="value" clearable outlined dense hide-details label="Certificate"/></v-col>
      </v-row>
    </v-card>

    <v-row class="mb-2">
      <v-col cols="6" md="2"><v-card flat class="summary-card pa-4"><div class="caption grey--text">Taxable Amount</div><div class="text-h6 font-weight-bold">PKR {{money(summary.taxable_amount)}}</div></v-card></v-col>
      <v-col cols="6" md="2"><v-card flat class="summary-card pa-4"><div class="caption grey--text">Tax Amount</div><div class="text-h6 font-weight-bold">PKR {{money(summary.tax_amount)}}</div></v-card></v-col>
      <v-col cols="6" md="2"><v-card flat class="summary-card pa-4"><div class="caption grey--text">Net Amount</div><div class="text-h6 font-weight-bold">PKR {{money(summary.net_amount)}}</div></v-card></v-col>
      <v-col cols="6" md="2"><v-card flat class="summary-card pa-4"><div class="caption grey--text">Active</div><div class="text-h6 font-weight-bold">{{summary.active_transactions || 0}}</div></v-card></v-col>
      <v-col cols="6" md="2"><v-card flat class="summary-card pa-4"><div class="caption grey--text">Pending Certificates</div><div class="text-h6 font-weight-bold warning--text">{{summary.pending_certificates || 0}}</div></v-card></v-col>
      <v-col cols="6" md="2"><v-card flat class="summary-card pa-4"><div class="caption grey--text">Issued Certificates</div><div class="text-h6 font-weight-bold success--text">{{summary.issued_certificates || 0}}</div></v-card></v-col>
    </v-row>

    <v-card flat class="table-card">
      <v-tabs v-model="tab" color="#165134">
        <v-tab>Tax Register</v-tab>
        <v-tab>GL Positions</v-tab>
        <v-tab>By Vendor</v-tab>
        <v-tab>Tax Codes</v-tab>
      </v-tabs>
      <v-divider/>

      <v-tabs-items v-model="tab">
        <v-tab-item>
          <v-data-table
            :headers="registerHeaders"
            :items="transactions"
            :loading="loading"
            :server-items-length="transactionTotal"
            :page.sync="page"
            :items-per-page.sync="perPage"
            :footer-props="{'items-per-page-options':[10,25,50,100]}"
            @update:page="loadRegister"
          >
            <template v-slot:item.reference="{item}">
              <div class="font-weight-medium">{{ item.tax_code ? item.tax_code.code : '—' }}</div>
              <div class="caption grey--text">{{ item.vendor_bill ? item.vendor_bill.bill_number : item.source_type }}</div>
            </template>
            <template v-slot:item.vendor="{item}">
              <div>{{ item.vendor ? item.vendor.name : '—' }}</div>
              <div class="caption grey--text">{{ item.vendor ? item.vendor.tax_number || item.vendor.vendor_number : '' }}</div>
            </template>
            <template v-slot:item.projects="{item}">
              <span v-if="!(item.allocations || []).length">—</span>
              <span v-else>{{ projectList(item.allocations) }}</span>
            </template>
            <template v-slot:item.taxable_amount="{item}">PKR {{money(item.taxable_amount)}}</template>
            <template v-slot:item.tax_rate_percent="{item}">{{Number(item.tax_rate_percent||0)}}%</template>
            <template v-slot:item.tax_amount="{item}"><strong>PKR {{money(item.tax_amount)}}</strong></template>
            <template v-slot:item.net_amount="{item}">PKR {{money(item.net_amount)}}</template>
            <template v-slot:item.status="{item}"><v-chip x-small :color="item.status==='active'?'success':'grey'" dark>{{item.status}}</v-chip></template>
            <template v-slot:item.certificate="{item}">
              <div v-if="item.certificate_status==='issued'">
                <div class="font-weight-medium">{{item.certificate_number}}</div>
                <div class="caption grey--text">{{dateLabel(item.certificate_date)}}</div>
              </div>
              <v-chip v-else x-small :color="item.certificate_status==='pending'?'warning':'grey'" dark>{{item.certificate_status}}</v-chip>
            </template>
            <template v-slot:item.actions="{item}">
              <v-btn
                v-if="$can('accounting.edit') && item.status==='active' && item.certificate_status==='pending'"
                x-small text color="#165134"
                @click="issueCertificate(item)"
              >
                Issue Certificate
              </v-btn>
            </template>
          </v-data-table>
        </v-tab-item>

        <v-tab-item>
          <div class="pa-4">
            <v-alert type="info" text dense>
              GL difference = mapped account balance through the report end date minus cumulative active tax-register amount. Manual settlements or other GL entries can create a difference.
            </v-alert>
            <v-data-table :headers="ledgerHeaders" :items="ledgerPositions" :items-per-page="25">
              <template v-slot:item.code="{item}"><div class="font-weight-medium">{{item.code}} · {{item.name}}</div><div class="caption grey--text">{{item.tax_type}}</div></template>
              <template v-slot:item.account="{item}">{{item.account_code ? item.account_code+' · '+item.account_name : '—'}}</template>
              <template v-slot:item.register_amount="{item}">PKR {{money(item.register_amount)}}</template>
              <template v-slot:item.ledger_balance="{item}">PKR {{money(item.ledger_balance)}}</template>
              <template v-slot:item.difference="{item}"><strong :class="Math.abs(Number(item.difference||0))<0.01?'success--text':'warning--text'">PKR {{money(item.difference)}}</strong></template>
            </v-data-table>
          </div>
        </v-tab-item>

        <v-tab-item>
          <div class="pa-4">
            <v-data-table :headers="vendorHeaders" :items="byVendor" :items-per-page="25">
              <template v-slot:item.vendor="{item}"><div class="font-weight-medium">{{item.vendor_name}}</div><div class="caption grey--text">{{item.tax_number || item.vendor_number}}</div></template>
              <template v-slot:item.taxable_amount="{item}">PKR {{money(item.taxable_amount)}}</template>
              <template v-slot:item.tax_amount="{item}"><strong>PKR {{money(item.tax_amount)}}</strong></template>
            </v-data-table>
          </div>
        </v-tab-item>

        <v-tab-item>
          <div class="pa-4">
            <div class="d-flex mb-3"><v-spacer/><v-btn v-if="$can('accounting.create')" small color="#165134" dark depressed @click="openCodeCreate"><v-icon left small>mdi-plus</v-icon>Add Tax Code</v-btn></div>
            <v-data-table :headers="codeHeaders" :items="taxCodesRaw" :loading="codeLoading" :items-per-page="25">
              <template v-slot:item.code="{item}"><div class="font-weight-medium">{{item.code}}</div><div class="caption grey--text">{{item.name}}</div></template>
              <template v-slot:item.tax_type="{item}">{{taxTypeLabel(item.tax_type)}}</template>
              <template v-slot:item.rate_percent="{item}">{{Number(item.rate_percent||0)}}%</template>
              <template v-slot:item.account="{item}">{{item.account ? item.account.code+' · '+item.account.name : '—'}}</template>
              <template v-slot:item.effective="{item}">{{dateLabel(item.effective_from)}} → {{dateLabel(item.effective_to)}}</template>
              <template v-slot:item.is_active="{item}"><v-chip x-small :color="item.is_active?'success':'grey'" dark>{{item.is_active?'Active':'Inactive'}}</v-chip></template>
              <template v-slot:item.actions="{item}"><v-btn v-if="$can('accounting.edit')" icon small @click="openCodeEdit(item)"><v-icon small>mdi-pencil-outline</v-icon></v-btn></template>
            </v-data-table>
          </div>
        </v-tab-item>
      </v-tabs-items>
    </v-card>

    <v-dialog v-model="codeDialog" max-width="760" persistent>
      <v-card>
        <v-card-title>{{ codeForm.id ? 'Edit Tax Code' : 'New Tax Code' }}</v-card-title>
        <v-card-text>
          <v-row>
            <v-col cols="12" md="4"><v-text-field v-model="codeForm.code" outlined dense label="Code *"/></v-col>
            <v-col cols="12" md="8"><v-text-field v-model="codeForm.name" outlined dense label="Name *"/></v-col>
            <v-col cols="12" md="6"><v-select v-model="codeForm.tax_type" :items="taxTypes" item-text="text" item-value="value" outlined dense label="Tax Type *"/></v-col>
            <v-col cols="12" md="3"><v-text-field v-model.number="codeForm.rate_percent" type="number" min="0" max="100" step="0.0001" outlined dense label="Rate % *"/></v-col>
            <v-col cols="12" md="3"><v-switch v-model="codeForm.is_active" inset label="Active"/></v-col>
            <v-col cols="12"><v-autocomplete v-model="codeForm.chart_of_account_id" :items="accountsForType" item-text="display" item-value="id" outlined dense label="Mapped GL Account *"/></v-col>
            <v-col cols="12" md="6"><v-text-field v-model="codeForm.effective_from" type="date" outlined dense label="Effective From"/></v-col>
            <v-col cols="12" md="6"><v-text-field v-model="codeForm.effective_to" type="date" outlined dense label="Effective To"/></v-col>
            <v-col cols="12" md="6"><v-switch v-model="codeForm.certificate_required" inset label="Certificate Required"/></v-col>
            <v-col cols="12"><v-textarea v-model="codeForm.notes" outlined dense rows="2" label="Notes"/></v-col>
          </v-row>
        </v-card-text>
        <v-card-actions><v-spacer/><v-btn text @click="codeDialog=false">Cancel</v-btn><v-btn color="#165134" dark depressed :loading="codeSaving" @click="saveCode">Save Tax Code</v-btn></v-card-actions>
      </v-card>
    </v-dialog>
  </div>
</template>

<script>
import api from '../../../services/api'

export default {
  name:'TaxManagement',
  data(){
    const d=new Date(),o=d.getTimezoneOffset(),today=new Date(d.getTime()-o*60000).toISOString().slice(0,10)
    return{
      tab:0,loading:false,codeLoading:false,codeSaving:false,codeDialog:false,
      branches:[],vendors:[],projects:[],accounts:[],taxTypes:[],taxCodes:[],taxCodesRaw:[],
      transactions:[],transactionTotal:0,ledgerPositions:[],byVendor:[],summary:{},page:1,perPage:25,
      filters:{from:today.slice(0,8)+'01',to:today,branch_id:null,vendor_id:null,project_id:null,tax_code_id:null,tax_type:null,status:null,certificate_status:null},
      certificateStatuses:[{text:'Not Required',value:'not_required'},{text:'Pending',value:'pending'},{text:'Issued',value:'issued'}],
      codeForm:{},
      registerHeaders:[
        {text:'Date',value:'transaction_date'},{text:'Tax / Source',value:'reference'},{text:'Vendor',value:'vendor'},{text:'Projects',value:'projects'},
        {text:'Taxable',value:'taxable_amount',align:'right'},{text:'Rate',value:'tax_rate_percent',align:'right'},{text:'Tax',value:'tax_amount',align:'right'},
        {text:'Net',value:'net_amount',align:'right'},{text:'Status',value:'status'},{text:'Certificate',value:'certificate'},{text:'',value:'actions',sortable:false}
      ],
      ledgerHeaders:[
        {text:'Tax Code',value:'code'},{text:'Mapped Account',value:'account'},{text:'Register',value:'register_amount',align:'right'},
        {text:'GL Balance',value:'ledger_balance',align:'right'},{text:'Difference',value:'difference',align:'right'}
      ],
      vendorHeaders:[
        {text:'Vendor',value:'vendor'},{text:'Transactions',value:'transaction_count',align:'right'},{text:'Taxable',value:'taxable_amount',align:'right'},
        {text:'Tax',value:'tax_amount',align:'right'},{text:'Pending Certs',value:'pending_certificates',align:'right'}
      ],
      codeHeaders:[
        {text:'Code',value:'code'},{text:'Type',value:'tax_type'},{text:'Rate',value:'rate_percent',align:'right'},{text:'GL Account',value:'account'},
        {text:'Effective',value:'effective'},{text:'Transactions',value:'transactions_count',align:'right'},{text:'Status',value:'is_active'},{text:'',value:'actions',sortable:false}
      ]
    }
  },
  computed:{
    projectsForFilter(){return this.filters.branch_id?this.projects.filter(p=>Number(p.branch_id)===Number(this.filters.branch_id)):this.projects},
    accountsForType(){
      if(this.codeForm.tax_type==='input_tax_receivable')return this.accounts.filter(a=>a.account_type==='asset'&&a.normal_balance==='debit')
      if(this.codeForm.tax_type)return this.accounts.filter(a=>a.account_type==='liability'&&a.normal_balance==='credit')
      return this.accounts
    }
  },
  async mounted(){await this.loadOptions();await Promise.all([this.loadCodes(),this.loadRegister()])},
  methods:{
    async loadOptions(){
      const r=await api.get('/accounting/taxes/options',{skipGlobalLoader:true})
      this.branches=r.data.branches||[]
      this.vendors=(r.data.vendors||[]).map(v=>Object.assign({},v,{display:v.vendor_number+' · '+v.name}))
      this.projects=(r.data.projects||[]).map(p=>Object.assign({},p,{display:(p.code?p.code+' · ':'')+p.name}))
      this.accounts=(r.data.accounts||[]).map(a=>Object.assign({},a,{display:a.code+' · '+a.name+' · '+a.account_type}))
      this.taxTypes=r.data.tax_types||[]
    },
    async loadCodes(){
      this.codeLoading=true
      try{
        const r=await api.get('/accounting/taxes/codes',{skipGlobalLoader:true})
        this.taxCodesRaw=r.data.data||[]
        this.taxCodes=this.taxCodesRaw.filter(x=>x.is_active).map(x=>Object.assign({},x,{display:x.code+' · '+x.name+' · '+Number(x.rate_percent||0)+'%'}))
      }finally{this.codeLoading=false}
    },
    async loadRegister(){
      this.loading=true
      try{
        const r=await api.get('/accounting/taxes/register',{params:{...this.filters,page:this.page,per_page:this.perPage},skipGlobalLoader:true})
        this.summary=r.data.summary||{}
        this.ledgerPositions=r.data.ledger_positions||[]
        this.byVendor=r.data.by_vendor||[]
        const tx=r.data.transactions||{}
        this.transactions=tx.data||[]
        this.transactionTotal=Number(tx.total||0)
      }catch(e){this.$root.$emit('show-error',this.errorText(e,'Unable to load tax register.'))}
      finally{this.loading=false}
    },
    branchChanged(){this.filters.vendor_id=null;this.filters.project_id=null},
    blankCode(){return{id:null,code:'',name:'',tax_type:'withholding_payable',rate_percent:0,chart_of_account_id:null,effective_from:null,effective_to:null,certificate_required:true,is_active:true,notes:''}},
    openCodeCreate(){this.codeForm=this.blankCode();this.codeDialog=true},
    openCodeEdit(item){this.codeForm={...this.blankCode(),...item,effective_from:this.dateOnly(item.effective_from)==='—'?null:this.dateOnly(item.effective_from),effective_to:this.dateOnly(item.effective_to)==='—'?null:this.dateOnly(item.effective_to)};this.codeDialog=true},
    async saveCode(){
      this.codeSaving=true
      try{
        const payload={...this.codeForm};delete payload.id;delete payload.account;delete payload.transactions_count
        if(this.codeForm.id)await api.put('/accounting/taxes/codes/'+this.codeForm.id,payload,{skipGlobalError:true})
        else await api.post('/accounting/taxes/codes',payload,{skipGlobalError:true})
        this.codeDialog=false
        await Promise.all([this.loadCodes(),this.loadRegister()])
      }catch(e){this.$root.$emit('show-error',this.errorText(e,'Unable to save tax code.'))}
      finally{this.codeSaving=false}
    },
    async issueCertificate(item){
      try{
        await api.post('/accounting/taxes/transactions/'+item.id+'/certificate',{certificate_date:this.filters.to},{skipGlobalError:true})
        await this.loadRegister()
      }catch(e){this.$root.$emit('show-error',this.errorText(e,'Unable to issue withholding certificate.'))}
    },
    projectList(allocations){
      const names=[...new Set((allocations||[]).map(a=>a.project?a.project.name:'General'))]
      return names.join(', ')
    },
    taxTypeLabel(value){const t=this.taxTypes.find(x=>x.value===value);return t?t.text:value},
    money(v){return new Intl.NumberFormat('en-PK',{minimumFractionDigits:2,maximumFractionDigits:2}).format(Number(v||0))},
    dateOnly(v){return v?String(v).slice(0,10):'—'},
    dateLabel(v){if(!v)return'—';const s=String(v).slice(0,10),p=s.split('-');return p.length===3?new Intl.DateTimeFormat('en-PK',{day:'2-digit',month:'short',year:'numeric'}).format(new Date(Number(p[0]),Number(p[1])-1,Number(p[2]))):s},
    errorText(e,fallback){if(e.response&&e.response.data){const er=e.response.data.errors||{},k=Object.keys(er)[0];if(k&&er[k]&&er[k][0])return er[k][0];if(e.response.data.message)return e.response.data.message}return fallback}
  }
}
</script>

<style scoped>
.hero{border-left:4px solid #165134}
.filter-card,.summary-card,.table-card{border:1px solid rgba(22,81,52,.08);border-radius:15px!important}
</style>
