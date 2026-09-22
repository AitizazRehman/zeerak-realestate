<template>
<div class="page">
  <v-card flat class="hero pa-5 mb-4"><div class="d-flex flex-wrap align-center"><div><div class="text-overline">BUSINESS INTELLIGENCE</div><h1 class="text-h5 font-weight-bold">Sales & Financial Reports</h1><div class="grey--text">Review sales, collections, receivables, installments, expenses and commissions.</div></div><v-spacer/><v-btn color="#165134" dark :loading="loading" @click="loadAll"><v-icon left>mdi-refresh</v-icon>Refresh</v-btn></div></v-card>

  <v-card outlined class="mb-4"><v-card-text><v-row dense><v-col cols="12" md="4"><v-text-field v-model="filters.from" type="date" outlined dense label="From"/></v-col><v-col cols="12" md="4"><v-text-field v-model="filters.to" type="date" outlined dense label="To"/></v-col><v-col cols="12" md="4"><v-btn block color="#165134" dark height="40" @click="applyFilters"><v-icon left>mdi-filter</v-icon>Apply Filters</v-btn></v-col></v-row></v-card-text></v-card>

  <v-alert v-if="error" type="error" dense text>{{ error }}</v-alert>
  <v-row><v-col v-for="card in cards" :key="card.key" cols="12" sm="6" lg="3"><v-card outlined class="fill-height"><v-card-text><div class="caption grey--text text-uppercase">{{ card.label }}</div><div class="text-h5 font-weight-bold mt-1">{{ card.money ? money(metrics[card.key]) : (metrics[card.key] || 0) }}</div></v-card-text></v-card></v-col></v-row>

  <v-card outlined class="mt-5">
    <v-tabs v-model="tab" color="#165134" show-arrows><v-tab>Sales</v-tab><v-tab>Collections</v-tab><v-tab>Installments</v-tab><v-tab>Expenses</v-tab><v-tab>Commissions</v-tab></v-tabs>
    <v-divider/>
    <v-tabs-items v-model="tab">
      <v-tab-item><v-data-table :headers="salesHeaders" :items="sales" :loading="loading"><template v-slot:item.final_price="{item}">{{ money(item.final_price) }}</template><template v-slot:item.paid_amount="{item}">{{ money(item.paid_amount) }}</template><template v-slot:item.remaining_amount="{item}">{{ money(item.remaining_amount) }}</template><template v-slot:item.booking_date="{item}">{{ date(item.booking_date) }}</template></v-data-table></v-tab-item>
      <v-tab-item><v-data-table :headers="collectionHeaders" :items="collections" :loading="loading"><template v-slot:item.amount="{item}"><strong>{{ money(item.amount) }}</strong></template><template v-slot:item.payment_date="{item}">{{ date(item.payment_date) }}</template></v-data-table></v-tab-item>
      <v-tab-item><div class="pa-3 d-flex justify-end"><v-switch v-model="overdueOnly" dense inset label="Overdue only" @change="loadInstallments"/></div><v-data-table :headers="installmentHeaders" :items="installments" :loading="loading"><template v-slot:item.amount="{item}">{{ money(item.amount) }}</template><template v-slot:item.remaining_amount="{item}"><strong>{{ money(item.remaining_amount) }}</strong></template><template v-slot:item.due_date="{item}">{{ date(item.due_date) }}</template><template v-slot:item.status="{item}"><v-chip x-small outlined :color="isOverdue(item)?'error':'primary'">{{ isOverdue(item)?'overdue':item.status }}</v-chip></template></v-data-table></v-tab-item>
      <v-tab-item><v-data-table :headers="expenseHeaders" :items="expenses" :loading="loading"><template v-slot:item.amount="{item}"><strong>{{ money(item.amount) }}</strong></template><template v-slot:item.expense_date="{item}">{{ date(item.expense_date) }}</template></v-data-table></v-tab-item>
      <v-tab-item><v-data-table :headers="commissionHeaders" :items="commissions" :loading="loading"><template v-slot:item.commission_amount="{item}"><strong>{{ money(item.commission_amount) }}</strong></template></v-data-table></v-tab-item>
    </v-tabs-items>
  </v-card>
</div>
</template>
<script>
import api from '../../../services/api'
export default {
 name:'Reports',
 data(){return{loading:false,error:'',tab:0,overdueOnly:false,metrics:{},sales:[],collections:[],installments:[],expenses:[],commissions:[],filters:{from:new Date(new Date().getFullYear(),0,1).toISOString().slice(0,10),to:new Date().toISOString().slice(0,10)},cards:[{label:'Sales Value',key:'sales_value',money:true},{label:'Collections',key:'collections',money:true},{label:'Receivables',key:'receivables',money:true},{label:'Overdue Amount',key:'overdue_amount',money:true},{label:'Expenses',key:'expenses',money:true},{label:'Commissions',key:'commissions',money:true},{label:'Sales',key:'sales_count',money:false},{label:'Overdue Installments',key:'overdue_count',money:false}],salesHeaders:[{text:'Booking',value:'booking_number'},{text:'Customer',value:'customer.name'},{text:'Property',value:'property.property_number'},{text:'Project',value:'property.project.name'},{text:'Date',value:'booking_date'},{text:'Sale Value',value:'final_price',align:'right'},{text:'Paid',value:'paid_amount',align:'right'},{text:'Balance',value:'remaining_amount',align:'right'}],collectionHeaders:[{text:'Receipt',value:'receipt_number'},{text:'Customer',value:'customer.name'},{text:'Booking',value:'booking.booking_number'},{text:'Date',value:'payment_date'},{text:'Method',value:'payment_method'},{text:'Amount',value:'amount',align:'right'}],installmentHeaders:[{text:'#',value:'installment_number'},{text:'Customer',value:'booking.customer.name'},{text:'Property',value:'booking.property.property_number'},{text:'Due Date',value:'due_date'},{text:'Amount',value:'amount',align:'right'},{text:'Remaining',value:'remaining_amount',align:'right'},{text:'Status',value:'status'}],expenseHeaders:[{text:'Expense #',value:'expense_number'},{text:'Project',value:'project.name'},{text:'Category',value:'category'},{text:'Vendor',value:'vendor_name'},{text:'Date',value:'expense_date'},{text:'Amount',value:'amount',align:'right'}],commissionHeaders:[{text:'Booking',value:'booking.booking_number'},{text:'Customer',value:'booking.customer.name'},{text:'Agent',value:'agent.name'},{text:'Percentage',value:'percentage'},{text:'Commission',value:'commission_amount',align:'right'},{text:'Status',value:'status'}] }},
 mounted(){this.loadAll()},
 methods:{
  params(){return{from:this.filters.from,to:this.filters.to,per_page:100}},
  async loadAll(){this.loading=true;this.error='';try{const p=this.params();const r=await Promise.all([api.get('/reports/summary',{params:p}),api.get('/reports/sales',{params:p}),api.get('/reports/collections',{params:p}),api.get('/reports/installments',{params:Object.assign({},p,{overdue:this.overdueOnly})}),api.get('/reports/expenses',{params:p}),api.get('/reports/commissions',{params:p})]);this.metrics=r[0].data.metrics||{};this.sales=r[1].data.data||[];this.collections=r[2].data.data||[];this.installments=r[3].data.data||[];this.expenses=r[4].data.data||[];this.commissions=r[5].data.data||[]}catch(e){this.error=(e.response&&e.response.data&&e.response.data.message)||'Unable to load reports.'}finally{this.loading=false}},
  applyFilters(){this.loadAll()},
  async loadInstallments(){try{const r=await api.get('/reports/installments',{params:Object.assign({},this.params(),{overdue:this.overdueOnly})});this.installments=r.data.data||[]}catch(e){this.error='Unable to load installment report.'}},
  money(v){return 'PKR '+new Intl.NumberFormat('en-PK',{maximumFractionDigits:0}).format(Number(v||0))},
  date(v){return v?String(v).slice(0,10):'—'},
  isOverdue(i){return i&&i.due_date&&String(i.due_date).slice(0,10)<new Date().toISOString().slice(0,10)&&Number(i.remaining_amount)>0}
 }
}
</script>
<style scoped>.page{width:100%}.hero{border-left:4px solid #165134}.page ::v-deep .v-data-table__wrapper{overflow-x:auto}</style>
