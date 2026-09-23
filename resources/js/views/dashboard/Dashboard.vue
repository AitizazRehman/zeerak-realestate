<template>
<div class="dashboard">
  <div class="dashboard-header mb-5">
    <div>
      <div class="text-overline section-kicker">OVERVIEW</div>
      <h1 class="text-h4 font-weight-bold mb-1">Dashboard</h1>
      <div class="grey--text">Welcome back, {{firstName}}. Here is how the business is performing.</div>
    </div>

    <div class="header-actions">
      <v-btn-toggle v-model="range" mandatory dense class="range-toggle" @change="changeRange">
        <v-btn value="6m" small>6M</v-btn>
        <v-btn value="ytd" small>YTD</v-btn>
        <v-btn value="12m" small>12M</v-btn>
      </v-btn-toggle>

      <v-btn icon color="#165134" @click="load">
        <v-icon>mdi-refresh</v-icon>
      </v-btn>
    </div>
  </div>

  <v-alert v-if="error" type="error" dense text class="mb-4">{{error}}</v-alert>

  <div class="period-strip mb-4">
    <v-icon small color="#165134" class="mr-2">mdi-calendar-range</v-icon>
    <span>{{periodLabel}}</span>
    <v-spacer/>
    <span class="caption grey--text">{{todayLabel}}</span>
  </div>

  <v-row>
    <v-col v-for="card in kpiCards" :key="card.key" cols="12" sm="6" lg="4" xl="2">
      <v-card
        flat
        class="metric-card fill-height pa-4"
        :class="{'clickable':card.route && canOpen(card)}"
        @click="go(card)"
      >
        <div class="d-flex align-start">
          <v-avatar size="44" :class="card.softClass" class="mr-3">
            <v-icon :color="card.color">{{card.icon}}</v-icon>
          </v-avatar>

          <div class="flex-grow-1 min-width-0">
            <div class="caption grey--text text-uppercase metric-label">{{card.label}}</div>
            <div class="text-h6 font-weight-bold mt-1 text-truncate">
              {{card.money ? 'PKR '+format(metrics[card.key]) : format(metrics[card.key])}}
            </div>

            <div v-if="card.trendKey" class="d-flex align-center mt-2">
              <span class="trend-pill" :class="trendClass(card)">
                <v-icon x-small :color="trendColor(card)" class="mr-1">{{trendIcon(card)}}</v-icon>
                {{Math.abs(Number(trends[card.trendKey]||0))}}%
              </span>
              <span class="caption grey--text ml-2">vs previous period</span>
            </div>

            <div v-else class="caption grey--text mt-2">{{card.helper}}</div>
          </div>
        </div>
      </v-card>
    </v-col>
  </v-row>

  <v-row class="mt-1">
    <v-col cols="12" lg="8">
      <v-card flat class="panel fill-height">
        <v-card-title class="pb-2">
          <div>
            <div class="text-overline section-kicker">TREND</div>
            <div class="subtitle-1 font-weight-bold">Financial performance</div>
            <div class="caption grey--text">Sales, collections and expenses by month</div>
          </div>
          <v-spacer/>
          <div class="chart-legend">
            <span><i class="legend-dot sales-dot"></i>Sales</span>
            <span><i class="legend-dot collection-dot"></i>Collections</span>
            <span><i class="legend-dot expense-dot"></i>Expenses</span>
          </div>
        </v-card-title>
        <v-card-text class="pt-2">
          <div v-if="!financials.length" class="empty-state">
            <v-icon size="48" color="grey lighten-1">mdi-chart-line</v-icon>
            <div class="mt-2">No financial trend data for this period.</div>
          </div>

          <div v-else class="trend-chart-wrap">
            <svg class="trend-chart" viewBox="0 0 720 260" preserveAspectRatio="none">
              <g class="grid">
                <line v-for="line in gridLines" :key="'g'+line.y" x1="54" x2="700" :y1="line.y" :y2="line.y"/>
                <text v-for="line in gridLines" :key="'t'+line.y" x="48" :y="line.y+4" text-anchor="end">{{compact(line.value)}}</text>
              </g>

              <polyline class="trend-line sales-line" :points="chartPoints('sales')" fill="none"/>
              <polyline class="trend-line collection-line" :points="chartPoints('collections')" fill="none"/>
              <polyline class="trend-line expense-line" :points="chartPoints('expenses')" fill="none"/>

              <g v-for="(item,index) in financials" :key="item.month">
                <circle class="chart-point sales-point" :cx="chartX(index)" :cy="chartY(item.sales)" r="4">
                  <title>{{month(item.month)}} · Sales PKR {{format(item.sales)}}</title>
                </circle>
                <circle class="chart-point collection-point" :cx="chartX(index)" :cy="chartY(item.collections)" r="4">
                  <title>{{month(item.month)}} · Collections PKR {{format(item.collections)}}</title>
                </circle>
                <circle class="chart-point expense-point" :cx="chartX(index)" :cy="chartY(item.expenses)" r="4">
                  <title>{{month(item.month)}} · Expenses PKR {{format(item.expenses)}}</title>
                </circle>

                <text
                  v-if="showMonthLabel(index)"
                  class="month-label"
                  :x="chartX(index)"
                  y="246"
                  text-anchor="middle"
                >{{monthShort(item.month)}}</text>
              </g>
            </svg>
          </div>
        </v-card-text>
      </v-card>
    </v-col>

    <v-col cols="12" lg="4">
      <v-card flat class="panel fill-height">
        <v-card-title class="pb-2">
          <div>
            <div class="text-overline section-kicker">PIPELINE</div>
            <div class="subtitle-1 font-weight-bold">Lead progression</div>
            <div class="caption grey--text">{{format(totalPipelineLeads)}} total leads</div>
          </div>
          <v-spacer/>
          <v-btn v-if="$can('leads.view')" text small color="#165134" to="/admin/leads">Open CRM</v-btn>
        </v-card-title>
        <v-card-text class="pt-4">
          <div v-for="stage in leadStages" :key="stage.key" class="pipeline-row">
            <div class="d-flex justify-space-between mb-1">
              <span class="caption font-weight-medium">{{stage.label}}</span>
              <strong>{{stage.value}}</strong>
            </div>
            <div class="pipeline-track">
              <div class="pipeline-fill" :style="{width:stage.percent+'%',background:stage.color}"></div>
            </div>
          </div>

          <v-divider class="my-4"/>
          <div class="d-flex justify-space-between">
            <span class="caption grey--text">Converted</span>
            <strong class="success--text">{{pipelineCount('converted')}}</strong>
          </div>
          <div class="d-flex justify-space-between mt-2">
            <span class="caption grey--text">Lost</span>
            <strong class="grey--text text--darken-1">{{pipelineCount('lost')}}</strong>
          </div>
        </v-card-text>
      </v-card>
    </v-col>
  </v-row>

  <v-row>
    <v-col cols="12" md="6" lg="4">
      <v-card flat class="panel fill-height">
        <v-card-title>
          <div>
            <div class="text-overline section-kicker">INVENTORY</div>
            <div class="subtitle-1 font-weight-bold">Property mix</div>
          </div>
          <v-spacer/>
          <v-btn v-if="$can('properties.view')" text small color="#165134" to="/admin/properties">View</v-btn>
        </v-card-title>
        <v-card-text>
          <div class="inventory-chart">
            <div class="donut" :style="inventoryDonutStyle">
              <div class="donut-center">
                <div class="text-h5 font-weight-bold">{{inventoryTotal}}</div>
                <div class="caption grey--text">properties</div>
              </div>
            </div>

            <div class="inventory-legend">
              <div v-for="item in inventoryItems" :key="item.label" class="inventory-item">
                <div><span class="legend-square" :style="{background:item.color}"></span>{{item.label}}</div>
                <strong>{{item.value}}</strong>
              </div>
            </div>
          </div>
        </v-card-text>
      </v-card>
    </v-col>

    <v-col cols="12" md="6" lg="4">
      <v-card flat class="panel fill-height">
        <v-card-title>
          <div>
            <div class="text-overline section-kicker">ATTENTION</div>
            <div class="subtitle-1 font-weight-bold">Needs action</div>
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
            <v-avatar size="38" :class="item.softClass" class="mr-3">
              <v-icon small :color="item.color">{{item.icon}}</v-icon>
            </v-avatar>
            <div class="flex-grow-1 min-width-0">
              <div class="font-weight-medium">{{item.label}}</div>
              <div class="caption grey--text">{{item.helper}}</div>
            </div>
            <div class="font-weight-bold" :class="item.valueClass">
              {{item.money?'PKR '+format(item.value):format(item.value)}}
            </div>
          </div>
        </v-card-text>
      </v-card>
    </v-col>

    <v-col cols="12" lg="4">
      <v-card flat class="panel fill-height">
        <v-card-title>
          <div>
            <div class="text-overline section-kicker">TOP PERFORMANCE</div>
            <div class="subtitle-1 font-weight-bold">Sales agents</div>
          </div>
        </v-card-title>
        <v-card-text class="pt-2">
          <div v-if="!agents.length" class="empty-state compact-empty">No sales data yet.</div>
          <div v-for="(agent,index) in agents.slice(0,5)" :key="agent.sales_agent_id" class="rank-row">
            <div class="rank-number">{{index+1}}</div>
            <div class="flex-grow-1 min-width-0">
              <div class="font-weight-medium text-truncate">{{agent.sales_agent ? agent.sales_agent.name : 'Unassigned'}}</div>
              <div class="caption grey--text">{{agent.bookings}} booking(s)</div>
            </div>
            <div class="text-right">
              <div class="font-weight-bold">PKR {{compact(agent.sales_value)}}</div>
              <div class="caption success--text">PKR {{compact(agent.collected)}} collected</div>
            </div>
          </div>
        </v-card-text>
      </v-card>
    </v-col>
  </v-row>

  <v-row>
    <v-col cols="12" lg="7">
      <v-card flat class="panel">
        <v-card-title>
          <div>
            <div class="text-overline section-kicker">PROJECTS</div>
            <div class="subtitle-1 font-weight-bold">Project performance</div>
          </div>
          <v-spacer/>
          <v-btn v-if="$can('reports.view')" text small color="#165134" to="/admin/reports">Reports</v-btn>
        </v-card-title>
        <v-divider/>
        <v-data-table dense hide-default-footer :headers="projectHeaders" :items="projects" :items-per-page="6">
          <template v-slot:item.project_name="{item}"><strong>{{item.project_name}}</strong></template>
          <template v-slot:item.sales_value="{item}">PKR {{format(item.sales_value)}}</template>
          <template v-slot:item.collected="{item}"><span class="success--text font-weight-medium">PKR {{format(item.collected)}}</span></template>
          <template v-slot:no-data><div class="empty-table">No project performance data.</div></template>
        </v-data-table>
      </v-card>
    </v-col>

    <v-col cols="12" lg="5">
      <v-card flat class="panel">
        <v-tabs v-model="activityTab" color="#165134" grow>
          <v-tab>Bookings</v-tab>
          <v-tab>Payments</v-tab>
        </v-tabs>
        <v-divider/>

        <v-tabs-items v-model="activityTab">
          <v-tab-item>
            <v-list v-if="recentBookings.length" two-line>
              <v-list-item v-for="item in recentBookings" :key="item.id">
                <v-list-item-avatar class="soft-green"><v-icon color="#165134">mdi-home-check-outline</v-icon></v-list-item-avatar>
                <v-list-item-content>
                  <v-list-item-title>{{item.booking_number}} · {{item.customer ? item.customer.name : 'Customer'}}</v-list-item-title>
                  <v-list-item-subtitle>{{item.property ? item.property.property_number : 'Property'}} · {{date(item.booking_date)}}</v-list-item-subtitle>
                </v-list-item-content>
                <div class="font-weight-bold">PKR {{compact(item.final_price)}}</div>
              </v-list-item>
            </v-list>
            <div v-else class="empty-table">No recent bookings.</div>
          </v-tab-item>

          <v-tab-item>
            <v-list v-if="recentPayments.length" two-line>
              <v-list-item v-for="item in recentPayments" :key="item.id">
                <v-list-item-avatar class="soft-blue"><v-icon color="blue">mdi-cash-check</v-icon></v-list-item-avatar>
                <v-list-item-content>
                  <v-list-item-title>{{item.receipt_number}} · {{item.customer ? item.customer.name : 'Customer'}}</v-list-item-title>
                  <v-list-item-subtitle>{{item.booking ? item.booking.booking_number : 'Booking'}} · {{date(item.payment_date)}}</v-list-item-subtitle>
                </v-list-item-content>
                <div class="font-weight-bold success--text">PKR {{compact(item.amount)}}</div>
              </v-list-item>
            </v-list>
            <div v-else class="empty-table">No recent payments.</div>
          </v-tab-item>
        </v-tabs-items>
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
      error:'',
      range:'6m',
      activityTab:0,
      period:{},
      metrics:{},
      trends:{},
      financials:[],
      leadPipeline:[],
      agents:[],
      projects:[],
      recentBookings:[],
      recentPayments:[],

      kpiCards:[
        {key:'sales_value',label:'Sales Value',money:true,icon:'mdi-chart-line',color:'#165134',softClass:'soft-green',trendKey:'sales_value',route:'bookings',permission:'sales.view'},
        {key:'collections',label:'Collections',money:true,icon:'mdi-cash-check',color:'blue darken-1',softClass:'soft-blue',trendKey:'collections',route:'payments',permission:'payments.view'},
        {key:'net_cash_flow',label:'Net Cash Flow',money:true,icon:'mdi-swap-vertical-bold',color:'purple darken-1',softClass:'soft-purple',trendKey:'net_cash_flow',route:'reports',permission:'reports.view'},
        {key:'receivables',label:'Receivables',money:true,icon:'mdi-cash-clock',color:'orange darken-2',softClass:'soft-amber',helper:'Outstanding customer balance',route:'installments',permission:'installments.view'},
        {key:'expenses',label:'Expenses',money:true,icon:'mdi-receipt-text-minus-outline',color:'deep-orange darken-1',softClass:'soft-amber',trendKey:'expenses',invertTrend:true,route:'expenses',permission:'expenses.view'},
        {key:'overdue_amount',label:'Overdue',money:true,icon:'mdi-alert-circle-outline',color:'red darken-1',softClass:'soft-red',helper:'Past-due installment balance',route:'installments',permission:'installments.view',query:{status:'overdue'}}
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
        weekday:'short',
        day:'2-digit',
        month:'short',
        year:'numeric'
      }).format(new Date())
    },

    periodLabel(){
      if(!this.period.from||!this.period.to)return'Current period'
      return this.date(this.period.from)+' — '+this.date(this.period.to)
    },

    chartMax(){
      const values=[]
      this.financials.forEach(function(x){
        values.push(Number(x.sales||0),Number(x.collections||0),Number(x.expenses||0))
      })
      return Math.max.apply(null,values.concat([1]))
    },

    gridLines(){
      const lines=[]
      for(let i=0;i<5;i++){
        lines.push({
          y:28+(i*42),
          value:this.chartMax*((4-i)/4)
        })
      }
      return lines
    },

    pipelineMap(){
      const map={}
      this.leadPipeline.forEach(function(x){map[x.status]=Number(x.total||0)})
      return map
    },

    totalPipelineLeads(){
      return Object.keys(this.pipelineMap).reduce((sum,key)=>sum+Number(this.pipelineMap[key]||0),0)
    },

    leadStages(){
      const defs=[
        ['new','New','#78909c'],
        ['contacted','Contacted','#1976d2'],
        ['qualified','Qualified','#5e35b1'],
        ['site_visit','Site Visit','#ef6c00'],
        ['negotiation','Negotiation','#8e24aa']
      ]
      const max=Math.max.apply(null,defs.map(x=>Number(this.pipelineMap[x[0]]||0)).concat([1]))

      return defs.map(x=>({
        key:x[0],
        label:x[1],
        color:x[2],
        value:Number(this.pipelineMap[x[0]]||0),
        percent:Number(this.pipelineMap[x[0]]||0)/max*100
      }))
    },

    inventoryItems(){
      return[
        {label:'Available',value:Number(this.metrics.available_properties||0),color:'#2e7d32'},
        {label:'Reserved',value:Number(this.metrics.reserved_properties||0),color:'#ef9a00'},
        {label:'Booked',value:Number(this.metrics.booked_properties||0),color:'#1976d2'},
        {label:'Sold',value:Number(this.metrics.sold_properties||0),color:'#d32f2f'}
      ]
    },

    inventoryTotal(){
      return this.inventoryItems.reduce((sum,x)=>sum+x.value,0)
    },

    inventoryDonutStyle(){
      const total=this.inventoryTotal||1
      let cursor=0
      const segments=this.inventoryItems.map(function(item){
        const start=cursor
        cursor+=item.value/total*100
        return item.color+' '+start+'% '+cursor+'%'
      })
      return{background:'conic-gradient('+segments.join(',')+')'}
    },

    attention(){
      return[
        {label:'Overdue installments',helper:'Require collection follow-up',value:this.metrics.overdue_count||0,icon:'mdi-alert-decagram-outline',color:'red darken-2',softClass:'soft-red',valueClass:'error--text',route:'installments',permission:'installments.view',query:{status:'overdue'}},
        {label:'Receivables',helper:'Outstanding customer balance',value:this.metrics.receivables||0,money:true,icon:'mdi-cash-clock',color:'orange darken-2',softClass:'soft-amber',valueClass:'orange--text text--darken-2',route:'installments',permission:'installments.view'},
        {label:'Active leads',helper:'Prospects in the pipeline',value:this.metrics.active_leads||0,icon:'mdi-account-star-outline',color:'blue darken-1',softClass:'soft-blue',route:'leads',permission:'leads.view'},
        {label:'Scheduled visits',helper:'Upcoming customer visits',value:this.metrics.scheduled_visits||0,icon:'mdi-map-marker-clock-outline',color:'purple darken-1',softClass:'soft-purple',route:'site-visits',permission:'site_visits.view',query:{status:'scheduled'}}
      ]
    }
  },

  mounted(){
    this.changeRange()
  },

  methods:{
    localDate(d){
      const y=d.getFullYear()
      const m=String(d.getMonth()+1).padStart(2,'0')
      const day=String(d.getDate()).padStart(2,'0')
      return y+'-'+m+'-'+day
    },

    changeRange(){
      const now=new Date()
      let from=new Date(now.getFullYear(),now.getMonth()-5,1)

      if(this.range==='ytd'){
        from=new Date(now.getFullYear(),0,1)
      }

      if(this.range==='12m'){
        from=new Date(now.getFullYear(),now.getMonth()-11,1)
      }

      this.load(this.localDate(from),this.localDate(now))
    },

    async load(from,to){
      this.error=''

      try{
        const params={}
        if(from)params.from=from
        if(to)params.to=to

        const r=await api.get('/sales/dashboard',{params:params})
        const d=r.data||{}

        this.period=d.period||{}
        this.metrics=d.metrics||{}
        this.trends=d.trends||{}
        this.financials=d.monthly_financials||[]
        this.leadPipeline=d.lead_pipeline||[]
        this.agents=d.agent_performance||[]
        this.projects=d.project_performance||[]
        this.recentBookings=d.recent_bookings||[]
        this.recentPayments=d.recent_payments||[]
      }catch(e){
        this.error=(e.response&&e.response.data&&e.response.data.message)||'Unable to load dashboard.'
      }
    },

    canOpen(item){
      return !item||!item.permission||this.$can(item.permission)
    },

    go(item){
      if(!item||!item.route||!this.canOpen(item))return
      this.$router.push({name:item.route,query:item.query||{}}).catch(function(){})
    },

    trendValue(card){
      return Number(this.trends[card.trendKey]||0)
    },

    trendPositive(card){
      const value=this.trendValue(card)
      return card.invertTrend ? value<=0 : value>=0
    },

    trendClass(card){
      return this.trendPositive(card)?'trend-good':'trend-bad'
    },

    trendColor(card){
      return this.trendPositive(card)?'success':'error'
    },

    trendIcon(card){
      const value=this.trendValue(card)
      if(value===0)return'mdi-minus'
      return value>0?'mdi-arrow-up':'mdi-arrow-down'
    },

    chartX(index){
      if(this.financials.length<=1)return377
      return54+(646*(index/(this.financials.length-1)))
    },

    chartY(value){
      return196-(168*(Number(value||0)/this.chartMax))
    },

    chartPoints(key){
      return this.financials.map((item,index)=>this.chartX(index)+','+this.chartY(item[key])).join(' ')
    },

    showMonthLabel(index){
      if(this.financials.length<=8)return true
      return index%2===0||index===this.financials.length-1
    },

    pipelineCount(status){
      return Number(this.pipelineMap[status]||0)
    },

    format(v){
      return new Intl.NumberFormat('en-PK',{maximumFractionDigits:0}).format(Number(v||0))
    },

    compact(v){
      return new Intl.NumberFormat('en-PK',{notation:'compact',maximumFractionDigits:1}).format(Number(v||0))
    },

    date(v){
      if(!v)return'—'
      const raw=String(v).slice(0,10)
      const d=new Date(raw+'T00:00:00')
      if(Number.isNaN(d.getTime()))return raw
      return new Intl.DateTimeFormat('en-PK',{day:'2-digit',month:'short',year:'numeric'}).format(d)
    },

    month(v){
      const p=String(v).split('-')
      if(p.length!==2)return v
      return new Intl.DateTimeFormat('en-PK',{month:'short',year:'numeric'}).format(new Date(Number(p[0]),Number(p[1])-1,1))
    },

    monthShort(v){
      const p=String(v).split('-')
      if(p.length!==2)return v
      return new Intl.DateTimeFormat('en-PK',{month:'short'}).format(new Date(Number(p[0]),Number(p[1])-1,1))
    }
  }
}
</script>

<style scoped>
.dashboard{width:100%;padding-bottom:16px}
.dashboard-header{display:flex;align-items:flex-end;justify-content:space-between;gap:20px;flex-wrap:wrap}
.header-actions{display:flex;align-items:center;gap:8px}
.range-toggle{border:1px solid rgba(22,81,52,.12);border-radius:10px!important;overflow:hidden}
.range-toggle ::v-deep .v-btn--active{background:#165134!important;color:#fff!important}
.period-strip{display:flex;align-items:center;padding:10px 14px;border:1px solid rgba(22,81,52,.08);border-radius:12px;background:rgba(22,81,52,.025);font-size:13px}
.section-kicker{color:#165134!important;line-height:1.2;letter-spacing:.1em}
.metric-card,.panel{border:1px solid rgba(22,81,52,.08);border-radius:16px!important;box-shadow:0 7px 22px rgba(20,50,35,.045)!important}
.metric-card{transition:transform .18s ease,box-shadow .18s ease}
.metric-label{letter-spacing:.04em}
.clickable{cursor:pointer}
.clickable:hover{transform:translateY(-2px);box-shadow:0 12px 28px rgba(20,50,35,.09)!important}
.soft-green{background:#e8f5ee!important}
.soft-blue{background:#eaf2ff!important}
.soft-amber{background:#fff5df!important}
.soft-red{background:#fdecec!important}
.soft-purple{background:#f3eefe!important}
.trend-pill{display:inline-flex;align-items:center;padding:2px 7px;border-radius:20px;font-size:11px;font-weight:700}
.trend-good{background:#e9f7ee;color:#237a3b}
.trend-bad{background:#fdecec;color:#c62828}
.chart-legend{display:flex;gap:14px;flex-wrap:wrap;font-size:12px;color:#6b746f}
.chart-legend span{display:flex;align-items:center}
.legend-dot{width:8px;height:8px;border-radius:50%;display:inline-block;margin-right:5px}
.sales-dot{background:#165134}.collection-dot{background:#1976d2}.expense-dot{background:#ef6c00}
.trend-chart-wrap{width:100%;overflow:hidden}
.trend-chart{width:100%;height:300px;display:block}
.grid line{stroke:rgba(0,0,0,.08);stroke-width:1}
.grid text,.month-label{fill:#86908b;font-size:10px}
.trend-line{stroke-width:3;stroke-linecap:round;stroke-linejoin:round;vector-effect:non-scaling-stroke}
.sales-line{stroke:#165134}.collection-line{stroke:#1976d2}.expense-line{stroke:#ef6c00}
.chart-point{stroke:#fff;stroke-width:2;vector-effect:non-scaling-stroke}
.sales-point{fill:#165134}.collection-point{fill:#1976d2}.expense-point{fill:#ef6c00}
.pipeline-row{margin-bottom:15px}
.pipeline-track{height:8px;border-radius:10px;background:rgba(128,128,128,.12);overflow:hidden}
.pipeline-fill{height:100%;border-radius:10px;transition:width .3s ease}
.inventory-chart{display:flex;align-items:center;justify-content:center;gap:28px;min-height:230px}
.donut{width:150px;height:150px;border-radius:50%;position:relative;display:flex;align-items:center;justify-content:center;flex-shrink:0}
.donut:after{content:'';position:absolute;width:94px;height:94px;border-radius:50%;background:var(--v-background-base,#fff)}
.donut-center{position:relative;z-index:2;text-align:center}
.inventory-legend{width:100%}
.inventory-item{display:flex;align-items:center;justify-content:space-between;padding:8px 0;border-bottom:1px solid rgba(0,0,0,.05);font-size:13px}
.inventory-item:last-child{border-bottom:0}
.legend-square{width:9px;height:9px;border-radius:3px;display:inline-block;margin-right:8px}
.attention-row{display:flex;align-items:center;padding:12px 4px;border-bottom:1px solid rgba(0,0,0,.055)}
.attention-row:last-child{border-bottom:0}
.clickable-row{cursor:pointer;border-radius:10px}
.clickable-row:hover{background:rgba(22,81,52,.035)}
.rank-row{display:flex;align-items:center;gap:10px;padding:11px 0;border-bottom:1px solid rgba(0,0,0,.055)}
.rank-row:last-child{border-bottom:0}
.rank-number{width:28px;height:28px;border-radius:9px;background:rgba(22,81,52,.08);color:#165134;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:12px}
.empty-state{min-height:260px;display:flex;flex-direction:column;align-items:center;justify-content:center;color:#888}
.compact-empty{min-height:180px}
.empty-table{padding:28px;text-align:center;color:#888}
.min-width-0{min-width:0}
.dashboard ::v-deep .v-data-table__wrapper{overflow-x:auto}
@media(max-width:960px){
  .trend-chart{height:260px}
  .inventory-chart{flex-direction:column}
  .inventory-legend{max-width:300px}
}
@media(max-width:600px){
  .dashboard-header{align-items:flex-start}
  .header-actions{width:100%;justify-content:space-between}
  .range-toggle{flex:1}
  .range-toggle ::v-deep .v-btn{flex:1}
  .trend-chart{height:230px}
  .chart-legend{width:100%;margin-top:8px}
}
</style>
