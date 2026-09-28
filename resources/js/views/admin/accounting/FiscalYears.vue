<template>
  <div>
    <v-card flat class="pa-5 mb-4">
      <div class="d-flex flex-wrap align-center">
        <div>
          <div class="text-overline">ACCOUNTING CONTROL</div>
          <h1 class="text-h5 font-weight-bold">Fiscal Years, Periods & Closing</h1>
          <p class="grey--text mb-0">Manage monthly locks and formal year-end transfer to Retained Earnings.</p>
        </div>
        <v-spacer/>
        <v-btn v-if="$can('accounting.create')" color="primary" :disabled="busy" @click="dialog=true">New fiscal year</v-btn>
      </div>
    </v-card>

    <v-alert v-if="error" type="error" text>{{ error }}</v-alert>
    <v-progress-linear v-if="loading" indeterminate color="primary"/>

    <v-alert v-if="!loading && !years.length" type="info" text>
      Create a fiscal year to generate its 12 monthly periods.
    </v-alert>

    <v-card v-for="year in years" :key="year.id" flat class="mb-4">
      <v-card-title>
        {{ year.name }}
        <v-chip small outlined class="ml-3" :color="year.status==='open'?'success':'grey'">{{ year.status }}</v-chip>
        <v-spacer/>

        <v-btn small text color="#165134" class="mr-2" @click="openHistory(year)">
          <v-icon left small>mdi-history</v-icon>History
        </v-btn>

        <v-btn
          v-if="$can('accounting.edit') && year.status==='open'"
          small
          color="#165134"
          dark
          depressed
          :disabled="busy"
          @click="openClose(year)"
        >
          <v-icon left small>mdi-lock-check-outline</v-icon>Year-End Close
        </v-btn>

        <v-btn
          v-if="$can('accounting.edit') && year.status==='closed'"
          small
          color="warning"
          dark
          depressed
          :disabled="busy"
          @click="openReopen(year)"
        >
          <v-icon left small>mdi-lock-open-variant-outline</v-icon>Reopen Year
        </v-btn>
      </v-card-title>

      <v-card-subtitle>
        {{ dateOnly(year.starts_on) }} — {{ dateOnly(year.ends_on) }}.
        Close earlier periods as work is finalized. Keep the final period open until the formal year-end close.
      </v-card-subtitle>

      <v-simple-table>
        <thead>
          <tr>
            <th>Period</th>
            <th>From</th>
            <th>To</th>
            <th>Status</th>
            <th v-if="$can('accounting.edit')">Action</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="period in year.periods" :key="period.id">
            <td>{{ period.name }}</td>
            <td>{{ dateOnly(period.starts_on) }}</td>
            <td>{{ dateOnly(period.ends_on) }}</td>
            <td>
              <v-chip x-small :color="period.status==='open'?'success':'grey'" outlined>{{ period.status }}</v-chip>
            </td>
            <td v-if="$can('accounting.edit')">
              <v-btn
                small
                text
                :disabled="busy || year.status==='closed'"
                @click="togglePeriod(period)"
              >
                {{ period.status==='open' ? 'Close' : 'Reopen' }}
              </v-btn>
              <v-btn
                v-if="period.status==='open'"
                x-small
                text
                color="#165134"
                @click="showPeriodReadiness(period)"
              >
                Check
              </v-btn>
            </td>
          </tr>
        </tbody>
      </v-simple-table>
    </v-card>

    <v-dialog v-model="dialog" max-width="520" persistent>
      <v-card>
        <v-card-title>Create fiscal year</v-card-title>
        <v-card-text>
          <v-form @submit.prevent="create">
            <v-alert v-if="error" type="error" text dense>{{ error }}</v-alert>
            <v-text-field v-model="form.name" label="Name" outlined dense/>
            <v-text-field v-model="form.starts_on" label="Starts on" type="date" outlined dense/>
            <v-text-field v-model="form.ends_on" label="Ends on" type="date" outlined dense/>
            <div class="caption grey--text">Use 12 complete months, starting on the first day of a month.</div>
            <div class="text-right mt-4">
              <v-btn text :disabled="busy" @click="dialog=false">Cancel</v-btn>
              <v-btn type="submit" color="primary" :loading="busy" :disabled="busy">Create</v-btn>
            </div>
          </v-form>
        </v-card-text>
      </v-card>
    </v-dialog>

    <v-dialog v-model="closeDialog" max-width="760" persistent scrollable>
      <v-card>
        <v-card-title>
          Year-End Close
          <v-spacer/>
          <v-btn icon @click="closeDialog=false"><v-icon>mdi-close</v-icon></v-btn>
        </v-card-title>
        <v-divider/>
        <v-card-text class="pt-4">
          <v-progress-linear v-if="closeLoading" indeterminate color="#165134" class="mb-4"/>
          <template v-if="readiness">
            <v-alert :type="readiness.ready?'success':'warning'" text dense>
              {{ readiness.ready
                ? 'This fiscal year is ready for formal closing.'
                : 'Resolve the blocking items before closing.' }}
            </v-alert>

            <v-card flat outlined class="pa-3 mb-4">
              <div class="d-flex justify-space-between">
                <span>Fiscal Year</span><strong>{{ readiness.fiscal_year.name }}</strong>
              </div>
              <div class="d-flex justify-space-between mt-2">
                <span>Final Period</span><strong>{{ readiness.final_period ? readiness.final_period.name : '—' }}</strong>
              </div>
              <div class="d-flex justify-space-between mt-2">
                <span>Net Result to Retained Earnings</span>
                <strong :class="Number(readiness.summary.net_result||0)>=0?'success--text':'error--text'">
                  PKR {{ money(readiness.summary.net_result) }}
                </strong>
              </div>
              <div class="d-flex justify-space-between mt-2">
                <span>Trial Balance Difference</span>
                <strong>PKR {{ money(readiness.summary.trial_balance_difference) }}</strong>
              </div>
            </v-card>

            <div class="subtitle-2 font-weight-bold mb-2">Required Checks</div>
            <v-list dense class="mb-3">
              <v-list-item v-for="(check,index) in readiness.checks || []" :key="index">
                <v-list-item-icon>
                  <v-icon :color="check.passed?'success':'error'">
                    {{ check.passed ? 'mdi-check-circle-outline' : 'mdi-alert-circle-outline' }}
                  </v-icon>
                </v-list-item-icon>
                <v-list-item-content>
                  <v-list-item-title>{{ check.name }}</v-list-item-title>
                  <v-list-item-subtitle>{{ check.message }}</v-list-item-subtitle>
                </v-list-item-content>
              </v-list-item>
            </v-list>

            <v-alert v-for="(blocker,index) in readiness.blockers || []" :key="'b'+index" type="error" text dense>
              {{ blocker }}
            </v-alert>

            <v-alert v-for="(warning,index) in readiness.warnings || []" :key="'w'+index" type="warning" text dense>
              {{ warning }}
            </v-alert>

            <v-alert type="info" text dense>
              Closing creates a posted year-end journal that zeros Revenue, Cost of Sales and Expense accounts into account 3200 Retained Earnings, then locks the final period and fiscal year.
            </v-alert>
          </template>
        </v-card-text>
        <v-divider/>
        <v-card-actions>
          <v-spacer/>
          <v-btn text @click="closeDialog=false">Cancel</v-btn>
          <v-btn
            color="#165134"
            dark
            depressed
            :loading="busy"
            :disabled="!readiness || !readiness.ready || busy"
            @click="closeYear"
          >
            Close Fiscal Year
          </v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>

    <v-dialog v-model="reopenDialog" max-width="600" persistent>
      <v-card>
        <v-card-title>Reopen Fiscal Year</v-card-title>
        <v-card-text>
          <v-alert type="warning" text dense>
            Reopening reverses the prior year-end closing journal on the fiscal year-end date and reopens the final accounting period.
          </v-alert>
          <v-textarea v-model="reopenReason" outlined dense rows="3" label="Reason *"/>
        </v-card-text>
        <v-card-actions>
          <v-spacer/>
          <v-btn text @click="reopenDialog=false">Cancel</v-btn>
          <v-btn color="warning" dark depressed :loading="busy" @click="reopenYear">Reopen & Reverse Close</v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>

    <v-dialog v-model="historyDialog" max-width="900" scrollable>
      <v-card>
        <v-card-title>
          Closing History
          <v-spacer/><v-btn icon @click="historyDialog=false"><v-icon>mdi-close</v-icon></v-btn>
        </v-card-title>
        <v-divider/>
        <v-card-text class="pt-4">
          <v-data-table :headers="historyHeaders" :items="history" :loading="historyLoading" :items-per-page="20">
            <template v-slot:item.net_result="{item}">PKR {{ money(item.net_result) }}</template>
            <template v-slot:item.closed_at="{item}">{{ dateTime(item.closed_at) }}</template>
            <template v-slot:item.reopened_at="{item}">{{ dateTime(item.reopened_at) }}</template>
            <template v-slot:item.status="{item}">
              <v-chip x-small :color="item.status==='closed'?'success':'warning'" dark>{{ item.status }}</v-chip>
            </template>
            <template v-slot:item.close_journal="{item}">{{ item.closing_journal ? item.closing_journal.entry_number : 'No P&L balance' }}</template>
            <template v-slot:item.reversal_journal="{item}">{{ item.reversal_journal ? item.reversal_journal.entry_number : '—' }}</template>
          </v-data-table>
        </v-card-text>
      </v-card>
    </v-dialog>

    <v-dialog v-model="periodReadinessDialog" max-width="650">
      <v-card>
        <v-card-title>Period Close Check</v-card-title>
        <v-card-text v-if="periodReadiness">
          <v-alert :type="periodReadiness.ready?'success':'warning'" text dense>
            {{ periodReadiness.ready ? 'No blocking items found.' : 'This period has blocking items.' }}
          </v-alert>
          <v-alert v-for="(b,index) in periodReadiness.blockers || []" :key="'pb'+index" type="error" text dense>{{ b }}</v-alert>
          <v-alert v-for="(w,index) in periodReadiness.warnings || []" :key="'pw'+index" type="warning" text dense>{{ w }}</v-alert>
        </v-card-text>
        <v-card-actions><v-spacer/><v-btn text @click="periodReadinessDialog=false">Close</v-btn></v-card-actions>
      </v-card>
    </v-dialog>
  </div>
</template>

<script>
import api from '../../../services/api'

export default {
  name:'FiscalYears',
  data(){
    const now=new Date()
    const year=now.getMonth()>=6?now.getFullYear():now.getFullYear()-1
    return{
      years:[],loading:false,busy:false,error:'',dialog:false,
      closeDialog:false,closeLoading:false,selectedYear:null,readiness:null,
      reopenDialog:false,reopenReason:'',
      historyDialog:false,historyLoading:false,history:[],
      periodReadinessDialog:false,periodReadiness:null,
      form:{name:`FY ${year}-${year+1}`,starts_on:`${year}-07-01`,ends_on:`${year+1}-06-30`},
      historyHeaders:[
        {text:'Status',value:'status'},
        {text:'Net Result',value:'net_result',align:'right'},
        {text:'Close Journal',value:'close_journal'},
        {text:'Closed By',value:'closed_by.name'},
        {text:'Closed At',value:'closed_at'},
        {text:'Reversal Journal',value:'reversal_journal'},
        {text:'Reopened By',value:'reopened_by.name'},
        {text:'Reopened At',value:'reopened_at'}
      ]
    }
  },
  mounted(){this.load()},
  methods:{
    errorText(e){
      const data=e.response&&e.response.data
      if(data&&data.errors){const key=Object.keys(data.errors)[0];return data.errors[key][0]}
      return data&&data.message||'Unable to update fiscal periods.'
    },
    async load(){
      this.loading=true
      try{this.years=(await api.get('/accounting/fiscal-years')).data.data}
      catch(e){this.error=this.errorText(e)}
      finally{this.loading=false}
    },
    async create(){
      if(this.busy)return
      this.busy=true;this.error=''
      try{await api.post('/accounting/fiscal-years',this.form);this.dialog=false;await this.load()}
      catch(e){this.error=this.errorText(e)}
      finally{this.busy=false}
    },
    async togglePeriod(period){
      if(this.busy)return
      this.busy=true;this.error=''
      try{
        await api.patch('/accounting/periods/'+period.id+'/status',{status:period.status==='open'?'closed':'open'})
        await this.load()
      }catch(e){this.error=this.errorText(e)}
      finally{this.busy=false}
    },
    async showPeriodReadiness(period){
      try{
        const r=await api.get('/accounting/periods/'+period.id+'/close-readiness',{skipGlobalLoader:true})
        this.periodReadiness=r.data
        this.periodReadinessDialog=true
      }catch(e){this.error=this.errorText(e)}
    },
    async openClose(year){
      this.selectedYear=year
      this.readiness=null
      this.closeDialog=true
      this.closeLoading=true
      try{
        const r=await api.get('/accounting/fiscal-years/'+year.id+'/close-readiness',{skipGlobalLoader:true})
        this.readiness=r.data
      }catch(e){this.error=this.errorText(e);this.closeDialog=false}
      finally{this.closeLoading=false}
    },
    async closeYear(){
      if(!this.selectedYear||!this.readiness||!this.readiness.ready)return
      this.busy=true;this.error=''
      try{
        await api.post('/accounting/fiscal-years/'+this.selectedYear.id+'/close',{}, {skipGlobalError:true})
        this.closeDialog=false
        await this.load()
      }catch(e){this.error=this.errorText(e)}
      finally{this.busy=false}
    },
    openReopen(year){
      this.selectedYear=year
      this.reopenReason=''
      this.reopenDialog=true
    },
    async reopenYear(){
      if(!this.selectedYear||String(this.reopenReason||'').trim().length<10){
        this.error='Please enter a clear reopening reason of at least 10 characters.'
        return
      }
      this.busy=true;this.error=''
      try{
        await api.post('/accounting/fiscal-years/'+this.selectedYear.id+'/reopen',{reason:this.reopenReason},{skipGlobalError:true})
        this.reopenDialog=false
        await this.load()
      }catch(e){this.error=this.errorText(e)}
      finally{this.busy=false}
    },
    async openHistory(year){
      this.selectedYear=year
      this.history=[]
      this.historyDialog=true
      this.historyLoading=true
      try{
        const r=await api.get('/accounting/fiscal-years/'+year.id+'/closure-history',{skipGlobalLoader:true})
        this.history=r.data.data||[]
      }catch(e){this.error=this.errorText(e)}
      finally{this.historyLoading=false}
    },
    money(v){return new Intl.NumberFormat('en-PK',{minimumFractionDigits:2,maximumFractionDigits:2}).format(Number(v||0))},
    dateOnly(v){return v?String(v).slice(0,10):'—'},
    dateTime(v){if(!v)return'—';return new Date(v).toLocaleString()}
  }
}
</script>
