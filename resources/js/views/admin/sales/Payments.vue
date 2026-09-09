<template>
  <div class="page">
    <v-card flat class="hero pa-5 mb-4">
      <div class="d-flex flex-wrap align-center">
        <div><div class="text-overline">FINANCE</div><h1 class="text-h5 font-weight-bold">Payments</h1><div class="grey--text">Record and review customer collections.</div></div>
        <v-spacer/><v-btn color="#165134" dark @click="openCreate"><v-icon left>mdi-cash-plus</v-icon>Record Payment</v-btn>
      </div>
    </v-card>
    <v-card flat outlined>
      <v-card-text><v-row dense><v-col cols="12" md="6"><v-text-field v-model="from" outlined dense type="date" label="Payment from" @change="load"/></v-col><v-col cols="12" md="6"><v-text-field v-model="to" outlined dense type="date" label="Payment to" @change="load"/></v-col></v-row></v-card-text>
      <v-data-table :headers="headers" :items="items" :loading="loading" :options.sync="options" :server-items-length="total">
        <template v-slot:item.amount="{item}"><strong>{{ money(item.amount) }}</strong></template>
        <template v-slot:item.payment_date="{item}">{{ item.payment_date | dateOnly }}</template>
        <template v-slot:item.status="{item}"><v-chip x-small color="success" outlined>{{ item.status }}</v-chip></template>
        <template v-slot:item.actions="{item}"><v-btn icon small @click="view(item)"><v-icon small>mdi-eye</v-icon></v-btn><v-btn icon small color="#165134" @click="receipt(item)" :loading="receiptLoading===item.id"><v-icon small>mdi-file-pdf-box</v-icon></v-btn></template>
        <template v-slot:no-data><div class="pa-8 grey--text">No payments found.</div></template>
      </v-data-table>
    </v-card>
    <v-dialog v-model="dialog" max-width="680"><v-card><v-card-title>Record Payment</v-card-title><v-card-text><v-alert v-if="selectedBooking" type="info" text dense class="mb-4">Booking {{ selectedBooking.booking_number }} — Outstanding: <strong>{{ money(selectedBooking.remaining_amount) }}</strong></v-alert><v-row>
      <v-col cols="12" md="6"><v-autocomplete v-model="form.customer_id" :items="customers" item-text="name" item-value="id" outlined dense label="Customer *" @change="loadBookings"/></v-col>
      <v-col cols="12" md="6"><v-autocomplete v-model="form.booking_id" :items="bookings" item-text="booking_number" item-value="id" outlined dense label="Booking *" @change="loadInstallments"/></v-col>
      <v-col cols="12" md="6"><v-autocomplete v-model="form.installment_id" :items="installments" item-text="installmentLabel" item-value="id" outlined dense clearable label="Installment (optional)"/></v-col>
      <v-col cols="12" md="6"><v-text-field v-model="form.amount" type="number" min="0.01" step="0.01" outlined dense label="Amount *"/></v-col>
      <v-col cols="12" md="6"><v-select v-model="form.payment_method" :items="methods" outlined dense label="Payment Method *"/></v-col><v-col cols="12" md="6"><v-text-field v-model="form.payment_date" type="date" outlined dense label="Payment Date *"/></v-col>
      <v-col cols="12" md="6"><v-text-field v-model="form.reference_number" outlined dense label="Reference #"/></v-col><v-col cols="12" md="6"><v-text-field v-model="form.bank_name" outlined dense label="Bank Name"/></v-col><v-col cols="12" md="6"><v-text-field v-model="form.cheque_number" outlined dense label="Cheque #"/></v-col><v-col cols="12"><v-textarea v-model="form.notes" outlined dense rows="2" label="Notes"/></v-col>
    </v-row></v-card-text><v-card-actions><v-spacer/><v-btn text @click="dialog=false">Cancel</v-btn><v-btn color="#165134" dark :loading="saving" @click="save">Save Payment</v-btn></v-card-actions></v-card></v-dialog>
    <v-dialog v-model="detailsDialog" max-width="520"><v-card v-if="selectedPayment"><v-card-title>Payment Receipt</v-card-title><v-card-text><div class="receipt"><div class="text-h6 font-weight-bold">{{ selectedPayment.receipt_number }}</div><div class="mt-3"><strong>Customer:</strong> {{ customerName(selectedPayment) }}</div><div><strong>Booking:</strong> {{ bookingNumber(selectedPayment) }}</div><div><strong>Amount:</strong> {{ money(selectedPayment.amount) }}</div><div><strong>Method:</strong> {{ selectedPayment.payment_method }}</div><div><strong>Date:</strong> {{ selectedPayment.payment_date | dateOnly }}</div><div v-if="selectedPayment.reference_number"><strong>Reference:</strong> {{ selectedPayment.reference_number }}</div></div></v-card-text><v-card-actions><v-spacer/><v-btn text @click="detailsDialog=false">Close</v-btn><v-btn color="#165134" dark @click="receipt(selectedPayment)"><v-icon left>mdi-file-pdf-box</v-icon>Receipt PDF</v-btn></v-card-actions></v-card></v-dialog>
  </div>
</template>
<script>
import api from '../../../services/api'
export default {
  name: 'Payments',
  data: () => ({loading:false,saving:false,receiptLoading:null,dialog:false,detailsDialog:false,items:[],total:0,from:null,to:null,options:{page:1,itemsPerPage:15},customers:[],bookings:[],installments:[],selectedPayment:null,selectedBooking:null,methods:['cash','bank_transfer','cheque','online','other'],headers:[{text:'Receipt',value:'receipt_number'},{text:'Customer',value:'customer.name'},{text:'Booking',value:'booking.booking_number'},{text:'Amount',value:'amount',align:'right'},{text:'Method',value:'payment_method'},{text:'Date',value:'payment_date'},{text:'Status',value:'status'},{text:'',value:'actions',sortable:false}],form:{customer_id:null,booking_id:null,installment_id:null,amount:null,payment_method:'cash',payment_date:new Date().toISOString().slice(0,10),reference_number:'',bank_name:'',cheque_number:'',notes:''}}),
  watch:{options:{deep:true,handler(){this.load()}}},
  filters:{dateOnly(v){return v?String(v).slice(0,10):''}},
  mounted(){this.load();this.loadCustomers()},
  methods:{
    async load(){this.loading=true;try{const r=await api.get('/payments',{params:{page:this.options.page,per_page:this.options.itemsPerPage,from:this.from,to:this.to}});this.items=r.data.data||[];this.total=r.data.total||0}catch(e){this.$root.$emit('show-error',(e.response&&e.response.data&&e.response.data.message)||'Unable to load payments.')}finally{this.loading=false}},
    async loadCustomers(){try{const r=await api.get('/customers',{params:{per_page:100}});this.customers=r.data.data||r.data||[]}catch(e){}},
    async loadBookings(){this.form.booking_id=null;this.form.installment_id=null;this.bookings=[];this.installments=[];if(!this.form.customer_id)return;try{const r=await api.get('/bookings',{params:{customer_id:this.form.customer_id,per_page:100}});this.bookings=r.data.data||r.data||[]}catch(e){}},
    async loadInstallments(){this.form.installment_id=null;this.installments=[];this.selectedBooking=this.bookings.find(b=>b.id===this.form.booking_id)||null;if(!this.form.booking_id)return;try{const r=await api.get('/installments',{params:{booking_id:this.form.booking_id,per_page:100}});this.installments=(r.data.data||[]).filter(i=>Number(i.remaining_amount)>0).map(i=>Object.assign({},i,{installmentLabel:'#'+i.installment_number+' — '+this.money(i.remaining_amount)+' remaining — '+String(i.due_date).slice(0,10)}))}catch(e){}},
    openCreate(){this.form={customer_id:null,booking_id:null,installment_id:null,amount:null,payment_method:'cash',payment_date:new Date().toISOString().slice(0,10),reference_number:'',bank_name:'',cheque_number:'',notes:''};this.bookings=[];this.installments=[];this.selectedBooking=null;this.dialog=true},
    async save(){if(!this.form.customer_id||!this.form.booking_id||!this.form.amount){this.$root.$emit('show-error','Customer, booking and amount are required.');return}this.saving=true;try{await api.post('/payments',this.form);this.dialog=false;await this.load();this.$root.$emit('show-success','Payment recorded successfully.')}catch(e){this.$root.$emit('show-error',(e.response&&e.response.data&&e.response.data.message)||'Unable to record payment.')}finally{this.saving=false}},
    async view(item){try{const r=await api.get('/payments/'+item.id);this.selectedPayment=r.data;this.detailsDialog=true}catch(e){this.$root.$emit('show-error','Unable to load payment details.')}},
    async receipt(item){this.receiptLoading=item.id;try{const r=await api.get('/payments/'+item.id+'/receipt',{responseType:'blob'});const url=URL.createObjectURL(new Blob([r.data],{type:'application/pdf'}));const w=window.open(url,'_blank');if(!w)this.$root.$emit('show-error','Please allow pop-ups to view the receipt.');setTimeout(()=>URL.revokeObjectURL(url),60000)}catch(e){this.$root.$emit('show-error','Unable to generate receipt PDF.')}finally{this.receiptLoading=null}},
    customerName(payment){return payment&&payment.customer&&payment.customer.name?payment.customer.name:'—'},
    bookingNumber(payment){return payment&&payment.booking&&payment.booking.booking_number?payment.booking.booking_number:'—'},
    money(v){return new Intl.NumberFormat('en-PK',{maximumFractionDigits:0}).format(Number(v||0))}
  }
}
</script>
<style scoped>.page{width:100%}.hero{border-left:4px solid #165134}.receipt{line-height:2}.page ::v-deep .v-data-table__wrapper{overflow-x:auto}</style>
