<template>
  <div class="treasury-page">
    <v-card flat class="hero pa-5 mb-4">
      <div class="d-flex align-center flex-wrap">
        <div>
          <div class="text-overline">TREASURY MANAGEMENT</div>
          <h1 class="text-h5 font-weight-bold mb-1">Cash Flow Forecast</h1>
          <div class="grey--text">Opening bank/cash liquidity plus customer collections, vendor obligations, and planned treasury commitments.</div>
        </div>
        <v-spacer/>
        <v-btn v-if="$can('accounting.create')" color="#165134" dark depressed @click="openCommitment">
          <v-icon left>mdi-calendar-plus</v-icon>Add Commitment
        </v-btn>
      </div>
    </v-card>

    <v-card flat class="filter-card pa-4 mb-4">
      <v-row dense>
        <v-col v-if="branches.length" cols="12" md="2">
          <v-select v-model="filters.branch_id" :items="branches" item-text="name" item-value="id" outlined dense hide-details clearable label="Branch" @change="branchChanged"/>
        </v-col>
        <v-col cols="12" md="2">
          <v-autocomplete v-model="filters.project_id" :items="projects" item-text="display" item-value="id" outlined dense hide-details clearable label="Project"/>
        </v-col>
        <v-col cols="12" md="2">
          <v-autocomplete v-model="filters.bank_account_id" :items="bankAccounts" item-text="display" item-value="id" outlined dense hide-details clearable label="Bank / Cash"/>
        </v-col>
        <v-col cols="6" md="1">
          <v-select v-model="filters.interval" :items="intervals" outlined dense hide-details label="Interval"/>
        </v-col>
        <v-col cols="6" md="1">
          <v-select v-model="filters.horizon_days" :items="horizons" item-text="text" item-value="value" outlined dense hide-details label="Horizon"/>
        </v-col>
        <v-col cols="6" md="1">
          <v-text-field v-model.number="filters.collection_percent" type="number" min="0" max="100" outlined dense hide-details label="AR %"/>
        </v-col>
        <v-col cols="6" md="1">
          <v-text-field v-model.number="filters.payable_percent" type="number" min="0" max="100" outlined dense hide-details label="AP %"/>
        </v-col>
        <v-col cols="12" md="1">
          <v-switch v-model="filters.use_commitment_probability" inset hide-details label="Weighted"/>
        </v-col>
        <v-col cols="12" md="1">
          <v-btn block color="#165134" dark depressed :loading="loading" @click="loadForecast">Apply</v-btn>
        </v-col>
      </v-row>
    </v-card>

    <v-row class="mb-1">
      <v-col cols="6" md="2"><v-card flat class="summary-card pa-4"><div class="caption grey--text">Opening Liquidity</div><div class="text-h6 font-weight-bold">PKR {{money(summary.opening_liquidity)}}</div></v-card></v-col>
      <v-col cols="6" md="2"><v-card flat class="summary-card pa-4"><div class="caption grey--text">Forecast Inflows</div><div class="text-h6 font-weight-bold success--text">PKR {{money(summary.forecast_inflows)}}</div></v-card></v-col>
      <v-col cols="6" md="2"><v-card flat class="summary-card pa-4"><div class="caption grey--text">Forecast Outflows</div><div class="text-h6 font-weight-bold error--text">PKR {{money(summary.forecast_outflows)}}</div></v-card></v-col>
      <v-col cols="6" md="2"><v-card flat class="summary-card pa-4"><div class="caption grey--text">Net Change</div><div class="text-h6 font-weight-bold" :class="Number(summary.net_change)>=0?'success--text':'error--text'">PKR {{money(summary.net_change)}}</div></v-card></v-col>
      <v-col cols="6" md="2"><v-card flat class="summary-card pa-4"><div class="caption grey--text">Projected Closing</div><div class="text-h6 font-weight-bold">PKR {{money(summary.projected_closing_liquidity)}}</div></v-card></v-col>
      <v-col cols="6" md="2"><v-card flat class="summary-card pa-4"><div class="caption grey--text">Funding Gap</div><div class="text-h6 font-weight-bold" :class="Number(summary.funding_gap)>0?'error--text':'success--text'">PKR {{money(summary.funding_gap)}}</div></v-card></v-col>
    </v-row>

    <v-alert v-if="Number(summary.unscheduled_receivables)>0" type="info" text dense class="mb-4">
      PKR {{money(summary.unscheduled_receivables)}} of customer receivables is not included in forecast timing because it is not assigned to an active installment due date.
    </v-alert>

    <v-card flat class="table-card mb-4">
      <v-card-title class="subtitle-1">
        Liquidity Projection
        <v-spacer/>
        <span class="caption grey--text">Snapshot {{forecast.snapshot_date || '—'}} · Through {{forecast.through_date || '—'}}</span>
      </v-card-title>
      <v-data-table :headers="periodHeaders" :items="periods" :loading="loading" :items-per-page="-1" hide-default-footer>
        <template v-slot:item.opening_balance="{item}">PKR {{money(item.opening_balance)}}</template>
        <template v-slot:item.inflows="{item}"><span class="success--text">PKR {{money(item.inflows)}}</span></template>
        <template v-slot:item.outflows="{item}"><span class="error--text">PKR {{money(item.outflows)}}</span></template>
        <template v-slot:item.net_flow="{item}"><span :class="Number(item.net_flow)>=0?'success--text':'error--text'">PKR {{money(item.net_flow)}}</span></template>
        <template v-slot:item.closing_balance="{item}"><strong :class="Number(item.closing_balance)<0?'error--text':''">PKR {{money(item.closing_balance)}}</strong></template>
      </v-data-table>
    </v-card>

    <v-row>
      <v-col cols="12" md="5">
        <v-card flat class="table-card">
          <v-card-title class="subtitle-1">Opening Bank / Cash Position</v-card-title>
          <v-data-table :headers="accountHeaders" :items="openingAccounts" :items-per-page="-1" hide-default-footer>
            <template v-slot:item.account="{item}">
              <div class="font-weight-medium">{{item.name}}</div>
              <div class="caption grey--text">{{item.bank_name || item.account_type}} · {{item.ledger_code}} {{item.ledger_name}}</div>
            </template>
            <template v-slot:item.balance="{item}"><strong>PKR {{money(item.balance)}}</strong></template>
          </v-data-table>
        </v-card>
      </v-col>

      <v-col cols="12" md="7">
        <v-card flat class="table-card">
          <v-card-title class="subtitle-1">Forecast Source Detail</v-card-title>
          <v-data-table :headers="flowHeaders" :items="flows" :items-per-page="20">
            <template v-slot:item.date="{item}">{{dateLabel(item.date)}}</template>
            <template v-slot:item.source="{item}"><v-chip x-small :color="sourceColor(item.source)" dark>{{sourceLabel(item.source)}}</v-chip></template>
            <template v-slot:item.forecast_amount="{item}"><strong :class="item.flow_type==='inflow'?'success--text':'error--text'">{{item.flow_type==='inflow'?'+':'-'}} PKR {{money(item.forecast_amount)}}</strong></template>
            <template v-slot:item.probability_percent="{item}">{{item.probability_percent}}%</template>
          </v-data-table>
        </v-card>
      </v-col>
    </v-row>

    <v-card flat class="table-card mt-4">
      <v-card-title>
        <span class="subtitle-1 font-weight-bold">Treasury Commitments</span>
        <v-spacer/>
        <v-btn small text color="#165134" @click="loadCommitments"><v-icon left small>mdi-refresh</v-icon>Refresh</v-btn>
      </v-card-title>
      <v-data-table
        :headers="commitmentHeaders"
        :items="commitments"
        :loading="commitmentLoading"
        :server-items-length="commitmentTotal"
        :page.sync="commitmentPage"
        :items-per-page.sync="commitmentPerPage"
        @update:page="loadCommitments"
      >
        <template v-slot:item.title="{item}">
          <div class="font-weight-medium">{{item.title}}</div>
          <div class="caption grey--text">{{item.category}}</div>
        </template>
        <template v-slot:item.expected_date="{item}">{{dateLabel(item.expected_date)}}</template>
        <template v-slot:item.amount="{item}">PKR {{money(item.amount)}}</template>
        <template v-slot:item.flow_type="{item}"><v-chip x-small :color="item.flow_type==='inflow'?'success':'error'" dark>{{item.flow_type}}</v-chip></template>
        <template v-slot:item.status="{item}"><v-chip x-small :color="item.status==='planned'?'info':item.status==='realized'?'success':'grey'" dark>{{item.status}}</v-chip></template>
        <template v-slot:item.actions="{item}">
          <v-btn v-if="$can('accounting.edit') && item.status==='planned'" icon small @click="openEditCommitment(item)"><v-icon small>mdi-pencil-outline</v-icon></v-btn>
          <v-btn v-if="$can('accounting.edit') && item.status==='planned'" icon small color="success" title="Mark realized" @click="setCommitmentStatus(item,'realized')"><v-icon small>mdi-check-circle-outline</v-icon></v-btn>
          <v-btn v-if="$can('accounting.edit') && item.status==='planned'" icon small color="grey" title="Cancel" @click="setCommitmentStatus(item,'cancelled')"><v-icon small>mdi-cancel</v-icon></v-btn>
        </template>
      </v-data-table>
    </v-card>

    <v-dialog v-model="commitmentDialog" max-width="800" persistent>
      <v-card>
        <v-card-title>{{commitmentForm.id?'Edit':'Add'}} Treasury Commitment</v-card-title>
        <v-card-text>
          <v-row>
            <v-col v-if="branches.length" cols="12" md="4"><v-select v-model="commitmentForm.branch_id" :items="branches" item-text="name" item-value="id" outlined dense label="Branch *" @change="commitmentBranchChanged"/></v-col>
            <v-col cols="12" md="4"><v-autocomplete v-model="commitmentForm.project_id" :items="commitmentProjects" item-text="display" item-value="id" outlined dense clearable label="Project"/></v-col>
            <v-col cols="12" md="4"><v-autocomplete v-model="commitmentForm.bank_account_id" :items="commitmentBankAccounts" item-text="display" item-value="id" outlined dense clearable label="Bank / Cash Account"/></v-col>
            <v-col cols="12" md="4"><v-select v-model="commitmentForm.flow_type" :items="flowTypes" outlined dense label="Flow Type *"/></v-col>
            <v-col cols="12" md="4"><v-combobox v-model="commitmentForm.category" :items="categories" outlined dense label="Category *"/></v-col>
            <v-col cols="12" md="4"><v-text-field v-model="commitmentForm.expected_date" type="date" outlined dense label="Expected Date *"/></v-col>
            <v-col cols="12"><v-text-field v-model="commitmentForm.title" outlined dense label="Title / Counterparty *"/></v-col>
            <v-col cols="12" md="6"><v-text-field v-model.number="commitmentForm.amount" type="number" step="0.01" min="0.01" outlined dense label="Amount *"/></v-col>
            <v-col cols="12" md="6"><v-text-field v-model.number="commitmentForm.probability_percent" type="number" min="0" max="100" outlined dense label="Probability %"/></v-col>
            <v-col cols="12"><v-textarea v-model="commitmentForm.notes" rows="2" outlined dense label="Notes"/></v-col>
          </v-row>
        </v-card-text>
        <v-card-actions><v-spacer/><v-btn text @click="commitmentDialog=false">Cancel</v-btn><v-btn color="#165134" dark depressed :loading="commitmentSaving" @click="saveCommitment">Save</v-btn></v-card-actions>
      </v-card>
    </v-dialog>
  </div>
</template>

<script>
import api from '../../../services/api'

export default {
  name:'Treasury',
  data(){
    return{
      loading:false,commitmentLoading:false,commitmentSaving:false,commitmentDialog:false,
      forecast:{},summary:{},periods:[],flows:[],openingAccounts:[],
      branches:[],projects:[],bankAccounts:[],categories:[],
      commitmentProjects:[],commitmentBankAccounts:[],
      commitments:[],commitmentTotal:0,commitmentPage:1,commitmentPerPage:25,
      filters:{branch_id:null,project_id:null,bank_account_id:null,interval:'weekly',horizon_days:90,collection_percent:100,payable_percent:100,use_commitment_probability:true},
      intervals:['weekly','monthly'],
      horizons:[{text:'30 days',value:30},{text:'60 days',value:60},{text:'90 days',value:90},{text:'180 days',value:180},{text:'365 days',value:365}],
      flowTypes:['inflow','outflow'],
      commitmentForm:{},
      periodHeaders:[
        {text:'Period',value:'label'},{text:'Opening',value:'opening_balance',align:'right'},{text:'Inflows',value:'inflows',align:'right'},
        {text:'Outflows',value:'outflows',align:'right'},{text:'Net',value:'net_flow',align:'right'},{text:'Closing',value:'closing_balance',align:'right'},{text:'Flows',value:'flow_count',align:'right'}
      ],
      accountHeaders:[{text:'Account',value:'account'},{text:'Balance',value:'balance',align:'right'}],
      flowHeaders:[
        {text:'Date',value:'date'},{text:'Source',value:'source'},{text:'Reference',value:'reference'},{text:'Counterparty',value:'counterparty'},
        {text:'Project',value:'project_name'},{text:'Category',value:'category'},{text:'Probability',value:'probability_percent',align:'right'},{text:'Forecast',value:'forecast_amount',align:'right'}
      ],
      commitmentHeaders:[
        {text:'Title',value:'title'},{text:'Type',value:'flow_type'},{text:'Branch',value:'branch.name'},{text:'Project',value:'project.name'},
        {text:'Expected',value:'expected_date'},{text:'Amount',value:'amount',align:'right'},{text:'Probability',value:'probability_percent',align:'right'},
        {text:'Status',value:'status'},{text:'',value:'actions',sortable:false}
      ]
    }
  },
  async mounted(){
    await this.loadOptions()
    await Promise.all([this.loadForecast(),this.loadCommitments()])
  },
  methods:{
    async loadOptions(branchId){
      const selected=branchId===undefined?this.filters.branch_id:branchId
      const r=await api.get('/accounting/treasury/options',{skipGlobalLoader:true})
      this.branches=r.data.branches||[]
      const projects=r.data.projects||[]
      const accounts=r.data.bank_accounts||[]
      this.projects=projects.filter(x=>!selected||Number(x.branch_id)===Number(selected)).map(x=>Object.assign({},x,{display:(x.code?x.code+' · ':'')+x.name}))
      this.bankAccounts=accounts.filter(x=>!selected||!x.branch_id||Number(x.branch_id)===Number(selected)).map(x=>Object.assign({},x,{display:(x.bank_name?x.bank_name+' · ':'')+x.name}))
      this.categories=r.data.commitment_categories||[]
      this.commitmentProjects=this.projects
      this.commitmentBankAccounts=this.bankAccounts
    },
    async branchChanged(){
      this.filters.project_id=null;this.filters.bank_account_id=null
      await this.loadOptions(this.filters.branch_id)
    },
    params(){
      return{
        branch_id:this.filters.branch_id||undefined,
        project_id:this.filters.project_id||undefined,
        bank_account_id:this.filters.bank_account_id||undefined,
        interval:this.filters.interval,
        horizon_days:this.filters.horizon_days,
        collection_percent:this.filters.collection_percent,
        payable_percent:this.filters.payable_percent,
        use_commitment_probability:this.filters.use_commitment_probability?1:0
      }
    },
    async loadForecast(){
      this.loading=true
      try{
        const r=await api.get('/accounting/treasury/forecast',{params:this.params(),skipGlobalLoader:true})
        this.forecast=r.data||{}
        this.summary=r.data.summary||{}
        this.periods=r.data.periods||[]
        this.flows=r.data.flows||[]
        this.openingAccounts=(r.data.opening_liquidity&&r.data.opening_liquidity.accounts)||[]
      }catch(e){this.$root.$emit('show-error',this.errorText(e,'Unable to load treasury forecast.'))}
      finally{this.loading=false}
    },
    async loadCommitments(){
      this.commitmentLoading=true
      try{
        const r=await api.get('/accounting/treasury/commitments',{params:{branch_id:this.filters.branch_id||undefined,project_id:this.filters.project_id||undefined,page:this.commitmentPage,per_page:this.commitmentPerPage},skipGlobalLoader:true})
        this.commitments=r.data.data||[];this.commitmentTotal=Number(r.data.total||0)
      }finally{this.commitmentLoading=false}
    },
    blankCommitment(){
      return{id:null,branch_id:this.filters.branch_id||null,project_id:this.filters.project_id||null,bank_account_id:this.filters.bank_account_id||null,flow_type:'outflow',category:'Other',title:'',expected_date:this.today(),amount:null,probability_percent:100,notes:''}
    },
    openCommitment(){this.commitmentForm=this.blankCommitment();this.commitmentDialog=true;this.prepareCommitmentOptions()},
    openEditCommitment(item){this.commitmentForm={id:item.id,branch_id:item.branch_id,project_id:item.project_id,bank_account_id:item.bank_account_id,flow_type:item.flow_type,category:item.category,title:item.title,expected_date:String(item.expected_date).slice(0,10),amount:Number(item.amount),probability_percent:Number(item.probability_percent),notes:item.notes||''};this.commitmentDialog=true;this.prepareCommitmentOptions()},
    prepareCommitmentOptions(){
      const branch=this.commitmentForm.branch_id
      this.commitmentProjects=this.projects.filter(x=>!branch||!x.branch_id||Number(x.branch_id)===Number(branch))
      this.commitmentBankAccounts=this.bankAccounts.filter(x=>!branch||!x.branch_id||Number(x.branch_id)===Number(branch))
    },
    commitmentBranchChanged(){this.commitmentForm.project_id=null;this.commitmentForm.bank_account_id=null;this.prepareCommitmentOptions()},
    async saveCommitment(){
      this.commitmentSaving=true
      try{
        const payload=Object.assign({},this.commitmentForm);delete payload.id
        if(this.commitmentForm.id)await api.put('/accounting/treasury/commitments/'+this.commitmentForm.id,payload,{skipGlobalError:true})
        else await api.post('/accounting/treasury/commitments',payload,{skipGlobalError:true})
        this.commitmentDialog=false
        await Promise.all([this.loadCommitments(),this.loadForecast()])
      }catch(e){this.$root.$emit('show-error',this.errorText(e,'Unable to save treasury commitment.'))}
      finally{this.commitmentSaving=false}
    },
    async setCommitmentStatus(item,status){
      const payload={status}
      if(status==='realized')payload.realized_date=this.today()
      try{
        await api.patch('/accounting/treasury/commitments/'+item.id+'/status',payload,{skipGlobalError:true})
        await Promise.all([this.loadCommitments(),this.loadForecast()])
      }catch(e){this.$root.$emit('show-error',this.errorText(e,'Unable to update commitment status.'))}
    },
    sourceLabel(v){return v==='customer_installment'?'Customer AR':v==='vendor_bill'?'Vendor AP':'Commitment'},
    sourceColor(v){return v==='customer_installment'?'success':v==='vendor_bill'?'error':'info'},
    money(v){return new Intl.NumberFormat('en-PK',{minimumFractionDigits:2,maximumFractionDigits:2}).format(Number(v||0))},
    dateLabel(v){if(!v)return'—';const p=String(v).slice(0,10).split('-');return p.length===3?new Intl.DateTimeFormat('en-PK',{day:'2-digit',month:'short',year:'numeric'}).format(new Date(Number(p[0]),Number(p[1])-1,Number(p[2]))):v},
    today(){const d=new Date(),o=d.getTimezoneOffset();return new Date(d.getTime()-o*60000).toISOString().slice(0,10)},
    errorText(e,fallback){if(e.response&&e.response.data){const er=e.response.data.errors||{},k=Object.keys(er)[0];if(k&&er[k]&&er[k][0])return er[k][0];if(e.response.data.message)return e.response.data.message}return fallback}
  }
}
</script>

<style scoped>
.hero{border-left:4px solid #165134}
.filter-card,.summary-card,.table-card{border:1px solid rgba(22,81,52,.08);border-radius:15px!important}
</style>
