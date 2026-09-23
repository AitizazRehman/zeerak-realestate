<template>
  <div class="sales-dashboard">
    <v-card flat class="hero pa-5 mb-4">
      <div class="d-flex flex-wrap align-center">
        <div>
          <div class="text-overline">SALES & FINANCE</div>
          <h1 class="text-h5 font-weight-bold">Sales Dashboard</h1>
          <div class="grey--text">Live overview of CRM, inventory, collections, receivables and operating cash flow.</div>
        </div>
        <v-spacer/>
        <div class="quick-actions d-flex flex-wrap">
          <v-btn v-if="$can('payments.create')" small color="#165134" dark depressed to="/admin/payments"><v-icon left small>mdi-cash-plus</v-icon>Payment</v-btn>
          <v-btn v-if="$can('installments.view')" small outlined color="#165134" :to="{name:'installments',query:{status:'overdue'}}"><v-icon left small>mdi-alert-clock-outline</v-icon>Overdue</v-btn>
          <v-btn v-if="$can('expenses.create')" small outlined color="#165134" to="/admin/expenses"><v-icon left small>mdi-cash-minus</v-icon>Expense</v-btn>
          <v-btn text small color="#165134" :loading="loading" @click="load"><v-icon left small>mdi-refresh</v-icon>Refresh</v-btn>
        </div>
      </div>
    </v-card>

    <v-card flat class="filter-card pa-4 mb-4">
      <v-row dense align="center">
        <v-col cols="12" sm="4"><v-text-field v-model="filters.from" type="date" label="From date" outlined dense hide-details/></v-col>
        <v-col cols="12" sm="4"><v-text-field v-model="filters.to" type="date" label="To date" outlined dense hide-details/></v-col>
        <v-col cols="12" sm="4" class="text-sm-right">
          <v-btn text color="grey darken-1" :disabled="loading || (!filters.from && !filters.to)" @click="clearFilters"><v-icon left>mdi-filter-remove</v-icon>Clear</v-btn>
          <v-btn color="#165134" dark depressed :loading="loading" @click="load"><v-icon left>mdi-filter</v-icon>Apply</v-btn>
        </v-col>
      </v-row>
    </v-card>

    <v-alert v-if="error" type="error" dense text class="mb-4">{{error}}</v-alert>

    <v-row>
      <v-col v-for="card in metricCards" :key="card.key" cols="6" sm="4" md="3">
        <v-card flat class="metric-card fill-height pa-4" :class="{'clickable':card.route}" @click="go(card)">
          <div class="d-flex align-center">
            <v-avatar size="44" :class="card.softClass" class="mr-3"><v-icon :color="card.color">{{card.icon}}</v-icon></v-avatar>
            <div class="min-width-0">
              <div class="caption grey--text text-truncate">{{card.label}}</div>
              <div class="text-h6 font-weight-bold text-truncate">{{card.money ? 'PKR '+money(metrics[card.key]) : money(metrics[card.key])}}</div>
            </div>
          </div>
        </v-card>
      </v-col>
    </v-row>

    <v-row class="mt-1">
      <v-col cols="12" lg="7">
        <v-card flat class="section-card fill-height">
          <v-card-title><v-icon left color="#165134">mdi-chart-line</v-icon>Collections by Month</v-card-title>
          <v-divider/>
          <v-card-text>
            <div v-if="!monthlyCollections.length" class="empty-state">No verified collections in this period.</div>
            <div v-else>
              <div v-for="item in monthlyCollections" :key="item.month" class="mb-4">
                <div class="d-flex justify-space-between caption mb-1"><span>{{formatMonth(item.month)}}</span><strong>PKR {{money(item.amount)}}</strong></div>
                <v-progress-linear rounded height="9" :value="collectionPercent(item.amount)" color="#165134"/>
              </div>
            </div>
          </v-card-text>
        </v-card>
      </v-col>

      <v-col cols="12" lg="5">
        <v-card flat class="section-card fill-height">
          <v-card-title><v-icon left color="#165134">mdi-home-analytics</v-icon>Property Pipeline</v-card-title>
          <v-divider/>
          <v-card-text>
            <div v-for="item in pipeline" :key="item.label" class="d-flex align-center mb-4">
              <div class="pipeline-label">{{item.label}}</div>
              <v-progress-linear class="mx-3" rounded height="9" :value="item.percent" :color="item.color"/>
              <strong>{{item.value}}</strong>
            </div>
          </v-card-text>
        </v-card>
      </v-col>
    </v-row>

    <v-row>
      <v-col cols="12" lg="6">
        <v-card flat class="section-card">
          <v-card-title><v-icon left color="#165134">mdi-account-tie</v-icon>Top Sales Agents</v-card-title>
          <v-divider/>
          <v-data-table :headers="agentHeaders" :items="agentPerformance" :loading="loading" dense hide-default-footer :items-per-page="10">
            <template v-slot:item.sales_value="{item}">PKR {{money(item.sales_value)}}</template>
            <template v-slot:item.collected="{item}">PKR {{money(item.collected)}}</template>
            <template v-slot:no-data><div class="pa-6 grey--text text-center">No sales data for this period.</div></template>
          </v-data-table>
        </v-card>
      </v-col>
      <v-col cols="12" lg="6">
        <v-card flat class="section-card">
          <v-card-title><v-icon left color="#165134">mdi-office-building-outline</v-icon>Project Performance</v-card-title>
          <v-divider/>
          <v-data-table :headers="projectHeaders" :items="projectPerformance" :loading="loading" dense hide-default-footer :items-per-page="10">
            <template v-slot:item.sales_value="{item}">PKR {{money(item.sales_value)}}</template>
            <template v-slot:item.collected="{item}">PKR {{money(item.collected)}}</template>
            <template v-slot:no-data><div class="pa-6 grey--text text-center">No project sales data for this period.</div></template>
          </v-data-table>
        </v-card>
      </v-col>
    </v-row>

    <v-row>
      <v-col cols="12" lg="6">
        <v-card flat class="section-card">
          <v-card-title><v-icon left color="#165134">mdi-bookmark-check-outline</v-icon>Recent Sales</v-card-title>
          <v-divider/>
          <v-list v-if="recentBookings.length" two-line>
            <v-list-item v-for="item in recentBookings" :key="item.id">
              <v-list-item-avatar class="soft-green"><v-icon color="#165134">mdi-home-check-outline</v-icon></v-list-item-avatar>
              <v-list-item-content><v-list-item-title>{{item.booking_number}} · {{item.customer ? item.customer.name : 'Customer'}}</v-list-item-title><v-list-item-subtitle>{{item.property ? item.property.property_number : 'Property'}} · PKR {{money(item.final_price)}}</v-list-item-subtitle></v-list-item-content>
              <v-chip x-small dark :color="item.status==='completed'?'success':'blue'">{{item.status}}</v-chip>
            </v-list-item>
          </v-list>
          <div v-else class="empty-state">No recent sales.</div>
        </v-card>
      </v-col>

      <v-col cols="12" lg="6">
        <v-card flat class="section-card">
          <v-card-title><v-icon left color="#165134">mdi-cash-check</v-icon>Recent Collections</v-card-title>
          <v-divider/>
          <v-list v-if="recentPayments.length" two-line>
            <v-list-item v-for="item in recentPayments" :key="item.id">
              <v-list-item-avatar class="soft-blue"><v-icon color="blue">mdi-receipt-text-check-outline</v-icon></v-list-item-avatar>
              <v-list-item-content><v-list-item-title>{{item.receipt_number}} · {{item.customer ? item.customer.name : 'Customer'}}</v-list-item-title><v-list-item-subtitle>{{item.booking ? item.booking.booking_number : 'Booking'}} · {{dateOnly(item.payment_date)}}</v-list-item-subtitle></v-list-item-content>
              <strong>PKR {{money(item.amount)}}</strong>
            </v-list-item>
          </v-list>
          <div v-else class="empty-state">No recent collections.</div>
        </v-card>
      </v-col>
    </v-row>
  </div>
</template>

<script>
import api from '../../../services/api'

export default {
  name:'SalesDashboard',
  data:()=>({
    loading:false,error:'',filters:{from:'',to:''},metrics:{},monthlyCollections:[],agentPerformance:[],projectPerformance:[],recentBookings:[],recentPayments:[],
    metricCards:[
      {key:'sales_value',label:'Sales Value',icon:'mdi-cash-multiple',color:'green darken-2',softClass:'soft-green',money:true},
      {key:'collections',label:'Collections',icon:'mdi-bank-check',color:'teal darken-2',softClass:'soft-green',money:true,route:'payments'},
      {key:'receivables',label:'Receivables',icon:'mdi-cash-clock',color:'deep-orange darken-2',softClass:'soft-amber',money:true},
      {key:'overdue_amount',label:'Overdue Amount',icon:'mdi-alert-decagram-outline',color:'red darken-2',softClass:'soft-red',money:true,route:'installments',query:{status:'overdue'}},
      {key:'expenses',label:'Expenses',icon:'mdi-cash-minus',color:'purple darken-1',softClass:'soft-purple',money:true,route:'expenses'},
      {key:'net_cash_flow',label:'Net Cash Flow',icon:'mdi-chart-areaspline',color:'#165134',softClass:'soft-green',money:true},
      {key:'active_leads',label:'Active Leads',icon:'mdi-account-star-outline',color:'orange darken-2',softClass:'soft-amber',money:false,route:'leads'},
      {key:'available_properties',label:'Available Properties',icon:'mdi-home-check-outline',color:'green darken-2',softClass:'soft-green',money:false,route:'properties',query:{status:'available'}}
    ],
    agentHeaders:[{text:'Agent',value:'sales_agent.name'},{text:'Bookings',value:'bookings',align:'right'},{text:'Sales',value:'sales_value',align:'right'},{text:'Collected',value:'collected',align:'right'}],
    projectHeaders:[{text:'Project',value:'project_name'},{text:'Bookings',value:'bookings',align:'right'},{text:'Sales',value:'sales_value',align:'right'},{text:'Collected',value:'collected',align:'right'}]
  }),
  computed:{
    pipeline(){const items=[{label:'Available',value:this.metrics.available_properties||0,color:'green'},{label:'Reserved',value:this.metrics.reserved_properties||0,color:'orange'},{label:'Booked',value:this.metrics.booked_properties||0,color:'blue'},{label:'Sold',value:this.metrics.sold_properties||0,color:'red'}];const max=Math.max.apply(null,items.map(function(i){return Number(i.value)}).concat([1]));return items.map(function(i){return Object.assign({},i,{percent:(Number(i.value)/max)*100})})},
    maxCollection(){return Math.max.apply(null,this.monthlyCollections.map(function(i){return Number(i.amount)}).concat([1]))}
  },
  mounted(){this.load()},
  methods:{
    async load(){if(this.filters.from&&this.filters.to&&this.filters.from>this.filters.to){this.error='The From date cannot be later than the To date.';return}this.loading=true;this.error='';try{const params={};if(this.filters.from)params.from=this.filters.from;if(this.filters.to)params.to=this.filters.to;const r=await api.get('/sales/dashboard',{params:params});const d=r.data||{};this.metrics=d.metrics||{};this.monthlyCollections=d.monthly_collections||[];this.agentPerformance=d.agent_performance||[];this.projectPerformance=d.project_performance||[];this.recentBookings=d.recent_bookings||[];this.recentPayments=d.recent_payments||[]}catch(e){this.error=(e.response&&e.response.data&&e.response.data.message)||'Unable to load the sales dashboard.'}finally{this.loading=false}},
    go(card){if(card.route)this.$router.push({name:card.route,query:card.query||{}})},
    clearFilters(){this.filters={from:'',to:''};this.load()},
    money(v){return new Intl.NumberFormat('en-PK',{maximumFractionDigits:0}).format(Number(v||0))},
    dateOnly(v){return v?String(v).slice(0,10):'—'},
    formatMonth(v){const p=String(v).split('-');return p.length===2?p[1]+'/'+p[0]:v},
    collectionPercent(v){return(Number(v||0)/this.maxCollection)*100}
  }
}
</script>

<style scoped>
.sales-dashboard{width:100%}.hero{border-left:4px solid #165134}.metric-card,.filter-card,.section-card{border:1px solid rgba(22,81,52,.08);border-radius:15px!important}.metric-card{transition:.2s}.clickable{cursor:pointer}.clickable:hover{transform:translateY(-2px);box-shadow:0 8px 22px rgba(20,50,35,.08)!important}.quick-actions{gap:8px}.empty-state{min-height:120px;display:flex;align-items:center;justify-content:center;color:#888}.pipeline-label{width:76px;font-size:13px}.soft-green{background:#e8f5ee!important}.soft-amber{background:#fff5df!important}.soft-red{background:#fdecec!important}.soft-blue{background:#eaf2ff!important}.soft-purple{background:#f3eefe!important}.min-width-0{min-width:0}
</style>