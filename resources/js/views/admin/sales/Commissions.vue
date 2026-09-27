<template>
  <div class="page">
    <v-card flat class="hero pa-5 mb-4"><div class="d-flex flex-wrap align-center"><div><div class="text-overline">SALES &amp; COMMISSIONS</div><h1 class="text-h5 font-weight-bold">Commissions</h1><div class="grey--text">Track, approve and settle sales-agent commissions.</div></div><v-spacer></v-spacer><v-btn v-if="$can('commissions.create')" color="#165134" dark depressed class="mr-2 rounded-lg" @click="openCreate"><v-icon left>mdi-plus</v-icon>Add Commission</v-btn><v-btn icon @click="load"><v-icon>mdi-refresh</v-icon></v-btn></div></v-card>
    <v-row class="mb-1"><v-col cols="6" md="3"><v-card flat class="summary-card pa-4"><div class="caption grey--text">Commissions Shown</div><div class="text-h6 font-weight-bold">{{items.length}}</div></v-card></v-col><v-col cols="6" md="3"><v-card flat class="summary-card pa-4"><div class="caption grey--text">Pending</div><div class="text-h6 font-weight-bold orange--text">{{statusCount('pending')}}</div></v-card></v-col><v-col cols="6" md="3"><v-card flat class="summary-card pa-4"><div class="caption grey--text">Approved</div><div class="text-h6 font-weight-bold blue--text">{{statusCount('approved')}}</div></v-card></v-col><v-col cols="6" md="3"><v-card flat class="summary-card pa-4"><div class="caption grey--text">Amount Shown</div><div class="text-h6 font-weight-bold">PKR {{money(amountShown)}}</div></v-card></v-col></v-row>
    <v-card flat class="filter-card mb-4"><v-card-text><v-row dense><v-col cols="12" md="4"><v-select v-model="filters.status" :items="statuses" outlined dense clearable label="Status" @change="load"></v-select></v-col><v-col cols="12" md="4"><v-select v-model="filters.agent_id" :items="agents" item-text="name" item-value="id" outlined dense clearable label="Sales Agent" @change="load"></v-select></v-col><v-col cols="12" md="4" class="d-flex align-center"><v-chip outlined>{{ items.length }} shown</v-chip></v-col></v-row></v-card-text></v-card>
    <v-card flat class="table-card"><v-data-table :headers="headers" :items="items" :options.sync="options" :server-items-length="total"><template v-slot:item.commission_amount="{ item }"><strong>{{ money(item.commission_amount) }}</strong></template><template v-slot:item.percentage="{ item }">{{ item.percentage }}%</template><template v-slot:item.status="{ item }"><v-chip x-small :color="statusColor(item.status)" dark>{{ item.status }}</v-chip></template><template v-slot:item.journal="{ item }"><div>{{ item.accrual_journal ? item.accrual_journal.entry_number : 'Not posted' }}</div></template><template v-slot:item.actions="{ item }"><v-btn v-if="$can('commissions.view')" icon small title="Accounting history" @click="historyItem=item;historyDialog=true"><v-icon small>mdi-book-open-outline</v-icon></v-btn><v-badge v-if="$can('commissions.view')" :content="item.financial_documents_count" :value="item.financial_documents_count" color="#165134" overlap><v-btn icon small color="blue-grey" title="Receipts / invoices / documents" @click="openDocuments(item)"><v-icon small>mdi-paperclip</v-icon></v-btn></v-badge><v-menu v-if="$can('commissions.edit') && item.status!=='cancelled'" offset-y><template v-slot:activator="{ on, attrs }"><v-btn icon small v-bind="attrs" v-on="on"><v-icon small>mdi-dots-vertical</v-icon></v-btn></template><v-list dense><v-list-item v-if="item.status==='approved' && !item.accrual_journal" @click="openStatusChange(item,'recognize')"><v-list-item-title>Post approval to accounting</v-list-item-title></v-list-item><v-list-item v-for="status in nextStatuses(item.status)" :key="status" @click="openStatusChange(item, status)"><v-list-item-title>Mark {{ status }}</v-list-item-title></v-list-item><v-divider v-if="item.status==='paid'"></v-divider><v-list-item v-if="item.status==='paid'" @click="openReverse(item)"><v-list-item-icon><v-icon small color="error">mdi-undo</v-icon></v-list-item-icon><v-list-item-title class="error--text">Reverse payment</v-list-item-title></v-list-item></v-list></v-menu></template><template v-slot:no-data><div class="pa-8 grey--text">No commissions found.</div></template></v-data-table></v-card>
    <v-dialog v-model="createDialog" max-width="720" persistent>
      <v-card>
        <v-card-title>Add Commission<v-spacer/><v-btn icon :disabled="saving" @click="createDialog=false"><v-icon>mdi-close</v-icon></v-btn></v-card-title>
        <v-card-text>
          <v-alert type="info" text dense>Choose a booking with an assigned sales agent. Commission is calculated from the booking final price.</v-alert>
          <v-autocomplete v-model="form.booking_id" :items="bookings" :item-text="bookingLabel" item-value="id" outlined dense label="Sale / Booking *" no-data-text="No eligible sales/bookings found" @change="bookingChanged"/>
          <v-text-field :value="selectedBooking && selectedBooking.property ? ((selectedBooking.property.property_number||'Property')+(selectedBooking.property.project?' — '+selectedBooking.property.project.name:'')) : ''" outlined dense readonly label="Property"/><v-text-field :value="selectedBooking ? money(selectedBooking.final_price) : ''" outlined dense readonly label="Sale / Final Price" prefix="PKR"/>
          <v-autocomplete v-model="form.agent_id" :items="agents" item-text="name" item-value="id" outlined dense label="Sales Agent *" readonly/>
          <v-text-field v-model="form.percentage" type="number" min="0.01" max="100" step="0.01" outlined dense label="Commission Rate (%) *" suffix="%"/>
          <v-text-field :value="money(calculatedCommission)" outlined dense readonly label="Calculated Commission" prefix="PKR"/>
          <v-textarea v-model="form.notes" outlined dense rows="3" label="Notes"/>
        </v-card-text>
        <v-card-actions><v-spacer/><v-btn text :disabled="saving" @click="createDialog=false">Cancel</v-btn><v-btn outlined color="#165134" :loading="saving" :disabled="saving" @click="createCommission(false)">Create</v-btn><v-btn color="#165134" dark depressed :disabled="saving" @click="createCommission(true)"><v-icon left>mdi-paperclip</v-icon>Create & Add Document</v-btn></v-card-actions>
      </v-card>
    </v-dialog>
    <financial-documents-dialog v-model="documentsDialog" entity-type="commission" :entity-id="documentEntity ? documentEntity.id : null" :title="documentEntity ? 'Commission Documents — '+(documentEntity.booking ? documentEntity.booking.booking_number : '#'+documentEntity.id) : 'Commission Documents'" :can-upload="$can('commissions.create') || $can('commissions.edit')" :can-delete="$can('commissions.edit')" @updated="load"/>

    <v-dialog v-model="reverseDialog" max-width="520" persistent><v-card>
      <v-card-title>Reverse commission payment</v-card-title>
      <v-card-text><v-alert type="warning" text dense>Confirm that the payout was voided or the funds were returned. This restores the commission to approved and reopens its payable.</v-alert>
        <v-alert v-if="reverseItem && !(reverseItem.payments || []).length" type="info" text dense>This historical payment has no journal. Its reversal updates the commission status only.</v-alert>
        <v-text-field v-model="reversalDate" type="date" label="Reversal date *" outlined dense :disabled="reversing" />
        <v-textarea v-model="reverseReason" label="Reason for reversal *" outlined rows="3" :disabled="reversing" :error-messages="reverseError" />
      </v-card-text><v-card-actions><v-spacer /><v-btn text :disabled="reversing" @click="reverseDialog=false">Cancel</v-btn><v-btn color="error" :loading="reversing" :disabled="reversing || !reversalDate || !reverseReason.trim()" @click="reversePayment">Reverse payment</v-btn></v-card-actions>
    </v-card></v-dialog>
    <v-dialog v-model="statusDialog" max-width="560" persistent><v-card>
      <v-card-title>{{ statusTarget==='recognize' ? 'Post approval to accounting' : 'Confirm commission status' }}</v-card-title>
      <v-card-text>
        <v-alert v-if="statusTarget==='recognize'" type="info" text dense>Record the existing approved commission as Sales Commission Expense and Commission Payable on the selected accounting date.</v-alert>
        <v-alert v-else type="info" text dense>Change this commission from {{statusItem && statusItem.status}} to {{statusTarget}}. Approval records the payable; payment settles it; cancellation reverses the approval.</v-alert>
        <v-alert v-if="statusTarget==='paid' && statusItem && !statusItem.accrual_journal" type="warning" text dense>Use “Post approval to accounting” for this historical commission before recording its payment.</v-alert>
        <v-text-field v-model="accountingDate" type="date" label="Accounting date *" outlined dense />
        <v-autocomplete v-if="statusTarget==='paid'" v-model="cashAccountId" :items="cashAccounts" item-text="label" item-value="id" label="Paid from cash / bank account *" outlined dense :loading="accountsLoading" />
        <v-textarea v-if="statusTarget==='cancelled'" v-model="cancelReason" label="Cancellation reason *" outlined dense rows="2" />
      </v-card-text><v-card-actions><v-spacer /><v-btn text :disabled="statusSaving" @click="statusDialog=false">Cancel</v-btn><v-btn color="primary" :loading="statusSaving" :disabled="statusSaving || !accountingDate || (statusTarget==='paid' && (accountsLoading || !cashAccountId || !statusItem || !statusItem.accrual_journal)) || (statusTarget==='cancelled' && !cancelReason.trim())" @click="confirmStatusChange">Confirm</v-btn></v-card-actions>
    </v-card></v-dialog>
    <v-dialog v-model="historyDialog" max-width="760"><v-card v-if="historyItem">
      <v-card-title>Commission #{{historyItem.id}} accounting history</v-card-title>
      <v-card-text>
        <div>Approved amount: <strong>PKR {{money(historyItem.commission_amount)}}</strong></div>
        <div>Approval journal: <strong>{{historyItem.accrual_journal ? historyItem.accrual_journal.entry_number : 'Not posted'}}</strong><span v-if="historyItem.accrual_journal"> · {{dateOnly(historyItem.accrual_journal.entry_date)}}</span></div>
        <div v-if="historyItem.cancellation_journal">Cancellation: {{historyItem.cancellation_journal.entry_number}} · {{dateOnly(historyItem.cancellation_journal.entry_date)}}</div>
        <v-alert v-if="!(historyItem.payments || []).length" type="info" text dense class="mt-4">No accounting payment records. Historical payments are not automatically imported.</v-alert>
        <v-card v-for="payment in historyItem.payments" :key="payment.id" outlined class="pa-3 mt-3">
          <div><strong>Payment #{{payment.id}} · PKR {{money(payment.amount)}}</strong> · {{dateOnly(payment.payment_date)}} · {{payment.reversed_at ? 'Reversed' : 'Paid'}}</div>
          <div>{{payment.cash_bank_account ? payment.cash_bank_account.code+' — '+payment.cash_bank_account.name : ''}}</div>
          <div>Journal: {{payment.journal_entry ? payment.journal_entry.entry_number : 'Missing'}}</div>
          <div v-if="payment.reversal_journal">Reversal: {{payment.reversal_journal.entry_number}} · {{dateOnly(payment.reversal_date)}} · {{payment.reversal_reason}}</div>
        </v-card>
      </v-card-text><v-card-actions><v-spacer /><v-btn text @click="historyDialog=false">Close</v-btn></v-card-actions>
    </v-card></v-dialog>
  </div>
</template>
<script>
import api from '../../../services/api'
import FinancialDocumentsDialog from '../../../components/FinancialDocumentsDialog.vue'

export default {
  name:'Commissions', components:{FinancialDocumentsDialog},
  data:()=>({
    loading:false,createDialog:false,saving:false,loadingBookings:false,documentsDialog:false,documentEntity:null,
    bookings:[],form:{booking_id:null,agent_id:null,percentage:null,notes:''},
    reverseDialog:false,reversing:false,reverseItem:null,reverseReason:'',reverseError:[],reversalDate:'',
    statusDialog:false,statusSaving:false,statusItem:null,statusTarget:null,accountingDate:'',cashAccountId:null,cashAccounts:[],accountsLoading:false,cancelReason:'',requestKey:'',
    historyDialog:false,historyItem:null,items:[],total:0,agents:[],options:{page:1,itemsPerPage:15},filters:{status:null,agent_id:null},statuses:['pending','approved','paid','cancelled'],
    headers:[{text:'Agent',value:'agent.name'},{text:'Customer',value:'booking.customer.name'},{text:'Booking',value:'booking.booking_number'},{text:'Base Amount',value:'base_amount',align:'right'},{text:'Rate',value:'percentage',align:'right'},{text:'Commission',value:'commission_amount',align:'right'},{text:'Status',value:'status'},{text:'Approval journal',value:'journal'},{text:'',value:'actions',sortable:false}]
  }),
  watch:{options:{deep:true,handler(){this.load()}},'$route.query.status':function(v){this.filters.status=v||null;this.options.page=1;this.load()}},
  computed:{
    selectedBooking(){return this.bookings.find(b=>Number(b.id)===Number(this.form.booking_id))||null},
    calculatedCommission(){return this.selectedBooking?Number(this.selectedBooking.final_price||0)*Number(this.form.percentage||0)/100:0},
    amountShown(){return this.items.filter(x=>x.status!=='cancelled').reduce((n,x)=>n+Number(x.commission_amount||0),0)}
  },
  async mounted(){if(this.$route.query.status)this.filters.status=this.$route.query.status;this.load();await this.loadAgents();if(this.$route.query.booking_id)this.openCreateFromQuery()},
  methods:{
    today(){const d=new Date();return [d.getFullYear(),String(d.getMonth()+1).padStart(2,'0'),String(d.getDate()).padStart(2,'0')].join('-')},
    dateOnly(v){return v?String(v).slice(0,10):''},
    errorText(e,fallback){const d=e.response&&e.response.data;if(d&&d.errors){const key=Object.keys(d.errors)[0];if(key)return d.errors[key][0]}return d&&d.message||fallback},
    openDocuments(item){this.documentEntity=item;this.documentsDialog=true},
    statusCount(status){return this.items.filter(x=>x.status===status).length},
    async openCreate(){this.form={booking_id:null,agent_id:null,percentage:null,notes:''};this.createDialog=true;await this.loadBookings()},
    async openCreateFromQuery(){await this.openCreate();const id=Number(this.$route.query.booking_id||0);const booking=this.bookings.find(b=>Number(b.id)===id);if(booking){this.form.booking_id=booking.id;this.bookingChanged()}},
    async loadBookings(){this.loadingBookings=true;try{const r=await api.get('/bookings',{params:{per_page:100}});this.bookings=(r.data.data||[]).filter(b=>b.status!=='cancelled')}catch(e){this.bookings=[];this.$root.$emit('show-error',this.errorText(e,'Unable to load eligible bookings.'))}finally{this.loadingBookings=false}},
    bookingLabel(b){const property=b.property?(b.property.property_number||'Property'):'Property';const project=b.property&&b.property.project?b.property.project.name:'';return (b.booking_number||('Booking #'+b.id))+' — '+property+(project?' / '+project:'')+' — '+(b.customer?b.customer.name:'Customer')+' — PKR '+this.money(b.final_price)},
    bookingChanged(){const b=this.selectedBooking;this.form.agent_id=b?b.sales_agent_id:null},
    async createCommission(addDocument){
      if(this.saving)return
      if(!this.form.booking_id||!this.form.agent_id||!(Number(this.form.percentage)>0)||Number(this.form.percentage)>100){this.$root.$emit('show-error','Select a booking and enter a commission rate above 0 and up to 100%.');return}
      this.saving=true
      try{const r=await api.post('/commissions',this.form);const saved=r.data.commission;this.createDialog=false;await this.load();if(addDocument&&saved){this.documentEntity=saved;this.documentsDialog=true}}
      catch(e){this.$root.$emit('show-error',this.errorText(e,'Unable to create commission.'))}finally{this.saving=false}
    },
    async load(){this.loading=true;try{const r=await api.get('/commissions',{params:{...this.filters,page:this.options.page,per_page:this.options.itemsPerPage}});this.items=r.data.data||[];this.total=r.data.total||0}catch(e){this.$root.$emit('show-error',this.errorText(e,'Unable to load commissions.'))}finally{this.loading=false}},
    async loadAgents(){try{this.agents=(await api.get('/sales-agents')).data.data||[]}catch(e){this.agents=[]}},
    money(v){return new Intl.NumberFormat('en-PK',{minimumFractionDigits:2,maximumFractionDigits:2}).format(Number(v||0))},
    statusColor(status){return {pending:'orange',approved:'blue',paid:'success',cancelled:'grey'}[status]||'grey'},
    nextStatuses(status){if(status==='pending')return ['approved','cancelled'];if(status==='approved')return ['paid','cancelled'];return []},
    async openStatusChange(item,status){
      this.statusItem=item;this.statusTarget=status;this.accountingDate=status==='recognize'?(this.dateOnly(item.approved_date)||this.today()):this.today();this.cashAccountId=null;this.cancelReason='';this.requestKey='commission-'+item.id+'-'+Date.now()+'-'+Math.random().toString(36).slice(2);this.statusDialog=true
      if(status==='paid'){this.accountsLoading=true;this.cashAccounts=[];try{const r=await api.get('/commissions/posting-accounts');this.cashAccounts=(r.data.data||[]).map(a=>({...a,label:a.code+' — '+a.name}))}catch(e){this.$root.$emit('show-error',this.errorText(e,'Unable to load cash/bank accounts.'))}finally{this.accountsLoading=false}}
    },
    async confirmStatusChange(){
      if(this.statusSaving||!this.statusItem||!this.statusTarget)return
      this.statusSaving=true
      try{
        if(this.statusTarget==='recognize')await api.post('/commissions/'+this.statusItem.id+'/recognize',{accounting_date:this.accountingDate})
        else await api.put('/commissions/'+this.statusItem.id,{status:this.statusTarget,accounting_date:this.accountingDate,cash_bank_account_id:this.cashAccountId,request_key:this.requestKey,reason:this.cancelReason||null})
        this.statusDialog=false;await this.load()
      }catch(e){this.$root.$emit('show-error',this.errorText(e,'Unable to update commission.'))}finally{this.statusSaving=false}
    },
    openReverse(item){this.reverseItem=item;this.reverseReason='';this.reverseError=[];this.reversalDate=this.today();this.reverseDialog=true},
    async reversePayment(){
      if(this.reversing||!this.reverseItem)return
      this.reverseError=[]
      if(!this.reverseReason.trim()||!this.reversalDate){this.reverseError=['Reversal date and reason are required.'];return}
      this.reversing=true
      try{await api.post('/commissions/'+this.reverseItem.id+'/reverse',{reason:this.reverseReason.trim(),reversal_date:this.reversalDate,payment_id:((this.reverseItem.payments||[]).find(p=>!p.reversed_at)||{}).id||null});this.reverseDialog=false;await this.load()}
      catch(e){this.reverseError=[this.errorText(e,'Unable to reverse commission payment.')]}finally{this.reversing=false}
    }
  }
}
</script><style scoped>.page{width:100%}.hero{border-left:4px solid #165134}.summary-card,.filter-card,.table-card{border:1px solid rgba(22,81,52,.08);border-radius:15px!important}.page ::v-deep .v-data-table__wrapper{overflow-x:auto}</style>
