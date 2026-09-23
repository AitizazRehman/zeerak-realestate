<template>
<div class="dashboard">
  <v-card flat class="hero pa-5 pa-md-6 mb-5 overflow-hidden">
    <div class="hero-glow hero-glow-one"></div>
    <div class="hero-glow hero-glow-two"></div>
    <div class="d-flex flex-wrap align-center hero-content">
      <div class="d-flex align-center">
        <v-avatar tile size="72" color="white" class="brand-avatar mr-4">
          <v-img src="/images/zeerak-logo.jpeg" contain/>
        </v-avatar>
        <div>
          <div class="text-overline hero-overline">ZEERAK REAL ESTATE & BUILDERS</div>
          <h1 class="text-h4 font-weight-bold mb-1">Welcome, {{firstName}}</h1>
          <div class="hero-subtitle">Business performance, customer activity and finance in one place.</div>
        </div>
      </div>

      <v-spacer/>

      <div class="hero-actions mt-4 mt-md-0">
        <v-btn v-if="$can('leads.create')" small depressed class="hero-primary" to="/admin/leads">
          <v-icon left small>mdi-account-plus</v-icon>New Lead
        </v-btn>
        <v-btn v-if="$can('properties.create')" small outlined class="hero-secondary" to="/admin/properties/create">
          <v-icon left small>mdi-home-plus-outline</v-icon>Add Property
        </v-btn>
        <v-btn v-if="$can('payments.create')" small outlined class="hero-secondary" to="/admin/payments">
          <v-icon left small>mdi-cash-plus</v-icon>Payment
        </v-btn>
        <v-btn icon dark :loading="loading" @click="load">
          <v-icon>mdi-refresh</v-icon>
        </v-btn>
      </div>
    </div>

    <div class="hero-meta mt-5">
      <div><v-icon small dark class="mr-1">mdi-calendar-range</v-icon>{{periodLabel}}</div>
      <div><v-icon small dark class="mr-1">mdi-clock-outline</v-icon>{{todayLabel}}</div>
    </div>
  </v-card>

  <v-alert v-if="error" type="error" dense text class="mb-4">{{error}}</v-alert>

  <div class="section-heading mb-3">
    <div>
      <div class="text-overline section-kicker">BUSINESS SNAPSHOT</div>
      <div class="text-h6 font-weight-bold">Key performance</div>
    </div>
    <v-btn v-if="$can('reports.view')" text color="#165134" to="/admin/reports">
      View reports<v-icon right>mdi-arrow-right</v-icon>
    </v-btn>
  </div>

  <v-row>
    <v-col v-for="card in cards" :key="card.key" cols="6" sm="6" lg="3">
      <v-card
        flat
        class="stat-card fill-height pa-4 pa-md-5"
        :class="{'clickable':card.route && canOpen(card)}"
        @click="go(card)"
      >
        <div class="d-flex align-start">
          <v-avatar size="46" :class="'soft-'+card.tone" class="mr-3">
            <v-icon :color="card.color">{{card.icon}}</v-icon>
          </v-avatar>

          <div class="min-width-0 flex-grow-1">
            <div class="caption grey--text text-uppercase font-weight-medium text-truncate">{{card.label}}</div>
            <div class="text-h6 font-weight-bold text-truncate mt-1">{{card.money ? 'PKR '+format(metrics[card.key]) : format(metrics[card.key])}}</div>
            <div class="caption grey--text mt-1">{{card.helper}}</div>
          </div>

          <v-icon v-if="card.route && canOpen(card)" small color="grey lighten-1">mdi-arrow-top-right</v-icon>
        </div>
      </v-card>
    </v-col>
  </v-row>

  <v-row class="mt-1">
    <v-col cols="12" lg="4">
      <v-card flat class="panel fill-height">
        <v-card-title class="pb-2">
          <div>
            <div class="text-overline section-kicker">ATTENTION</div>
            <div class="subtitle-1 font-weight-bold">What needs action</div>
          </div>
        </v-card-title>
        <v-card-text>
          <div
            v-for="item in attention"
            :key="item.label"
            class="attention-row"
            :class="{'clickable-row':item.route && canOpen(item)}"
            @click="go(item)"
          >
            <v-avatar size="40" :class="item.softClass" class="mr-3">
              <v-icon small :color="item.color">{{item.icon}}</v-icon>
            </v-avatar>
            <div class="flex-grow-1 min-width-0">
              <div class="font-weight-medium">{{item.label}}</div>
              <div class="caption grey--text">{{item.helper}}</div>
            </div>
            <div class="text-h6 font-weight-bold" :class="item.valueClass">{{item.money?'PKR '+format(item.value):format(item.value)}}</div>
          </div>
        </v-card-text>
      </v-card>
    </v-col>

    <v-col cols="12" lg="8">
      <v-card flat class="panel fill-height">
        <v-card-title>
          <div>
            <div class="text-overline section-kicker">CASH FLOW</div>
            <div class="subtitle-1 font-weight-bold">Monthly collections</div>
          </div>
          <v-spacer/>
          <v-chip small outlined color="#165134">PKR {{format(metrics.collections)}} collected</v-chip>
        </v-card-title>
        <v-divider/>
        <v-card-text class="pt-5">
          <div v-if="!monthly.length" class="empty">
            <div class="text-center"><v-icon size="46" color="grey lighten-1">mdi-chart-line</v-icon><div class="mt-2">No collection activity in this period.</div></div>
          </div>
          <div v-else>
            <div v-for="m in monthly" :key="m.month" class="collection-row mb-4">
              <div class="d-flex justify-space-between caption mb-2">
                <span class="font-weight-medium">{{month(m.month)}}</span>
                <strong>PKR {{format(m.amount)}}</strong>
              </div>
              <v-progress-linear rounded height="11" color="#165134" :value="percent(m.amount)"/>
            </div>
          </div>
        </v-card-text>
      </v-card>
    </v-col>
  </v-row>

  <v-row>
    <v-col cols="12" lg="5">
      <v-card flat class="panel fill-height">
        <v-card-title>
          <div>
            <div class="text-overline section-kicker">INVENTORY</div>
            <div class="subtitle-1 font-weight-bold">Property pipeline</div>
          </div>
          <v-spacer/>
          <v-btn text small color="#165134" to="/admin/properties">Open inventory</v-btn>
        </v-card-title>
        <v-divider/>
        <v-card-text class="pt-5">
          <div v-for="p in pipeline" :key="p.label" class="pipeline mb-5">
            <div class="d-flex justify-space-between align-center mb-2">
              <div class="d-flex align-center">
                <span class="pipeline-dot mr-2" :class="'dot-'+p.tone"></span>
                <span>{{p.label}}</span>
              </div>
              <strong>{{p.value}}</strong>
            </div>
            <v-progress-linear rounded height="9" :color="p.color" :value="p.percent"/>
          </div>
        </v-card-text>
      </v-card>
    </v-col>

    <v-col cols="12" lg="7">
      <v-card flat class="panel fill-height">
        <v-card-title>
          <div>
            <div class="text-overline section-kicker">CRM</div>
            <div class="subtitle-1 font-weight-bold">Customer activity</div>
          </div>
        </v-card-title>
        <v-divider/>
        <v-card-text class="pt-5">
          <v-row>
            <v-col v-for="item in crmCards" :key="item.key" cols="6">
              <v-card flat class="mini-card pa-4" :class="{'clickable':item.route && canOpen(item)}" @click="go(item)">
                <div class="d-flex align-center">
                  <v-avatar size="42" :class="item.softClass" class="mr-3"><v-icon :color="item.color">{{item.icon}}</v-icon></v-avatar>
                  <div>
                    <div class="caption grey--text">{{item.label}}</div>
                    <div class="text-h6 font-weight-bold">{{format(metrics[item.key])}}</div>
                  </div>
                </div>
              </v-card>
            </v-col>
          </v-row>
        </v-card-text>
      </v-card>
    </v-col>
  </v-row>

  <v-row>
    <v-col cols="12" lg="6">
      <v-card flat class="panel">
        <v-card-title>
          <div><div class="text-overline section-kicker">LATEST</div><div class="subtitle-1 font-weight-bold">Recent bookings</div></div>
          <v-spacer/><v-btn v-if="$can('sales.view')" text small color="#165134" to="/admin/bookings">View all</v-btn>
        </v-card-title>
        <v-divider/>
        <v-data-table dense hide-default-footer :headers="bookingHeaders" :items="recentBookings" :items-per-page="5">
          <template v-slot:item.booking_number="{item}"><strong>{{item.booking_number}}</strong></template>
          <template v-slot:item.final_price="{item}">PKR {{format(item.final_price)}}</template>
          <template v-slot:item.booking_date="{item}">{{date(item.booking_date)}}</template>
          <template v-slot:no-data><div class="empty-table">No recent bookings.</div></template>
        </v-data-table>
      </v-card>
    </v-col>

    <v-col cols="12" lg="6">
      <v-card flat class="panel">
        <v-card-title>
          <div><div class="text-overline section-kicker">LATEST</div><div class="subtitle-1 font-weight-bold">Recent payments</div></div>
          <v-spacer/><v-btn v-if="$can('payments.view')" text small color="#165134" to="/admin/payments">View all</v-btn>
        </v-card-title>
        <v-divider/>
        <v-data-table dense hide-default-footer :headers="paymentHeaders" :items="recentPayments" :items-per-page="5">
          <template v-slot:item.receipt_number="{item}"><strong>{{item.receipt_number}}</strong></template>
          <template v-slot:item.amount="{item}"><strong class="success--text">PKR {{format(item.amount)}}</strong></template>
          <template v-slot:item.payment_date="{item}">{{date(item.payment_date)}}</template>
          <template v-slot:no-data><div class="empty-table">No recent payments.</div></template>
        </v-data-table>
      </v-card>
    </v-col>
  </v-row>

  <v-row>
    <v-col cols="12" lg="6">
      <v-card flat class="panel">
        <v-card-title><v-icon left color="#165134">mdi-account-tie-outline</v-icon>Top Sales Agents</v-card-title>
        <v-divider/>
        <v-data-table dense hide-default-footer :headers="agentHeaders" :items="agents" :items-per-page="8">
          <template v-slot:item.sales_agent.name="{item}"><div class="font-weight-medium">{{item.sales_agent ? item.sales_agent.name : '—'}}</div></template>
          <template v-slot:item.sales_value="{item}">PKR {{format(item.sales_value)}}</template>
          <template v-slot:item.collected="{item}">PKR {{format(item.collected)}}</template>
          <template v-slot:no-data><div class="empty-table">No sales-agent performance data.</div></template>
        </v-data-table>
      </v-card>
    </v-col>

    <v-col cols="12" lg="6">
      <v-card flat class="panel">
        <v-card-title><v-icon left color="#165134">mdi-office-building-outline</v-icon>Project Performance</v-card-title>
        <v-divider/>
        <v-data-table dense hide-default-footer :headers="projectHeaders" :items="projects" :items-per-page="8">
          <template v-slot:item.sales_value="{item}">PKR {{format(item.sales_value)}}</template>
          <template v-slot:item.collected="{item}">PKR {{format(item.collected)}}</template>
          <template v-slot:no-data><div class="empty-table">No project performance data.</div></template>
        </v-data-table>
      </v-card>
    </v-col>
  </v-row>
</div>
</template>

<script>
import api from '../../services/api'

export default {
  name:'Dashboard',

  data(){
    return{
      loading:false,
      error:'',
      period:{},
      metrics:{},
      monthly:[],
      agents:[],
      projects:[],
      recentBookings:[],
      recentPayments:[],

      cards:[
        {key:'sales_value',label:'Sales Value',helper:'Confirmed & completed sales',money:true,icon:'mdi-cash-multiple',color:'green darken-2',tone:'green',route:'bookings',permission:'sales.view'},
        {key:'collections',label:'Collections',helper:'Verified customer receipts',money:true,icon:'mdi-bank-check',color:'teal darken-2',tone:'green',route:'payments',permission:'payments.view'},
        {key:'receivables',label:'Receivables',helper:'Outstanding booking balance',money:true,icon:'mdi-cash-clock',color:'orange darken-2',tone:'amber',route:'installments',permission:'installments.view'},
        {key:'overdue_amount',label:'Overdue Amount',helper:'Past-due installment balance',money:true,icon:'mdi-alert-circle-outline',color:'red darken-2',tone:'red',route:'installments',permission:'installments.view',query:{status:'overdue'}},
        {key:'expenses',label:'Expenses',helper:'Operating & project expenses',money:true,icon:'mdi-cash-minus',color:'deep-orange darken-2',tone:'amber',route:'expenses',permission:'expenses.view'},
        {key:'net_cash_flow',label:'Net Cash Flow',helper:'Collections less expenses',money:true,icon:'mdi-chart-areaspline',color:'purple darken-1',tone:'purple',route:'reports',permission:'reports.view'},
        {key:'available_properties',label:'Available Properties',helper:'Inventory ready for sale',icon:'mdi-home-check-outline',color:'green darken-2',tone:'green',route:'properties',permission:'properties.view',query:{status:'available'}},
        {key:'sold_properties',label:'Sold Properties',helper:'Completed property sales',icon:'mdi-home-lock-outline',color:'blue darken-2',tone:'blue',route:'properties',permission:'properties.view',query:{status:'sold'}}
      ],

      bookingHeaders:[
        {text:'Booking',value:'booking_number'},
        {text:'Customer',value:'customer.name'},
        {text:'Property',value:'property.property_number'},
        {text:'Date',value:'booking_date'},
        {text:'Value',value:'final_price',align:'right'}
      ],

      paymentHeaders:[
        {text:'Receipt',value:'receipt_number'},
        {text:'Customer',value:'customer.name'},
        {text:'Booking',value:'booking.booking_number'},
        {text:'Date',value:'payment_date'},
        {text:'Amount',value:'amount',align:'right'}
      ],

      agentHeaders:[
        {text:'Agent',value:'sales_agent.name'},
        {text:'Bookings',value:'bookings',align:'right'},
        {text:'Sales',value:'sales_value',align:'right'},
        {text:'Collected',value:'collected',align:'right'}
      ],

      projectHeaders:[
        {text:'Project',value:'project_name'},
        {text:'Bookings',value:'bookings',align:'right'},
        {text:'Sales',value:'sales_value',align:'right'},
        {text:'Collected',value:'collected',align:'right'}
      ]
    }
  },

  computed:{
    firstName(){
      const user=this.$store.getters['auth/user']
      const name=user&&user.name?user.name:'User'
      return name.split(' ')[0]
    },

    todayLabel(){
      return new Intl.DateTimeFormat('en-PK',{
        weekday:'long',
        day:'2-digit',
        month:'short',
        year:'numeric'
      }).format(new Date())
    },

    periodLabel(){
      if(!this.period.from||!this.period.to)return'Current business period'
      return this.date(this.period.from)+' — '+this.date(this.period.to)
    },

    pipeline(){
      const items=[
        ['Available','available_properties','green','green'],
        ['Reserved','reserved_properties','orange','amber'],
        ['Booked','booked_properties','blue','blue'],
        ['Sold','sold_properties','red','red']
      ].map(function(x){
        return{
          label:x[0],
          value:Number(this.metrics[x[1]]||0),
          color:x[2],
          tone:x[3]
        }
      },this)

      const max=Math.max.apply(null,items.map(function(x){return x.value}).concat([1]))

      return items.map(function(x){
        return Object.assign({},x,{percent:x.value/max*100})
      })
    },

    maxCollection(){
      return Math.max.apply(null,this.monthly.map(function(x){return Number(x.amount||0)}).concat([1]))
    },

    attention(){
      return[
        {label:'Overdue installments',helper:'Require collection follow-up',value:this.metrics.overdue_count||0,icon:'mdi-alert-decagram-outline',color:'red darken-2',softClass:'soft-red',valueClass:'error--text',route:'installments',permission:'installments.view',query:{status:'overdue'}},
        {label:'Receivables',helper:'Outstanding customer balance',value:this.metrics.receivables||0,money:true,icon:'mdi-cash-clock',color:'orange darken-2',softClass:'soft-amber',valueClass:'orange--text text--darken-2',route:'installments',permission:'installments.view'},
        {label:'Active leads',helper:'Prospects still in the pipeline',value:this.metrics.active_leads||0,icon:'mdi-account-star-outline',color:'blue darken-1',softClass:'soft-blue',route:'leads',permission:'leads.view'},
        {label:'Scheduled visits',helper:'Upcoming customer visits',value:this.metrics.scheduled_visits||0,icon:'mdi-map-marker-clock-outline',color:'purple darken-1',softClass:'soft-purple',route:'site-visits',permission:'site_visits.view',query:{status:'scheduled'}}
      ]
    },

    crmCards(){
      return[
        {key:'customers',label:'Active Customers',icon:'mdi-account-group-outline',color:'#165134',softClass:'soft-green',route:'customers',permission:'customers.view'},
        {key:'active_leads',label:'Active Leads',icon:'mdi-account-star-outline',color:'blue darken-1',softClass:'soft-blue',route:'leads',permission:'leads.view'},
        {key:'scheduled_visits',label:'Scheduled Visits',icon:'mdi-map-marker-clock-outline',color:'purple darken-1',softClass:'soft-purple',route:'site-visits',permission:'site_visits.view',query:{status:'scheduled'}},
        {key:'reserved_properties',label:'Reserved Properties',icon:'mdi-home-clock-outline',color:'orange darken-2',softClass:'soft-amber',route:'properties',permission:'properties.view',query:{status:'reserved'}}
      ]
    }
  },

  mounted(){
    this.load()
  },

  methods:{
    async load(){
      this.loading=true
      this.error=''

      try{
        const r=await api.get('/sales/dashboard')
        const d=r.data||{}

        this.period=d.period||{}
        this.metrics=d.metrics||{}
        this.monthly=d.monthly_collections||[]
        this.agents=d.agent_performance||[]
        this.projects=d.project_performance||[]
        this.recentBookings=d.recent_bookings||[]
        this.recentPayments=d.recent_payments||[]
      }catch(e){
        this.error=(e.response&&e.response.data&&e.response.data.message)||'Unable to load dashboard.'
      }finally{
        this.loading=false
      }
    },

    canOpen(item){
      return !item || !item.permission || this.$can(item.permission)
    },

    go(item){
      if(!item||!item.route||!this.canOpen(item))return

      this.$router.push({
        name:item.route,
        query:item.query||{}
      }).catch(function(){})
    },

    format(v){
      return new Intl.NumberFormat('en-PK',{maximumFractionDigits:0}).format(Number(v||0))
    },

    date(v){
      if(!v)return'—'

      const raw=String(v).slice(0,10)
      const d=new Date(raw+'T00:00:00')

      if(Number.isNaN(d.getTime()))return raw

      return new Intl.DateTimeFormat('en-PK',{
        day:'2-digit',
        month:'short',
        year:'numeric'
      }).format(d)
    },

    month(v){
      const parts=String(v).split('-')
      if(parts.length!==2)return v

      const d=new Date(Number(parts[0]),Number(parts[1])-1,1)
      return new Intl.DateTimeFormat('en-PK',{month:'short',year:'numeric'}).format(d)
    },

    percent(v){
      return Number(v||0)/this.maxCollection*100
    }
  }
}
</script>

<style scoped>
.dashboard{width:100%}
.hero{
  position:relative;
  overflow:hidden;
  color:white;
  border-radius:22px!important;
  background:linear-gradient(135deg,#123f2a 0%,#165134 58%,#8a6d22 145%)!important;
  box-shadow:0 14px 34px rgba(22,81,52,.18)!important
}
.hero-content,.hero-meta{position:relative;z-index:2}
.hero-overline{color:rgba(255,255,255,.72)!important}
.hero-subtitle{color:rgba(255,255,255,.78)}
.brand-avatar{border-radius:16px!important;box-shadow:0 10px 28px rgba(0,0,0,.16)}
.hero-actions{display:flex;gap:8px;align-items:center;position:relative;z-index:2}
.hero-primary{background:white!important;color:#165134!important}
.hero-secondary{border-color:rgba(255,255,255,.65)!important;color:white!important}
.hero-meta{display:flex;gap:22px;flex-wrap:wrap;font-size:12px;color:rgba(255,255,255,.72)}
.hero-glow{position:absolute;border-radius:50%;background:rgba(255,255,255,.07)}
.hero-glow-one{width:240px;height:240px;right:-60px;top:-100px}
.hero-glow-two{width:150px;height:150px;right:190px;bottom:-95px}
.section-heading{display:flex;align-items:center;justify-content:space-between}
.section-kicker{color:#165134!important;line-height:1.2}
.stat-card,.panel,.mini-card{
  border:1px solid rgba(22,81,52,.08);
  border-radius:16px!important;
  background:var(--v-background-base,#fff);
  box-shadow:0 7px 22px rgba(20,50,35,.045)!important
}
.stat-card,.mini-card{transition:transform .18s ease,box-shadow .18s ease}
.clickable{cursor:pointer}
.clickable:hover,.stat-card.clickable:hover,.mini-card.clickable:hover{transform:translateY(-3px);box-shadow:0 12px 30px rgba(20,50,35,.09)!important}
.attention-row{display:flex;align-items:center;padding:13px 4px;border-bottom:1px solid rgba(0,0,0,.055)}
.attention-row:last-child{border-bottom:0}
.clickable-row{cursor:pointer;border-radius:10px}
.clickable-row:hover{background:rgba(22,81,52,.035)}
.soft-green{background:#e8f5ee!important}
.soft-amber{background:#fff5df!important}
.soft-red{background:#fdecec!important}
.soft-blue{background:#eaf2ff!important}
.soft-purple{background:#f3eefe!important}
.pipeline-dot{width:9px;height:9px;border-radius:50%;display:inline-block}
.dot-green{background:#2e7d32}.dot-amber{background:#ef9a00}.dot-blue{background:#1976d2}.dot-red{background:#d32f2f}
.min-width-0{min-width:0}
.empty{min-height:210px;display:flex;align-items:center;justify-content:center;color:#888}
.empty-table{padding:28px;text-align:center;color:#888}
.collection-row:last-child{margin-bottom:0!important}
.dashboard ::v-deep .v-data-table__wrapper{overflow-x:auto}
@media(max-width:600px){
  .hero{border-radius:16px!important}
  .brand-avatar{width:56px!important;height:56px!important;min-width:56px!important}
  .hero h1{font-size:1.5rem!important}
  .hero-actions{width:100%}
}
</style>
