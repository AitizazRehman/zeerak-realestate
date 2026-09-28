<template>
  <div class="accounts-payable-page">
    <v-card flat class="hero pa-5 mb-4">
      <div class="d-flex align-center flex-wrap">
        <div>
          <div class="text-overline">LIABILITIES CONTROL</div>
          <h1 class="text-h5 font-weight-bold mb-1">Accounts Payable</h1>
          <div class="grey--text">Vendor bills, partial settlements, reversals, and payable aging tied to 2100 Accounts Payable.</div>
        </div>
        <v-spacer/>
        <v-btn text color="#165134" class="mr-2" to="/admin/accounting/accounts-payable-control">
          <v-icon left>mdi-chart-timeline-variant</v-icon>AP Control
        </v-btn>
        <v-btn text color="#165134" class="mr-2" to="/admin/accounting/vendors">
          <v-icon left>mdi-store-outline</v-icon>Vendors
        </v-btn>
        <v-btn v-if="$can('accounting.create')" color="#165134" dark depressed @click="openBill">
          <v-icon left>mdi-file-document-plus-outline</v-icon>New Bill
        </v-btn>
      </div>
    </v-card>

    <v-row class="mb-1">
      <v-col cols="6" md="2"><v-card flat class="summary-card pa-4"><div class="caption grey--text">Total Payable</div><div class="text-h6 font-weight-bold">PKR {{money(aging.total)}}</div></v-card></v-col>
      <v-col cols="6" md="2"><v-card flat class="summary-card pa-4"><div class="caption grey--text">Current</div><div class="text-h6 font-weight-bold success--text">PKR {{money(aging.current)}}</div></v-card></v-col>
      <v-col cols="6" md="2"><v-card flat class="summary-card pa-4"><div class="caption grey--text">1–30 Days</div><div class="text-h6 font-weight-bold warning--text">PKR {{money(aging['1_30'])}}</div></v-card></v-col>
      <v-col cols="6" md="2"><v-card flat class="summary-card pa-4"><div class="caption grey--text">31–60 Days</div><div class="text-h6 font-weight-bold">PKR {{money(aging['31_60'])}}</div></v-card></v-col>
      <v-col cols="6" md="2"><v-card flat class="summary-card pa-4"><div class="caption grey--text">61–90 Days</div><div class="text-h6 font-weight-bold">PKR {{money(aging['61_90'])}}</div></v-card></v-col>
      <v-col cols="6" md="2"><v-card flat class="summary-card pa-4"><div class="caption grey--text">90+ Days</div><div class="text-h6 font-weight-bold error--text">PKR {{money(aging['90_plus'])}}</div></v-card></v-col>
    </v-row>

    <v-card flat class="filter-card pa-4 mb-4">
      <v-row dense>
        <v-col cols="12" md="3"><v-text-field v-model="filters.search" outlined dense hide-details label="Search bill / vendor" prepend-inner-icon="mdi-magnify" @keyup.enter="applyFilters"/></v-col>
        <v-col v-if="branches.length" cols="12" md="2"><v-select v-model="filters.branch_id" :items="branches" item-text="name" item-value="id" outlined dense hide-details clearable label="Branch" @change="branchChanged"/></v-col>
        <v-col cols="12" md="2"><v-autocomplete v-model="filters.vendor_id" :items="vendors" item-text="display" item-value="id" outlined dense hide-details clearable label="Vendor"/></v-col>
        <v-col cols="12" md="2"><v-autocomplete v-model="filters.project_id" :items="projects" item-text="display" item-value="id" outlined dense hide-details clearable label="Project"/></v-col>
        <v-col cols="12" md="1"><v-select v-model="filters.status" :items="statusOptions" item-text="text" item-value="value" outlined dense hide-details clearable label="Status"/></v-col>
        <v-col cols="12" md="2"><v-btn block color="#165134" dark depressed :loading="loading" @click="applyFilters">Apply</v-btn></v-col>
      </v-row>
    </v-card>

    <v-card flat class="table-card">
      <v-tabs v-model="tab" color="#165134">
        <v-tab>Bills</v-tab>
        <v-tab>Vendor Aging</v-tab>
      </v-tabs>
      <v-divider/>
      <v-tabs-items v-model="tab">
        <v-tab-item>
          <v-data-table
            :headers="headers"
            :items="items"
            :loading="loading"
            :server-items-length="total"
            :page.sync="page"
            :items-per-page.sync="perPage"
            :footer-props="{'items-per-page-options':[10,25,50,100]}"
            show-expand
            item-key="id"
            @update:page="loadBills"
            @update:items-per-page="perPageChanged"
          >
            <template v-slot:item.bill="{item}">
              <div class="font-weight-medium">{{item.bill_number}}</div>
              <div class="caption grey--text">{{item.vendor_invoice_number || 'No vendor invoice #'}}</div>
            </template>
            <template v-slot:item.vendor="{item}">
              <div>{{item.vendor ? item.vendor.name : '—'}}</div>
              <div class="caption grey--text">{{item.vendor ? item.vendor.vendor_number : ''}}</div>
            </template>
            <template v-slot:item.project="{item}">{{item.project ? item.project.name : 'General / Unassigned'}}</template>
            <template v-slot:item.dates="{item}">
              <div>{{dateLabel(item.bill_date)}}</div>
              <div class="caption" :class="isOverdue(item) ? 'error--text' : 'grey--text'">Due {{dateLabel(item.due_date)}}</div>
            </template>
            <template v-slot:item.total_amount="{item}">PKR {{money(item.total_amount)}}</template>
            <template v-slot:item.remaining_amount="{item}">
              <strong :class="Number(item.remaining_amount)>0 ? 'warning--text' : 'success--text'">PKR {{money(item.remaining_amount)}}</strong>
            </template>
            <template v-slot:item.status="{item}">
              <v-chip x-small :color="statusColor(item.status)" dark>{{item.status}}</v-chip>
            </template>
            <template v-slot:item.actions="{item}">
              <v-btn v-if="$can('accounting.edit') && ['posted','partial'].includes(item.status)" icon small color="#165134" title="Pay bill" @click="openPayment(item)"><v-icon small>mdi-cash-check</v-icon></v-btn>
              <v-btn v-if="$can('accounting.edit') && ['posted','partial'].includes(item.status)" icon small color="error" title="Cancel bill" @click="openCancel(item)"><v-icon small>mdi-file-cancel-outline</v-icon></v-btn>
            </template>

            <template v-slot:expanded-item="{headers,item}">
              <td :colspan="headers.length" class="pa-4">
                <div class="subtitle-2 font-weight-bold mb-2">Bill Lines</div>
                <v-simple-table dense>
                  <thead><tr><th>GL Account</th><th>Project</th><th>Description</th><th class="text-right">Amount</th></tr></thead>
                  <tbody>
                    <tr v-for="line in item.lines || []" :key="line.id">
                      <td>{{line.account ? line.account.code+' · '+line.account.name : '—'}}</td>
                      <td>{{projectName(line.project_id,item)}}</td>
                      <td>{{line.description}}</td>
                      <td class="text-right">PKR {{money(line.amount)}}</td>
                    </tr>
                  </tbody>
                </v-simple-table>

                <div class="subtitle-2 font-weight-bold mt-4 mb-2">Payment History</div>
                <v-simple-table dense>
                  <thead><tr><th>Date</th><th>Cash/Bank</th><th>Method</th><th>Reference</th><th class="text-right">Amount</th><th></th></tr></thead>
                  <tbody>
                    <tr v-for="payment in item.payments || []" :key="payment.id">
                      <td>{{dateLabel(payment.payment_date)}}</td>
                      <td>{{payment.cash_bank_account ? payment.cash_bank_account.code+' · '+payment.cash_bank_account.name : '—'}}</td>
                      <td>{{payment.payment_method}}</td>
                      <td>{{payment.reference_number || payment.cheque_number || '—'}}</td>
                      <td class="text-right" :class="payment.reversed_at ? 'text-decoration-line-through grey--text' : ''">PKR {{money(payment.amount)}}</td>
                      <td class="text-right">
                        <v-chip v-if="payment.reversed_at" x-small color="grey" dark>Reversed</v-chip>
                        <v-btn v-else-if="$can('accounting.edit')" x-small text color="warning" @click="openReversePayment(payment)">Reverse</v-btn>
                      </td>
                    </tr>
                    <tr v-if="!(item.payments || []).length"><td colspan="6" class="text-center grey--text">No payments yet.</td></tr>
                  </tbody>
                </v-simple-table>
              </td>
            </template>
          </v-data-table>
        </v-tab-item>

        <v-tab-item>
          <v-data-table :headers="agingHeaders" :items="agingVendors" :items-per-page="25">
            <template v-slot:item.vendor="{item}">
              <div class="font-weight-medium">{{item.vendor_name}}</div>
              <div class="caption grey--text">{{item.vendor_number}}</div>
            </template>
            <template v-slot:item.total="{item}"><strong>PKR {{money(item.total)}}</strong></template>
            <template v-slot:item.current="{item}">PKR {{money(item.current)}}</template>
            <template v-slot:[`item.1_30`]="{item}">PKR {{money(item['1_30'])}}</template>
            <template v-slot:[`item.31_60`]="{item}">PKR {{money(item['31_60'])}}</template>
            <template v-slot:[`item.61_90`]="{item}">PKR {{money(item['61_90'])}}</template>
            <template v-slot:[`item.90_plus`]="{item}"><span :class="Number(item['90_plus'])>0?'error--text font-weight-bold':''">PKR {{money(item['90_plus'])}}</span></template>
            <template v-slot:item.max_days_overdue="{item}">{{item.max_days_overdue || '—'}}</template>
          </v-data-table>
        </v-tab-item>
      </v-tabs-items>
    </v-card>

    <v-dialog v-model="billDialog" max-width="1100" persistent>
      <v-card>
        <v-card-title>Post Vendor Bill</v-card-title>
        <v-card-text>
          <v-row>
            <v-col v-if="branches.length" cols="12" md="3"><v-select v-model="billForm.branch_id" :items="branches" item-text="name" item-value="id" outlined dense label="Branch *" @change="billBranchChanged"/></v-col>
            <v-col cols="12" md="3"><v-autocomplete v-model="billForm.vendor_id" :items="vendors" item-text="display" item-value="id" outlined dense label="Vendor *" @change="vendorChanged" :error-messages="billErrors.vendor_id"/></v-col>
            <v-col cols="12" md="3"><v-autocomplete v-model="billForm.project_id" :items="projects" item-text="display" item-value="id" outlined dense clearable label="Default Project"/></v-col>
            <v-col cols="12" md="3"><v-text-field v-model="billForm.vendor_invoice_number" outlined dense label="Vendor Invoice #"/></v-col>
            <v-col cols="12" md="3"><v-text-field v-model="billForm.bill_date" type="date" outlined dense label="Bill Date *"/></v-col>
            <v-col cols="12" md="3"><v-text-field v-model="billForm.due_date" type="date" outlined dense label="Due Date *"/></v-col>
            <v-col cols="12" md="6"><v-text-field v-model="billForm.description" outlined dense label="Description *"/></v-col>
          </v-row>

          <div class="d-flex align-center mb-2"><div class="subtitle-2 font-weight-bold">Bill Lines</div><v-spacer/><v-btn small text color="#165134" @click="addLine"><v-icon left small>mdi-plus</v-icon>Add Line</v-btn></div>
          <v-card v-for="(line,index) in billForm.lines" :key="index" flat outlined class="pa-3 mb-2">
            <v-row dense align="center">
              <v-col cols="12" md="4"><v-autocomplete v-model="line.chart_of_account_id" :items="debitAccounts" item-text="display" item-value="id" outlined dense hide-details label="Debit GL Account *"/></v-col>
              <v-col cols="12" md="3"><v-autocomplete v-model="line.project_id" :items="projects" item-text="display" item-value="id" outlined dense hide-details clearable label="Project"/></v-col>
              <v-col cols="12" md="3"><v-text-field v-model="line.description" outlined dense hide-details label="Line Description *"/></v-col>
              <v-col cols="10" md="1"><v-text-field v-model.number="line.amount" type="number" step="0.01" min="0.01" outlined dense hide-details label="Amount"/></v-col>
              <v-col cols="2" md="1" class="text-right"><v-btn icon small color="error" :disabled="billForm.lines.length===1" @click="billForm.lines.splice(index,1)"><v-icon small>mdi-delete-outline</v-icon></v-btn></v-col>
            </v-row>
          </v-card>
          <div class="text-right subtitle-1 font-weight-bold">Bill Total: PKR {{money(billTotal)}}</div>
          <v-alert v-if="billErrors.lines" type="error" text dense class="mt-3">{{billErrors.lines[0]}}</v-alert>
        </v-card-text>
        <v-card-actions><v-spacer/><v-btn text @click="billDialog=false">Cancel</v-btn><v-btn color="#165134" dark depressed :loading="billSaving" @click="saveBill">Post Bill</v-btn></v-card-actions>
      </v-card>
    </v-dialog>

    <v-dialog v-model="paymentDialog" max-width="650" persistent>
      <v-card>
        <v-card-title>Pay Vendor Bill</v-card-title>
        <v-card-text>
          <v-alert v-if="selectedBill" type="info" text dense>{{selectedBill.bill_number}} · Remaining PKR {{money(selectedBill.remaining_amount)}}</v-alert>
          <v-row>
            <v-col cols="12" md="6"><v-text-field v-model.number="paymentForm.amount" type="number" step="0.01" outlined dense label="Amount *"/></v-col>
            <v-col cols="12" md="6"><v-text-field v-model="paymentForm.payment_date" type="date" outlined dense label="Payment Date *"/></v-col>
            <v-col cols="12"><v-autocomplete v-model="paymentForm.cash_bank_account_id" :items="cashAccounts" item-text="display" item-value="id" outlined dense label="Cash / Bank Account *"/></v-col>
            <v-col cols="12" md="6"><v-select v-model="paymentForm.payment_method" :items="paymentMethods" outlined dense label="Method *"/></v-col>
            <v-col cols="12" md="6"><v-text-field v-model="paymentForm.reference_number" outlined dense label="Reference #"/></v-col>
            <v-col cols="12"><v-text-field v-model="paymentForm.cheque_number" outlined dense label="Cheque #"/></v-col>
          </v-row>
        </v-card-text>
        <v-card-actions><v-spacer/><v-btn text @click="paymentDialog=false">Cancel</v-btn><v-btn color="#165134" dark depressed :loading="paymentSaving" @click="savePayment">Post Payment</v-btn></v-card-actions>
      </v-card>
    </v-dialog>

    <v-dialog v-model="reverseDialog" max-width="560" persistent>
      <v-card><v-card-title>Reverse Vendor Payment</v-card-title><v-card-text>
        <v-text-field v-model="reverseForm.reversal_date" type="date" outlined dense label="Reversal Date *"/>
        <v-textarea v-model="reverseForm.reason" outlined rows="3" label="Reason *"/>
      </v-card-text><v-card-actions><v-spacer/><v-btn text @click="reverseDialog=false">Cancel</v-btn><v-btn color="warning" dark depressed :loading="reverseSaving" @click="reversePayment">Reverse</v-btn></v-card-actions></v-card>
    </v-dialog>

    <v-dialog v-model="cancelDialog" max-width="560" persistent>
      <v-card><v-card-title>Cancel Vendor Bill</v-card-title><v-card-text>
        <v-alert type="warning" text dense>All active payments must be reversed before a bill can be cancelled.</v-alert>
        <v-text-field v-model="cancelForm.cancellation_date" type="date" outlined dense label="Cancellation Date *"/>
        <v-textarea v-model="cancelForm.reason" outlined rows="3" label="Reason *"/>
      </v-card-text><v-card-actions><v-spacer/><v-btn text @click="cancelDialog=false">Close</v-btn><v-btn color="error" dark depressed :loading="cancelSaving" @click="cancelBill">Cancel Bill</v-btn></v-card-actions></v-card>
    </v-dialog>
  </div>
</template>

<script>
import api from '../../../services/api'

export default {
  name:'AccountsPayable',
  data(){
    return{
      tab:0,loading:false,billSaving:false,paymentSaving:false,reverseSaving:false,cancelSaving:false,
      items:[],total:0,page:1,perPage:25,aging:{},agingVendors:[],branches:[],vendors:[],projects:[],debitAccounts:[],cashAccounts:[],
      billDialog:false,paymentDialog:false,reverseDialog:false,cancelDialog:false,selectedBill:null,selectedPayment:null,billErrors:{},
      filters:{search:'',branch_id:null,vendor_id:null,project_id:null,status:null},
      statusOptions:[{text:'Posted',value:'posted'},{text:'Partial',value:'partial'},{text:'Paid',value:'paid'},{text:'Overdue',value:'overdue'},{text:'Cancelled',value:'cancelled'}],
      paymentMethods:['cash','bank_transfer','cheque','online','other'],
      headers:[
        {text:'Bill',value:'bill'},{text:'Vendor',value:'vendor'},{text:'Project',value:'project'},{text:'Bill / Due',value:'dates'},
        {text:'Total',value:'total_amount',align:'right'},{text:'Remaining',value:'remaining_amount',align:'right'},{text:'Status',value:'status'},{text:'',value:'actions',sortable:false},{text:'',value:'data-table-expand'}
      ],
      agingHeaders:[
        {text:'Vendor',value:'vendor'},{text:'Bills',value:'bill_count'},{text:'Total',value:'total',align:'right'},{text:'Current',value:'current',align:'right'},
        {text:'1–30',value:'1_30',align:'right'},{text:'31–60',value:'31_60',align:'right'},{text:'61–90',value:'61_90',align:'right'},{text:'90+',value:'90_plus',align:'right'},{text:'Max Days',value:'max_days_overdue',align:'right'}
      ],
      billForm:{},paymentForm:{},reverseForm:{},cancelForm:{}
    }
  },
  computed:{
    billTotal(){return (this.billForm.lines||[]).reduce((sum,line)=>sum+Number(line.amount||0),0)}
  },
  async mounted(){await this.loadOptions();await Promise.all([this.loadBills(),this.loadAging()])},
  methods:{
    today(){const d=new Date(),o=d.getTimezoneOffset();return new Date(d.getTime()-o*60000).toISOString().slice(0,10)},
    async loadOptions(branchId){
      const selectedBranch=branchId===undefined ? this.filters.branch_id : branchId
      const r=await api.get('/accounting/accounts-payable/options',{params:{branch_id:selectedBranch||undefined},skipGlobalLoader:true})
      this.branches=r.data.branches||this.branches
      this.vendors=(r.data.vendors||[]).map(x=>Object.assign({},x,{display:x.vendor_number+' · '+x.name}))
      this.projects=(r.data.projects||[]).map(x=>Object.assign({},x,{display:(x.code?x.code+' · ':'')+x.name}))
      this.debitAccounts=(r.data.debit_accounts||[]).map(x=>Object.assign({},x,{display:x.code+' · '+x.name}))
      this.cashAccounts=(r.data.cash_accounts||[]).map(x=>Object.assign({},x,{display:x.code+' · '+x.name}))
    },
    async branchChanged(){this.filters.vendor_id=null;this.filters.project_id=null;await this.loadOptions()},
    applyFilters(){this.page=1;Promise.all([this.loadBills(),this.loadAging()])},
    async loadBills(){
      this.loading=true
      try{
        const r=await api.get('/accounting/accounts-payable',{params:Object.assign({},this.filters,{page:this.page,per_page:this.perPage}),skipGlobalLoader:true})
        this.items=r.data.data||[];this.total=Number(r.data.total||0)
      }finally{this.loading=false}
    },
    async loadAging(){
      const r=await api.get('/accounting/accounts-payable/aging',{params:{branch_id:this.filters.branch_id||undefined,vendor_id:this.filters.vendor_id||undefined,project_id:this.filters.project_id||undefined,as_of:this.today()},skipGlobalLoader:true})
      this.aging=r.data.summary||{};this.agingVendors=r.data.vendors||[]
    },
    perPageChanged(v){this.perPage=Number(v||25);this.page=1;this.loadBills()},
    blankLine(){return{chart_of_account_id:null,project_id:null,description:'',amount:null}},
    openBill(){this.billErrors={};this.billForm={branch_id:this.filters.branch_id||null,vendor_id:null,project_id:null,vendor_invoice_number:'',bill_date:this.today(),due_date:this.today(),description:'',notes:'',lines:[this.blankLine()]};this.billDialog=true},
    async billBranchChanged(){
      this.billForm.vendor_id=null
      this.billForm.project_id=null
      this.billForm.lines=(this.billForm.lines||[]).map(line=>Object.assign({},line,{project_id:null}))
      await this.loadOptions(this.billForm.branch_id)
    },
    vendorChanged(id){
      const v=this.vendors.find(x=>Number(x.id)===Number(id));if(!v)return
      const d=new Date(this.billForm.bill_date+'T00:00:00');d.setDate(d.getDate()+Number(v.payment_terms_days||0))
      this.billForm.due_date=d.toISOString().slice(0,10)
    },
    addLine(){this.billForm.lines.push(this.blankLine())},
    async saveBill(){
      this.billSaving=true;this.billErrors={}
      try{
        await api.post('/accounting/accounts-payable',this.billForm,{skipGlobalError:true})
        this.billDialog=false;await Promise.all([this.loadBills(),this.loadAging()])
      }catch(e){
        if(e.response&&e.response.status===422)this.billErrors=e.response.data.errors||{}
        else this.$root.$emit('show-error',this.errorText(e,'Unable to post vendor bill.'))
      }finally{this.billSaving=false}
    },
    openPayment(item){
      this.selectedBill=item;this.paymentForm={amount:Number(item.remaining_amount||0),cash_bank_account_id:null,payment_date:this.today(),payment_method:'bank_transfer',reference_number:'',cheque_number:'',notes:'',request_key:'vendor-payment-'+item.id+'-'+Date.now()+'-'+Math.random().toString(36).slice(2)};this.paymentDialog=true
    },
    async savePayment(){
      this.paymentSaving=true
      try{
        await api.post('/accounting/accounts-payable/'+this.selectedBill.id+'/payments',this.paymentForm,{skipGlobalError:true})
        this.paymentDialog=false;await Promise.all([this.loadBills(),this.loadAging()])
      }catch(e){this.$root.$emit('show-error',this.errorText(e,'Unable to post vendor payment.'))}finally{this.paymentSaving=false}
    },
    openReversePayment(payment){this.selectedPayment=payment;this.reverseForm={reversal_date:this.today(),reason:''};this.reverseDialog=true},
    async reversePayment(){
      this.reverseSaving=true
      try{
        await api.post('/accounting/accounts-payable/payments/'+this.selectedPayment.id+'/reverse',this.reverseForm,{skipGlobalError:true})
        this.reverseDialog=false;await Promise.all([this.loadBills(),this.loadAging()])
      }catch(e){this.$root.$emit('show-error',this.errorText(e,'Unable to reverse vendor payment.'))}finally{this.reverseSaving=false}
    },
    openCancel(item){this.selectedBill=item;this.cancelForm={cancellation_date:this.today(),reason:''};this.cancelDialog=true},
    async cancelBill(){
      this.cancelSaving=true
      try{
        await api.post('/accounting/accounts-payable/'+this.selectedBill.id+'/cancel',this.cancelForm,{skipGlobalError:true})
        this.cancelDialog=false;await Promise.all([this.loadBills(),this.loadAging()])
      }catch(e){this.$root.$emit('show-error',this.errorText(e,'Unable to cancel vendor bill.'))}finally{this.cancelSaving=false}
    },
    projectName(projectId,item){
      if(projectId){const p=this.projects.find(x=>Number(x.id)===Number(projectId));if(p)return p.display}
      return item.project ? item.project.name : 'General / Unassigned'
    },
    isOverdue(item){return Number(item.remaining_amount||0)>0&&item.status!=='cancelled'&&String(item.due_date).slice(0,10)<this.today()},
    statusColor(s){return s==='paid'?'success':s==='partial'?'info':s==='cancelled'?'grey':'warning'},
    money(v){return new Intl.NumberFormat('en-PK',{minimumFractionDigits:2,maximumFractionDigits:2}).format(Number(v||0))},
    dateLabel(v){if(!v)return'—';const p=String(v).slice(0,10).split('-');return p.length===3?new Intl.DateTimeFormat('en-PK',{day:'2-digit',month:'short',year:'numeric'}).format(new Date(Number(p[0]),Number(p[1])-1,Number(p[2]))):v},
    errorText(e,fallback){if(e.response&&e.response.data){const er=e.response.data.errors||{},k=Object.keys(er)[0];if(k&&er[k]&&er[k][0])return er[k][0];if(e.response.data.message)return e.response.data.message}return fallback}
  }
}
</script>

<style scoped>
.hero{border-left:4px solid #165134}
.filter-card,.summary-card,.table-card{border:1px solid rgba(22,81,52,.08);border-radius:15px!important}
</style>
