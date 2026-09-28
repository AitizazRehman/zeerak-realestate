<template>
  <div class="accounts-receivable-page">
    <v-card flat class="hero pa-5 mb-4">
      <div class="d-flex align-center flex-wrap">
        <div>
          <div class="text-overline">RECEIVABLES CONTROL</div>
          <h1 class="text-h5 font-weight-bold mb-1">Accounts Receivable & Aging</h1>
          <div class="grey--text">Customer and project receivables aged by installment due date, with unscheduled balances kept visible in Current.</div>
        </div>
        <v-spacer/>
        <v-chip outlined color="#165134" class="mt-2 mt-md-0">
          Aging date · {{ filters.as_of }}
        </v-chip>
      </div>
    </v-card>

    <v-card flat class="filter-card pa-4 mb-4">
      <v-row dense>
        <v-col cols="12" md="3">
          <v-text-field
            v-model="filters.search"
            outlined dense hide-details
            prepend-inner-icon="mdi-magnify"
            label="Customer / Booking / Property"
            @keyup.enter="applyFilters"
          />
        </v-col>
        <v-col v-if="branches.length" cols="12" sm="6" md="2">
          <v-select
            v-model="filters.branch_id"
            :items="branches"
            item-text="name"
            item-value="id"
            outlined dense hide-details clearable
            label="Branch"
            @change="branchChanged"
          />
        </v-col>
        <v-col cols="12" sm="6" md="2">
          <v-autocomplete
            v-model="filters.project_id"
            :items="projects"
            item-text="display"
            item-value="id"
            outlined dense hide-details clearable
            label="Project"
          />
        </v-col>
        <v-col cols="12" sm="6" md="2">
          <v-autocomplete
            v-model="filters.customer_id"
            :items="customers"
            item-text="display"
            item-value="id"
            outlined dense hide-details clearable
            label="Customer"
          />
        </v-col>
        <v-col cols="12" sm="6" md="1">
          <v-select
            v-model="filters.bucket"
            :items="bucketOptions"
            item-text="text"
            item-value="value"
            outlined dense hide-details clearable
            label="Bucket"
          />
        </v-col>
        <v-col cols="12" sm="6" md="2">
          <v-text-field
            v-model="filters.as_of"
            type="date"
            outlined dense hide-details
            label="Aging Date"
          />
        </v-col>
      </v-row>

      <div class="d-flex justify-end mt-3">
        <v-btn text class="mr-2" @click="resetFilters">
          <v-icon left>mdi-filter-remove-outline</v-icon>
          Reset
        </v-btn>
        <v-btn color="#165134" dark depressed :loading="loading" @click="applyFilters">
          <v-icon left>mdi-refresh</v-icon>
          Apply
        </v-btn>
      </div>
    </v-card>

    <v-row class="mb-1">
      <v-col cols="6" md="2">
        <v-card flat class="summary-card pa-4">
          <div class="caption grey--text">Total Receivables</div>
          <div class="text-h6 font-weight-bold">PKR {{ money(summary.total) }}</div>
          <div class="caption grey--text">{{ summary.customers_with_balance || 0 }} customers</div>
        </v-card>
      </v-col>
      <v-col cols="6" md="2">
        <v-card flat class="summary-card pa-4">
          <div class="caption grey--text">Current</div>
          <div class="text-h6 font-weight-bold success--text">PKR {{ money(summary.current) }}</div>
          <div class="caption grey--text">Not overdue</div>
        </v-card>
      </v-col>
      <v-col cols="6" md="2">
        <v-card flat class="summary-card pa-4">
          <div class="caption grey--text">Overdue</div>
          <div class="text-h6 font-weight-bold warning--text">PKR {{ money(summary.overdue) }}</div>
          <div class="caption grey--text">{{ summary.overdue_percentage || 0 }}% of AR</div>
        </v-card>
      </v-col>
      <v-col cols="6" md="2">
        <v-card flat class="summary-card pa-4">
          <div class="caption grey--text">90+ Days</div>
          <div class="text-h6 font-weight-bold error--text">PKR {{ money(summary['90_plus']) }}</div>
          <div class="caption grey--text">Highest risk bucket</div>
        </v-card>
      </v-col>
      <v-col cols="6" md="2">
        <v-card flat class="summary-card pa-4">
          <div class="caption grey--text">Unallocated</div>
          <div class="text-h6 font-weight-bold">PKR {{ money(summary.unallocated) }}</div>
          <div class="caption grey--text">Not yet scheduled</div>
        </v-card>
      </v-col>
      <v-col cols="6" md="2">
        <v-card flat class="summary-card pa-4">
          <div class="caption grey--text">Overdue Installments</div>
          <div class="text-h6 font-weight-bold">{{ summary.overdue_installments || 0 }}</div>
          <div class="caption grey--text">{{ summary.overdue_customers || 0 }} customers</div>
        </v-card>
      </v-col>
    </v-row>

    <v-card flat class="aging-card pa-4 mb-4">
      <div class="d-flex align-center flex-wrap mb-3">
        <div>
          <div class="subtitle-1 font-weight-bold">Aging Distribution</div>
          <div class="caption grey--text">Current includes future installments plus booking balances not yet allocated to an active installment schedule.</div>
        </div>
        <v-spacer/>
        <div class="caption grey--text">Total PKR {{ money(summary.total) }}</div>
      </div>

      <div class="aging-bar">
        <div
          v-for="bucket in distribution"
          :key="bucket.key"
          class="aging-segment"
          :class="'bucket-'+bucket.key"
          :style="{width:bucket.percent+'%'}"
          :title="bucket.label+' · PKR '+money(bucket.amount)"
        />
      </div>
      <div class="aging-legend mt-3">
        <div v-for="bucket in distribution" :key="bucket.key" class="legend-item">
          <span class="legend-dot" :class="'bucket-'+bucket.key"/>
          <span>{{ bucket.label }}</span>
          <strong>PKR {{ money(bucket.amount) }}</strong>
          <span class="grey--text">({{ bucket.percent.toFixed(1) }}%)</span>
        </div>
      </div>
    </v-card>

    <v-card flat class="table-card">
      <v-tabs v-model="tab" color="#165134">
        <v-tab>Customers</v-tab>
        <v-tab>Projects</v-tab>
      </v-tabs>
      <v-divider/>

      <v-tabs-items v-model="tab">
        <v-tab-item>
          <v-data-table
            :headers="customerHeaders"
            :items="customerRows"
            :loading="loading"
            :server-items-length="customerTotal"
            :page.sync="page"
            :items-per-page.sync="perPage"
            :footer-props="{'items-per-page-options':[10,25,50,100]}"
            @update:page="loadAging"
            @update:items-per-page="perPageChanged"
          >
            <template v-slot:item.customer="{item}">
              <div class="font-weight-medium">{{ item.customer_name }}</div>
              <div class="caption grey--text">
                {{ item.customer_number || 'No customer number' }}
                <span v-if="item.customer_phone"> · {{ item.customer_phone }}</span>
              </div>
            </template>
            <template v-slot:item.total="{item}">
              <strong>PKR {{ money(item.total) }}</strong>
              <div class="caption grey--text">{{ item.booking_count }} booking(s) · {{ item.project_count }} project(s)</div>
            </template>
            <template v-slot:item.current="{item}">PKR {{ money(item.current) }}</template>
            <template v-slot:[`item.1_30`]="{item}">PKR {{ money(item['1_30']) }}</template>
            <template v-slot:[`item.31_60`]="{item}">PKR {{ money(item['31_60']) }}</template>
            <template v-slot:[`item.61_90`]="{item}">PKR {{ money(item['61_90']) }}</template>
            <template v-slot:[`item.90_plus`]="{item}">
              <span :class="Number(item['90_plus']) > 0 ? 'error--text font-weight-bold' : ''">
                PKR {{ money(item['90_plus']) }}
              </span>
            </template>
            <template v-slot:item.overdue="{item}">
              <div :class="Number(item.overdue) > 0 ? 'warning--text font-weight-bold' : 'success--text'">
                PKR {{ money(item.overdue) }}
              </div>
              <div v-if="item.max_days_overdue" class="caption grey--text">
                up to {{ item.max_days_overdue }} days
              </div>
            </template>
            <template v-slot:item.collection_rate="{item}">
              <div class="d-flex align-center">
                <v-progress-linear
                  :value="Number(item.collection_rate || 0)"
                  height="7"
                  rounded
                  class="mr-2"
                  style="max-width:70px"
                />
                <span class="caption">{{ item.collection_rate || 0 }}%</span>
              </div>
            </template>
            <template v-slot:item.actions="{item}">
              <v-btn icon small color="#165134" title="View receivable detail" @click="openCustomer(item)">
                <v-icon small>mdi-eye-outline</v-icon>
              </v-btn>
            </template>
          </v-data-table>
        </v-tab-item>

        <v-tab-item>
          <v-data-table
            :headers="projectHeaders"
            :items="projectRows"
            :loading="loading"
            :items-per-page="25"
          >
            <template v-slot:item.project="{item}">
              <div class="font-weight-medium">{{ item.project_name }}</div>
              <div class="caption grey--text">{{ item.project_code || 'No project code' }}</div>
            </template>
            <template v-slot:item.total="{item}">
              <strong>PKR {{ money(item.total) }}</strong>
              <div class="caption grey--text">{{ item.customer_count }} customers · {{ item.booking_count }} bookings</div>
            </template>
            <template v-slot:item.current="{item}">PKR {{ money(item.current) }}</template>
            <template v-slot:[`item.1_30`]="{item}">PKR {{ money(item['1_30']) }}</template>
            <template v-slot:[`item.31_60`]="{item}">PKR {{ money(item['31_60']) }}</template>
            <template v-slot:[`item.61_90`]="{item}">PKR {{ money(item['61_90']) }}</template>
            <template v-slot:[`item.90_plus`]="{item}">
              <span :class="Number(item['90_plus']) > 0 ? 'error--text font-weight-bold' : ''">
                PKR {{ money(item['90_plus']) }}
              </span>
            </template>
            <template v-slot:item.overdue="{item}">
              <span :class="Number(item.overdue) > 0 ? 'warning--text font-weight-bold' : 'success--text'">
                PKR {{ money(item.overdue) }}
              </span>
            </template>
          </v-data-table>
        </v-tab-item>
      </v-tabs-items>
    </v-card>

    <v-dialog v-model="customerDialog" max-width="1200" scrollable>
      <v-card>
        <v-card-title>
          <div v-if="customerDetail">
            <div class="text-h6 font-weight-bold">{{ customerDetail.customer.name }}</div>
            <div class="caption grey--text">
              {{ customerDetail.customer.customer_number || 'No customer number' }}
              <span v-if="customerDetail.customer.phone"> · {{ customerDetail.customer.phone }}</span>
              <span v-if="customerDetail.customer.email"> · {{ customerDetail.customer.email }}</span>
            </div>
          </div>
          <v-spacer/>
          <v-btn icon @click="customerDialog=false"><v-icon>mdi-close</v-icon></v-btn>
        </v-card-title>
        <v-divider/>
        <v-card-text class="pt-4">
          <v-progress-linear v-if="customerLoading" indeterminate color="#165134"/>

          <template v-if="customerDetail && !customerLoading">
            <v-row class="mb-2">
              <v-col cols="6" md="3">
                <div class="caption grey--text">Outstanding</div>
                <div class="subtitle-1 font-weight-bold">PKR {{ money(customerDetail.summary.total) }}</div>
              </v-col>
              <v-col cols="6" md="3">
                <div class="caption grey--text">Current</div>
                <div class="subtitle-1 font-weight-bold success--text">PKR {{ money(customerDetail.summary.current) }}</div>
              </v-col>
              <v-col cols="6" md="3">
                <div class="caption grey--text">Overdue</div>
                <div class="subtitle-1 font-weight-bold warning--text">PKR {{ money(customerDetail.summary.overdue) }}</div>
              </v-col>
              <v-col cols="6" md="3">
                <div class="caption grey--text">90+ Days</div>
                <div class="subtitle-1 font-weight-bold error--text">PKR {{ money(customerDetail.summary['90_plus']) }}</div>
              </v-col>
            </v-row>

            <v-data-table
              :headers="bookingHeaders"
              :items="customerDetail.bookings || []"
              show-expand
              item-key="booking_id"
              :items-per-page="10"
            >
              <template v-slot:item.booking="{item}">
                <div class="font-weight-medium">{{ item.booking_number }}</div>
                <div class="caption grey--text">
                  {{ item.project_code || item.project_name || 'Project' }} · {{ item.property_number || 'Property' }}
                </div>
              </template>
              <template v-slot:item.total="{item}"><strong>PKR {{ money(item.total) }}</strong></template>
              <template v-slot:item.current="{item}">PKR {{ money(item.current) }}</template>
              <template v-slot:item.overdue="{item}">
                <span :class="Number(item.overdue) > 0 ? 'warning--text font-weight-bold' : 'success--text'">
                  PKR {{ money(item.overdue) }}
                </span>
              </template>
              <template v-slot:item.unallocated="{item}">PKR {{ money(item.unallocated) }}</template>
              <template v-slot:item.oldest_due_date="{item}">
                <div>{{ item.oldest_due_date ? dateLabel(item.oldest_due_date) : '—' }}</div>
                <div v-if="item.max_days_overdue" class="caption grey--text">{{ item.max_days_overdue }} days overdue</div>
              </template>

              <template v-slot:expanded-item="{headers,item}">
                <td :colspan="headers.length" class="pa-4">
                  <div class="subtitle-2 font-weight-bold mb-2">Installment Aging</div>
                  <v-simple-table dense>
                    <thead>
                      <tr>
                        <th>#</th>
                        <th>Due Date</th>
                        <th>Status</th>
                        <th>Bucket</th>
                        <th class="text-right">Scheduled</th>
                        <th class="text-right">Paid</th>
                        <th class="text-right">Outstanding</th>
                        <th class="text-right">Days Overdue</th>
                      </tr>
                    </thead>
                    <tbody>
                      <tr v-for="installment in item.installments || []" :key="installment.id">
                        <td>{{ installment.installment_number }}</td>
                        <td>{{ dateLabel(installment.due_date) }}</td>
                        <td>{{ installment.status }}</td>
                        <td>{{ bucketLabel(installment.aging_bucket) }}</td>
                        <td class="text-right">PKR {{ money(installment.amount) }}</td>
                        <td class="text-right">PKR {{ money(installment.paid_amount) }}</td>
                        <td class="text-right">PKR {{ money(installment.remaining_amount) }}</td>
                        <td class="text-right">{{ installment.days_overdue || '—' }}</td>
                      </tr>
                      <tr v-if="Number(item.unallocated || 0) > 0">
                        <td colspan="4"><strong>Unallocated booking balance</strong></td>
                        <td colspan="2"></td>
                        <td class="text-right"><strong>PKR {{ money(item.unallocated) }}</strong></td>
                        <td class="text-right">Current</td>
                      </tr>
                    </tbody>
                  </v-simple-table>
                </td>
              </template>
            </v-data-table>
          </template>
        </v-card-text>
      </v-card>
    </v-dialog>
  </div>
</template>

<script>
import api from '../../../services/api'

export default {
  name:'AccountsReceivable',

  data(){
    return{
      loading:false,
      optionsLoading:false,
      customerLoading:false,
      customerDialog:false,
      customerDetail:null,
      tab:0,
      page:1,
      perPage:25,
      customerTotal:0,
      customerRows:[],
      projectRows:[],
      summary:{},
      branches:[],
      projects:[],
      customers:[],
      bucketOptions:[],
      filters:{
        search:'',
        branch_id:null,
        project_id:null,
        customer_id:null,
        bucket:null,
        as_of:this.today()
      },
      customerHeaders:[
        {text:'Customer',value:'customer'},
        {text:'Total AR',value:'total',align:'right'},
        {text:'Current',value:'current',align:'right'},
        {text:'1–30',value:'1_30',align:'right'},
        {text:'31–60',value:'31_60',align:'right'},
        {text:'61–90',value:'61_90',align:'right'},
        {text:'90+',value:'90_plus',align:'right'},
        {text:'Overdue',value:'overdue',align:'right'},
        {text:'Collection',value:'collection_rate'},
        {text:'',value:'actions',sortable:false,align:'right'}
      ],
      projectHeaders:[
        {text:'Project',value:'project'},
        {text:'Total AR',value:'total',align:'right'},
        {text:'Current',value:'current',align:'right'},
        {text:'1–30',value:'1_30',align:'right'},
        {text:'31–60',value:'31_60',align:'right'},
        {text:'61–90',value:'61_90',align:'right'},
        {text:'90+',value:'90_plus',align:'right'},
        {text:'Overdue',value:'overdue',align:'right'}
      ],
      bookingHeaders:[
        {text:'Booking / Property',value:'booking'},
        {text:'Outstanding',value:'total',align:'right'},
        {text:'Current',value:'current',align:'right'},
        {text:'Overdue',value:'overdue',align:'right'},
        {text:'Unallocated',value:'unallocated',align:'right'},
        {text:'Oldest Due',value:'oldest_due_date'},
        {text:'',value:'data-table-expand'}
      ]
    }
  },

  computed:{
    distribution(){
      const total=Number(this.summary.total || 0)
      const buckets=[
        {key:'current',label:'Current',amount:Number(this.summary.current || 0)},
        {key:'1_30',label:'1–30',amount:Number(this.summary['1_30'] || 0)},
        {key:'31_60',label:'31–60',amount:Number(this.summary['31_60'] || 0)},
        {key:'61_90',label:'61–90',amount:Number(this.summary['61_90'] || 0)},
        {key:'90_plus',label:'90+',amount:Number(this.summary['90_plus'] || 0)}
      ]
      return buckets.map(function(bucket){
        return Object.assign({},bucket,{percent:total > 0 ? (bucket.amount/total)*100 : 0})
      })
    }
  },

  async mounted(){
    await this.loadOptions()
    await this.loadAging()
  },

  methods:{
    async loadOptions(){
      this.optionsLoading=true
      try{
        const response=await api.get('/accounting/accounts-receivable/options',{
          params:{branch_id:this.filters.branch_id || undefined},
          skipGlobalLoader:true
        })
        this.branches=response.data.branches || this.branches
        this.projects=(response.data.projects || []).map(function(item){
          return Object.assign({},item,{display:(item.code ? item.code+' · ' : '')+item.name})
        })
        this.customers=(response.data.customers || []).map(function(item){
          return Object.assign({},item,{display:(item.customer_number ? item.customer_number+' · ' : '')+item.name})
        })
        this.bucketOptions=response.data.buckets || []
      }finally{
        this.optionsLoading=false
      }
    },

    async branchChanged(){
      this.filters.project_id=null
      this.filters.customer_id=null
      await this.loadOptions()
    },

    applyFilters(){
      this.page=1
      this.loadAging()
    },

    async resetFilters(){
      this.filters={
        search:'',
        branch_id:null,
        project_id:null,
        customer_id:null,
        bucket:null,
        as_of:this.today()
      }
      this.page=1
      await this.loadOptions()
      await this.loadAging()
    },

    perPageChanged(value){
      this.perPage=Number(value || 25)
      this.page=1
      this.loadAging()
    },

    async loadAging(){
      this.loading=true
      try{
        const params={
          as_of:this.filters.as_of,
          branch_id:this.filters.branch_id || undefined,
          project_id:this.filters.project_id || undefined,
          customer_id:this.filters.customer_id || undefined,
          search:this.filters.search || undefined,
          bucket:this.filters.bucket || undefined,
          page:this.page,
          per_page:this.perPage
        }

        const response=await api.get('/accounting/accounts-receivable/aging',{
          params:params,
          skipGlobalLoader:true
        })

        this.summary=response.data.summary || {}
        this.projectRows=response.data.projects || []
        const pager=response.data.customers || {}
        this.customerRows=pager.data || []
        this.customerTotal=Number(pager.total || 0)
      }catch(error){
        this.$root.$emit('show-error',this.errorMessage(error,'Unable to load accounts receivable aging.'))
      }finally{
        this.loading=false
      }
    },

    async openCustomer(item){
      this.customerDialog=true
      this.customerLoading=true
      this.customerDetail=null

      try{
        const response=await api.get('/accounting/accounts-receivable/customers/'+item.customer_id,{
          params:{
            as_of:this.filters.as_of,
            branch_id:this.filters.branch_id || undefined,
            project_id:this.filters.project_id || undefined
          },
          skipGlobalLoader:true
        })
        this.customerDetail=response.data
      }catch(error){
        this.customerDialog=false
        this.$root.$emit('show-error',this.errorMessage(error,'Unable to load customer receivable detail.'))
      }finally{
        this.customerLoading=false
      }
    },

    bucketLabel(value){
      const labels={
        current:'Current',
        '1_30':'1–30 Days',
        '31_60':'31–60 Days',
        '61_90':'61–90 Days',
        '90_plus':'90+ Days'
      }
      return labels[value] || value
    },

    money(value){
      if(value === null || value === undefined || value === '')return '0.00'
      return new Intl.NumberFormat('en-PK',{
        minimumFractionDigits:2,
        maximumFractionDigits:2
      }).format(Number(value || 0))
    },

    dateLabel(value){
      if(!value)return '—'
      const raw=String(value).slice(0,10)
      const parts=raw.split('-')
      if(parts.length !== 3)return raw
      return new Intl.DateTimeFormat('en-PK',{day:'2-digit',month:'short',year:'numeric'})
        .format(new Date(Number(parts[0]),Number(parts[1])-1,Number(parts[2])))
    },

    today(){
      const d=new Date()
      const offset=d.getTimezoneOffset()
      return new Date(d.getTime()-offset*60000).toISOString().slice(0,10)
    },

    errorMessage(error,fallback){
      if(error.response && error.response.data){
        const errors=error.response.data.errors || {}
        const first=Object.keys(errors)[0]
        if(first && errors[first] && errors[first][0])return errors[first][0]
        if(error.response.data.message)return error.response.data.message
      }
      return fallback
    }
  }
}
</script>

<style scoped>
.accounts-receivable-page{width:100%}
.hero{border-left:4px solid #165134}
.filter-card,.summary-card,.aging-card,.table-card{border:1px solid rgba(22,81,52,.08);border-radius:15px!important}
.aging-bar{display:flex;height:16px;border-radius:999px;overflow:hidden;background:rgba(128,128,128,.12)}
.aging-segment{height:100%;min-width:0;transition:width .25s ease}
.aging-legend{display:flex;flex-wrap:wrap;gap:12px 22px}
.legend-item{display:flex;align-items:center;gap:7px;font-size:12px}
.legend-dot{width:10px;height:10px;border-radius:50%;display:inline-block}
.bucket-current{background:#2e7d32}
.bucket-1_30{background:#f9a825}
.bucket-31_60{background:#ef6c00}
.bucket-61_90{background:#d84315}
.bucket-90_plus{background:#b71c1c}
.theme--dark .filter-card,.theme--dark .summary-card,.theme--dark .aging-card,.theme--dark .table-card{border-color:rgba(226,238,231,.09)}
</style>
