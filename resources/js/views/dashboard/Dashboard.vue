<template>
<div class="dashboard">
  <v-card flat class="hero pa-5 mb-5">
    <div class="d-flex flex-wrap align-center">
      <v-avatar tile size="70" color="white" class="mr-4"><v-img src="/images/zeerak-logo.jpeg" contain/></v-avatar>
      <div><div class="text-overline">ZEERAK REAL ESTATE & BUILDERS</div><h1 class="text-h4 font-weight-bold">Management Dashboard</h1><div class="grey--text">Live business, sales, inventory and collection overview.</div></div>
      <v-spacer/><div class="d-flex flex-wrap quick-actions"><v-btn v-if="$can('leads.create')" small depressed color="#165134" dark to="/admin/leads"><v-icon left small>mdi-account-plus</v-icon>New Lead</v-btn><v-btn v-if="$can('properties.create')" small outlined color="#165134" to="/admin/properties/create"><v-icon left small>mdi-home-plus</v-icon>Add Property</v-btn><v-btn text small color="#165134" :loading="loading" @click="load"><v-icon left small>mdi-refresh</v-icon>Refresh</v-btn></div>
    </div>
  </v-card>

  <v-alert v-if="error" type="error" dense text>{{ error }}</v-alert>
  <v-row><v-col v-for="card in cards" :key="card.key" cols="12" sm="6" lg="3"><v-card flat class="stat fill-height"><v-card-text class="d-flex align-center pa-5"><v-avatar size="50" :class="'soft-'+card.tone" class="mr-4"><v-icon :color="card.color">{{card.icon}}</v-icon></v-avatar><div class="min-width-0"><div class="caption grey--text text-uppercase font-weight-medium">{{card.label}}</div><div class="text-h6 font-weight-bold text-truncate">{{card.money?'PKR '+format(metrics[card.key]):format(metrics[card.key])}}</div></div></v-card-text></v-card></v-col></v-row>

  <v-row class="mt-2">
    <v-col cols="12" lg="7"><v-card outlined class="fill-height"><v-card-title>Monthly Collections<v-spacer/><v-btn text small color="#165134" to="/admin/reports">Reports</v-btn></v-card-title><v-card-text><div v-if="!monthly.length" class="empty">No collection activity.</div><div v-for="m in monthly" :key="m.month" class="mb-4"><div class="d-flex justify-space-between caption mb-1"><span>{{month(m.month)}}</span><strong>PKR {{format(m.amount)}}</strong></div><v-progress-linear rounded height="10" color="#165134" :value="percent(m.amount)"/></div></v-card-text></v-card></v-col>
    <v-col cols="12" lg="5"><v-card outlined class="fill-height"><v-card-title>Property Pipeline</v-card-title><v-card-text><div v-for="p in pipeline" :key="p.label" class="pipeline mb-4"><div class="d-flex justify-space-between mb-1"><span>{{p.label}}</span><strong>{{p.value}}</strong></div><v-progress-linear rounded height="10" :color="p.color" :value="p.percent"/></div><v-btn block outlined color="#165134" to="/admin/properties">Open Property Inventory</v-btn></v-card-text></v-card></v-col>
  </v-row>

  <v-row>
    <v-col cols="12" lg="6"><v-card outlined><v-card-title>Recent Bookings<v-spacer/><v-btn text small to="/admin/bookings">View all</v-btn></v-card-title><v-data-table dense hide-default-footer :headers="bookingHeaders" :items="recentBookings"><template v-slot:item.final_price="{item}">PKR {{format(item.final_price)}}</template><template v-slot:item.booking_date="{item}">{{date(item.booking_date)}}</template></v-data-table></v-card></v-col>
    <v-col cols="12" lg="6"><v-card outlined><v-card-title>Recent Payments<v-spacer/><v-btn text small to="/admin/payments">View all</v-btn></v-card-title><v-data-table dense hide-default-footer :headers="paymentHeaders" :items="recentPayments"><template v-slot:item.amount="{item}"><strong>PKR {{format(item.amount)}}</strong></template><template v-slot:item.payment_date="{item}">{{date(item.payment_date)}}</template></v-data-table></v-card></v-col>
  </v-row>

  <v-row>
    <v-col cols="12" lg="6"><v-card outlined><v-card-title>Top Sales Agents</v-card-title><v-data-table dense hide-default-footer :headers="agentHeaders" :items="agents"><template v-slot:item.sales_value="{item}">PKR {{format(item.sales_value)}}</template></v-data-table></v-card></v-col>
    <v-col cols="12" lg="6"><v-card outlined><v-card-title>Project Performance</v-card-title><v-data-table dense hide-default-footer :headers="projectHeaders" :items="projects"><template v-slot:item.sales_value="{item}">PKR {{format(item.sales_value)}}</template></v-data-table></v-card></v-col>
  </v-row>
</div>
</template>
<script>
import api from '../../services/api'
export default{
 name:'Dashboard',
 data(){return{loading:false,error:'',metrics:{},monthly:[],agents:[],projects:[],recentBookings:[],recentPayments:[],
 cards:[
 {key:'sales_value',label:'Sales Value',money:true,icon:'mdi-cash-multiple',color:'green darken-2',tone:'green'},
 {key:'collections',label:'Collections',money:true,icon:'mdi-bank-check',color:'teal',tone:'green'},
 {key:'receivables',label:'Receivables',money:true,icon:'mdi-cash-clock',color:'orange darken-2',tone:'amber'},
 {key:'overdue_amount',label:'Overdue',money:true,icon:'mdi-alert-circle',color:'red',tone:'red'},
 {key:'available_properties',label:'Available Properties',icon:'mdi-home-check',color:'green',tone:'green'},
 {key:'sold_properties',label:'Sold Properties',icon:'mdi-home-lock',color:'blue',tone:'blue'},
 {key:'expenses',label:'Expenses',money:true,icon:'mdi-cash-minus',color:'deep-orange',tone:'amber'},
 {key:'net_cash_flow',label:'Net Cash Flow',money:true,icon:'mdi-chart-line',color:'purple',tone:'purple'}],
 bookingHeaders:[{text:'Booking',value:'booking_number'},{text:'Customer',value:'customer.name'},{text:'Property',value:'property.property_number'},{text:'Date',value:'booking_date'},{text:'Value',value:'final_price',align:'right'}],
 paymentHeaders:[{text:'Receipt',value:'receipt_number'},{text:'Customer',value:'customer.name'},{text:'Booking',value:'booking.booking_number'},{text:'Date',value:'payment_date'},{text:'Amount',value:'amount',align:'right'}],
 agentHeaders:[{text:'Agent',value:'sales_agent.name'},{text:'Bookings',value:'bookings'},{text:'Sales',value:'sales_value',align:'right'}],
 projectHeaders:[{text:'Project',value:'project_name'},{text:'Bookings',value:'bookings'},{text:'Sales',value:'sales_value',align:'right'}]
 }},
 computed:{pipeline(){const a=[['Available','available_properties','green'],['Reserved','reserved_properties','orange'],['Booked','booked_properties','blue'],['Sold','sold_properties','red']].map(x=>({label:x[0],value:Number(this.metrics[x[1]]||0),color:x[2]}));const max=Math.max(...a.map(x=>x.value),1);return a.map(x=>Object.assign(x,{percent:x.value/max*100}))},maxCollection(){return Math.max(...this.monthly.map(x=>Number(x.amount||0)),1)}},
 mounted(){this.load()},
 methods:{async load(){this.loading=true;this.error='';try{const r=await api.get('/sales/dashboard');const d=r.data||{};this.metrics=d.metrics||{};this.monthly=d.monthly_collections||[];this.agents=d.agent_performance||[];this.projects=d.project_performance||[];this.recentBookings=d.recent_bookings||[];this.recentPayments=d.recent_payments||[]}catch(e){this.error=(e.response&&e.response.data&&e.response.data.message)||'Unable to load dashboard.'}finally{this.loading=false}},format(v){return new Intl.NumberFormat('en-PK',{maximumFractionDigits:0}).format(Number(v||0))},date(v){return v?String(v).slice(0,10):'—'},month(v){const p=String(v).split('-');return p.length===2?p[1]+'/'+p[0]:v},percent(v){return Number(v||0)/this.maxCollection*100}}
}
</script>
<style scoped>.dashboard{width:100%}.hero{border-left:4px solid #165134}.stat{border:1px solid rgba(15,70,43,.08);box-shadow:0 8px 24px rgba(20,50,35,.05)!important;border-radius:16px;transition:.2s}.soft-green{background:#e8f5ee!important}.soft-amber{background:#fff5df!important}.soft-red{background:#fdecec!important}.soft-blue{background:#eaf2ff!important}.soft-purple{background:#f3eefe!important}.quick-actions{gap:8px}.min-width-0{min-width:0}.stat:hover{transform:translateY(-2px)}.empty{min-height:120px;display:flex;align-items:center;justify-content:center;color:#888}</style>