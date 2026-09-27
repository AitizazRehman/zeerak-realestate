<template>
  <div class="bank-transactions-page">
    <v-card flat class="hero pa-5 mb-4">
      <div class="d-flex align-center flex-wrap">
        <div>
          <div class="text-overline">BANKING OPERATIONS</div>
          <h1 class="text-h5 font-weight-bold mb-1">Bank Transactions</h1>
          <div class="grey--text">Review deposits and withdrawals, link dimensions, and prepare transactions for reconciliation.</div>
        </div>
        <v-spacer/>
        <v-btn
          v-if="$can('accounting.create')"
          text
          color="#165134"
          class="mt-3 mt-md-0 mr-2"
          to="/admin/accounting/bank-statement-imports"
        >
          <v-icon left>mdi-file-upload-outline</v-icon>
          Import Statement
        </v-btn>
        <v-btn
          v-if="$can('accounting.create')"
          color="#165134"
          dark
          depressed
          class="rounded-lg mt-3 mt-md-0"
          @click="openCreate"
        >
          <v-icon left>mdi-bank-transfer</v-icon>
          Add Transaction
        </v-btn>
      </div>
    </v-card>

    <v-row class="mb-1">
      <v-col cols="6" md="3">
        <v-card flat class="summary-card pa-4">
          <div class="caption grey--text">Deposits</div>
          <div class="text-h6 font-weight-bold success--text">PKR {{ money(summary.deposits) }}</div>
        </v-card>
      </v-col>
      <v-col cols="6" md="3">
        <v-card flat class="summary-card pa-4">
          <div class="caption grey--text">Withdrawals</div>
          <div class="text-h6 font-weight-bold error--text">PKR {{ money(summary.withdrawals) }}</div>
        </v-card>
      </v-col>
      <v-col cols="6" md="3">
        <v-card flat class="summary-card pa-4">
          <div class="caption grey--text">Net Movement</div>
          <div class="text-h6 font-weight-bold">PKR {{ money(summary.net_movement) }}</div>
        </v-card>
      </v-col>
      <v-col cols="6" md="3">
        <v-card flat class="summary-card pa-4">
          <div class="caption grey--text">Reconciliation</div>
          <div class="d-flex align-center mt-1">
            <v-chip x-small color="warning" dark class="mr-1">{{ summary.unmatched || 0 }} unmatched</v-chip>
            <v-chip x-small color="success" dark>{{ summary.reconciled || 0 }} reconciled</v-chip>
          </div>
        </v-card>
      </v-col>
    </v-row>

    <v-card flat class="filter-card pa-4 mb-4">
      <v-row dense>
        <v-col cols="12" md="3">
          <v-text-field
            v-model="filters.search"
            outlined dense clearable hide-details
            prepend-inner-icon="mdi-magnify"
            label="Reference, cheque, customer..."
            @keyup.enter="applyFilters"
            @click:clear="applyFilters"
          />
        </v-col>
        <v-col v-if="canAccessAllBranches" cols="12" md="2">
          <v-select
            v-model="filters.branch_id"
            :items="branches"
            item-text="name"
            item-value="id"
            outlined dense clearable hide-details
            label="Branch"
            @change="branchChanged"
          />
        </v-col>
        <v-col cols="12" md="3">
          <v-select
            v-model="filters.bank_account_id"
            :items="bankAccounts"
            item-text="display"
            item-value="id"
            outlined dense clearable hide-details
            label="Bank / Cash Account"
            @change="applyFilters"
          />
        </v-col>
        <v-col cols="6" md="2">
          <v-text-field v-model="filters.from" type="date" outlined dense hide-details label="From" @change="applyFilters"/>
        </v-col>
        <v-col cols="6" md="2">
          <v-text-field v-model="filters.to" type="date" outlined dense hide-details label="To" @change="applyFilters"/>
        </v-col>
        <v-col cols="12" md="3">
          <v-autocomplete
            v-model="filters.project_id"
            :items="projects"
            item-text="display"
            item-value="id"
            outlined dense clearable hide-details
            label="Project"
            @change="applyFilters"
          />
        </v-col>
        <v-col cols="12" md="3">
          <v-autocomplete
            v-model="filters.customer_id"
            :items="customers"
            item-text="display"
            item-value="id"
            outlined dense clearable hide-details
            label="Customer"
            @change="applyFilters"
          />
        </v-col>
        <v-col cols="6" md="2">
          <v-select
            v-model="filters.transaction_type"
            :items="transactionTypes"
            item-text="text"
            item-value="value"
            outlined dense clearable hide-details
            label="Type"
            @change="applyFilters"
          />
        </v-col>
        <v-col cols="6" md="2">
          <v-select
            v-model="filters.reconciliation_status"
            :items="reconciliationStatuses"
            item-text="text"
            item-value="value"
            outlined dense clearable hide-details
            label="Reconciliation"
            @change="applyFilters"
          />
        </v-col>
        <v-col cols="12" md="2" class="d-flex align-center justify-end">
          <v-btn text color="#165134" @click="resetFilters">Reset</v-btn>
          <v-btn icon color="#165134" title="Refresh" @click="load(1)">
            <v-icon>mdi-refresh</v-icon>
          </v-btn>
        </v-col>
      </v-row>
    </v-card>

    <v-card flat class="table-card">
      <v-data-table
        :headers="headers"
        :items="transactions"
        :loading="loading"
        :items-per-page="pagination.per_page"
        hide-default-footer
        mobile-breakpoint="900"
      >
        <template v-slot:item.transaction_date="{item}">
          <div class="font-weight-medium">{{ dateLabel(item.transaction_date) }}</div>
          <div v-if="item.value_date && item.value_date !== item.transaction_date" class="caption grey--text">
            Value {{ dateLabel(item.value_date) }}
          </div>
        </template>

        <template v-slot:item.bank_account="{item}">
          <div v-if="item.bank_account">
            <div class="font-weight-medium">{{ item.bank_account.name }}</div>
            <div class="caption grey--text">
              {{ item.bank_account.bank_name || (item.bank_account.account_type === 'cash' ? 'Cash' : 'Bank') }}
            </div>
          </div>
          <span v-else>—</span>
        </template>

        <template v-slot:item.reference="{item}">
          <div>{{ item.reference_number || item.cheque_number || '—' }}</div>
          <div v-if="item.external_transaction_id" class="caption grey--text text-truncate ref-width">
            {{ item.external_transaction_id }}
          </div>
        </template>

        <template v-slot:item.party="{item}">
          <div v-if="item.customer" class="font-weight-medium">{{ item.customer.name }}</div>
          <div v-if="item.project" class="caption grey--text">{{ item.project.name }}</div>
          <span v-if="!item.customer && !item.project">—</span>
        </template>

        <template v-slot:item.type="{item}">
          <v-chip x-small :color="item.transaction_type === 'deposit' ? 'success' : 'error'" dark>
            <v-icon x-small left>{{ item.transaction_type === 'deposit' ? 'mdi-arrow-down-left' : 'mdi-arrow-up-right' }}</v-icon>
            {{ item.transaction_type === 'deposit' ? 'Deposit' : 'Withdrawal' }}
          </v-chip>
        </template>

        <template v-slot:item.amount="{item}">
          <span :class="item.transaction_type === 'deposit' ? 'success--text font-weight-bold' : 'error--text font-weight-bold'">
            {{ item.transaction_type === 'deposit' ? '+' : '-' }} PKR {{ money(item.amount) }}
          </span>
        </template>

        <template v-slot:item.journal="{item}">
          <v-chip v-if="item.journal_entry" x-small outlined color="primary">
            {{ item.journal_entry.entry_number }}
          </v-chip>
          <span v-else class="caption grey--text">Not linked</span>
        </template>

        <template v-slot:item.reconciliation_status="{item}">
          <v-chip x-small :color="statusColor(item.reconciliation_status)" dark>
            {{ statusLabel(item.reconciliation_status) }}
          </v-chip>
        </template>

        <template v-slot:item.actions="{item}">
          <v-btn
            v-if="$can('accounting.edit') && item.source === 'manual' && item.reconciliation_status !== 'reconciled'"
            icon small title="Edit transaction"
            @click="edit(item)"
          >
            <v-icon small>mdi-pencil-outline</v-icon>
          </v-btn>
          <v-icon v-else-if="item.reconciliation_status === 'reconciled'" small color="success" title="Locked after reconciliation">
            mdi-lock-check-outline
          </v-icon>
        </template>

        <template v-slot:no-data>
          <div class="pa-10 text-center grey--text">
            <v-icon size="48" color="grey lighten-1">mdi-bank-transfer</v-icon>
            <div class="mt-2">No bank transactions found for these filters.</div>
          </div>
        </template>
      </v-data-table>

      <v-divider v-if="pagination.last_page > 1"/>
      <div v-if="pagination.last_page > 1" class="d-flex align-center justify-space-between flex-wrap pa-4">
        <div class="caption grey--text">
          Showing {{ pagination.from || 0 }}–{{ pagination.to || 0 }} of {{ pagination.total || 0 }}
        </div>
        <v-pagination
          v-model="pagination.current_page"
          :length="pagination.last_page"
          :total-visible="7"
          color="#165134"
          @input="load"
        />
      </div>
    </v-card>

    <v-dialog v-model="dialog" max-width="900" persistent>
      <v-card>
        <v-card-title>
          <div>
            <div class="text-h6 font-weight-bold">{{ editing ? 'Edit Bank Transaction' : 'Add Bank Transaction' }}</div>
            <div class="caption grey--text">Manual transaction record for bank operations and reconciliation.</div>
          </div>
          <v-spacer/>
          <v-btn icon @click="closeDialog"><v-icon>mdi-close</v-icon></v-btn>
        </v-card-title>

        <v-divider/>

        <v-card-text class="pt-5">
          <v-alert type="info" text dense class="mb-5">
            A manual bank transaction does not create a journal entry automatically. Link a posted journal entry when the matching accounting posting already exists.
          </v-alert>

          <v-row>
            <v-col cols="12" md="6">
              <v-autocomplete
                v-model="form.bank_account_id"
                :items="bankAccounts"
                item-text="display"
                item-value="id"
                outlined dense
                label="Bank / Cash Account *"
                :error-messages="errors.bank_account_id"
              />
            </v-col>
            <v-col cols="12" md="3">
              <v-text-field
                v-model="form.transaction_date"
                type="date"
                outlined dense
                label="Transaction Date *"
                :error-messages="errors.transaction_date"
              />
            </v-col>
            <v-col cols="12" md="3">
              <v-text-field
                v-model="form.value_date"
                type="date"
                outlined dense
                label="Value Date"
                :error-messages="errors.value_date"
              />
            </v-col>

            <v-col cols="12" md="4">
              <v-select
                v-model="form.transaction_type"
                :items="transactionTypes"
                item-text="text"
                item-value="value"
                outlined dense
                label="Transaction Type *"
              />
            </v-col>
            <v-col cols="12" md="4">
              <v-text-field
                v-model="form.amount"
                type="number"
                min="0.01"
                step="0.01"
                outlined dense
                prefix="PKR"
                label="Amount *"
                :error-messages="amountErrors"
              />
            </v-col>
            <v-col cols="12" md="4">
              <v-text-field
                v-model="form.running_balance"
                type="number"
                step="0.01"
                outlined dense
                prefix="PKR"
                label="Statement Balance"
                hint="Optional running balance from bank statement"
                persistent-hint
                :error-messages="errors.running_balance"
              />
            </v-col>

            <v-col cols="12" md="4">
              <v-text-field
                v-model.trim="form.reference_number"
                outlined dense
                label="Reference Number"
                :error-messages="errors.reference_number"
              />
            </v-col>
            <v-col cols="12" md="4">
              <v-text-field
                v-model.trim="form.cheque_number"
                outlined dense
                label="Cheque Number"
                :error-messages="errors.cheque_number"
              />
            </v-col>
            <v-col cols="12" md="4">
              <v-text-field
                v-model.trim="form.external_transaction_id"
                outlined dense
                label="Bank Transaction ID"
                :error-messages="errors.external_transaction_id"
              />
            </v-col>

            <v-col cols="12" md="6">
              <v-autocomplete
                v-model="form.project_id"
                :items="projects"
                item-text="display"
                item-value="id"
                outlined dense clearable
                label="Project"
                :error-messages="errors.project_id"
              />
            </v-col>
            <v-col cols="12" md="6">
              <v-autocomplete
                v-model="form.customer_id"
                :items="customers"
                item-text="display"
                item-value="id"
                outlined dense clearable
                label="Customer"
                :error-messages="errors.customer_id"
              />
            </v-col>

            <v-col cols="12" md="4">
              <v-text-field
                v-model="form.journal_entry_id"
                type="number"
                min="1"
                outlined dense
                label="Posted Journal Entry ID"
                hint="Optional: must match this account movement"
                persistent-hint
                :error-messages="errors.journal_entry_id"
              />
            </v-col>
            <v-col cols="12" md="8">
              <v-text-field
                v-model.trim="form.description"
                outlined dense
                label="Description"
                :error-messages="errors.description"
              />
            </v-col>
            <v-col cols="12">
              <v-textarea
                v-model="form.notes"
                outlined dense rows="2"
                label="Internal Notes"
                :error-messages="errors.notes"
              />
            </v-col>
          </v-row>
        </v-card-text>

        <v-card-actions>
          <v-spacer/>
          <v-btn text @click="closeDialog">Cancel</v-btn>
          <v-btn color="#165134" dark depressed :loading="saving" @click="save">
            {{ editing ? 'Update Transaction' : 'Create Transaction' }}
          </v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>
  </div>
</template>

<script>
import api from '../../../services/api'

export default {
  name:'BankTransactions',

  data(){
    return{
      loading:false,
      saving:false,
      dialog:false,
      editing:null,
      errors:{},
      transactions:[],
      branches:[],
      bankAccounts:[],
      projects:[],
      customers:[],
      summary:{deposits:'0.00',withdrawals:'0.00',net_movement:'0.00',unmatched:0,matched:0,reconciled:0},
      pagination:{current_page:1,last_page:1,per_page:25,total:0,from:0,to:0},
      filters:{
        search:'',
        branch_id:null,
        bank_account_id:null,
        project_id:null,
        customer_id:null,
        transaction_type:null,
        reconciliation_status:null,
        from:'',
        to:''
      },
      form:this.blank(),
      transactionTypes:[
        {text:'Deposit',value:'deposit'},
        {text:'Withdrawal',value:'withdrawal'}
      ],
      reconciliationStatuses:[
        {text:'Unmatched',value:'unmatched'},
        {text:'Matched',value:'matched'},
        {text:'Reconciled',value:'reconciled'}
      ],
      headers:[
        {text:'Date',value:'transaction_date'},
        {text:'Account',value:'bank_account'},
        {text:'Reference',value:'reference'},
        {text:'Customer / Project',value:'party'},
        {text:'Type',value:'type',sortable:false},
        {text:'Amount',value:'amount',align:'right'},
        {text:'Journal',value:'journal',sortable:false},
        {text:'Status',value:'reconciliation_status'},
        {text:'',value:'actions',sortable:false,align:'right'}
      ]
    }
  },

  computed:{
    canAccessAllBranches(){
      const roles=this.$store.getters['auth/roles'] || []
      return roles.some(function(role){
        const name=typeof role === 'string' ? role : role.name
        return name === 'Super Admin' || name === 'Admin'
      })
    },

    amountErrors(){
      const messages=[]
      if(this.errors.amount)messages.push.apply(messages,this.errors.amount)
      if(this.errors.debit)messages.push.apply(messages,this.errors.debit)
      if(this.errors.credit)messages.push.apply(messages,this.errors.credit)
      return messages
    }
  },

  async mounted(){
    await this.loadOptions()
    await this.load(1)
  },

  methods:{
    blank(){
      return{
        bank_account_id:null,
        transaction_date:this.today(),
        value_date:'',
        transaction_type:'deposit',
        amount:'',
        running_balance:'',
        reference_number:'',
        cheque_number:'',
        external_transaction_id:'',
        project_id:null,
        customer_id:null,
        journal_entry_id:null,
        description:'',
        notes:''
      }
    },

    today(){
      const d=new Date()
      const offset=d.getTimezoneOffset()
      return new Date(d.getTime()-offset*60000).toISOString().slice(0,10)
    },

    async loadOptions(){
      const response=await api.get('/accounting/bank-transactions/options',{
        params:{branch_id:this.filters.branch_id || undefined},
        skipGlobalLoader:true
      })

      this.branches=response.data.branches || this.branches
      this.bankAccounts=(response.data.bank_accounts || []).map(function(item){
        const bank=item.bank_name ? item.bank_name+' · ' : ''
        const number=item.account_number ? ' · '+item.account_number : ''
        return Object.assign({},item,{display:bank+item.name+number})
      })
      this.projects=(response.data.projects || []).map(function(item){
        return Object.assign({},item,{display:(item.code ? item.code+' · ' : '')+item.name})
      })
      this.customers=(response.data.customers || []).map(function(item){
        return Object.assign({},item,{display:(item.customer_number ? item.customer_number+' · ' : '')+item.name})
      })
    },

    async branchChanged(){
      this.filters.bank_account_id=null
      this.filters.project_id=null
      this.filters.customer_id=null
      await this.loadOptions()
      await this.load(1)
    },

    async load(page){
      this.loading=true
      try{
        const targetPage=typeof page === 'number' ? page : this.pagination.current_page
        const response=await api.get('/accounting/bank-transactions',{
          params:{
            page:targetPage,
            per_page:this.pagination.per_page,
            search:this.filters.search || undefined,
            branch_id:this.filters.branch_id || undefined,
            bank_account_id:this.filters.bank_account_id || undefined,
            project_id:this.filters.project_id || undefined,
            customer_id:this.filters.customer_id || undefined,
            transaction_type:this.filters.transaction_type || undefined,
            reconciliation_status:this.filters.reconciliation_status || undefined,
            from:this.filters.from || undefined,
            to:this.filters.to || undefined
          },
          skipGlobalLoader:true
        })

        const pager=response.data.data || {}
        this.transactions=pager.data || []
        this.pagination={
          current_page:Number(pager.current_page || 1),
          last_page:Number(pager.last_page || 1),
          per_page:Number(pager.per_page || 25),
          total:Number(pager.total || 0),
          from:Number(pager.from || 0),
          to:Number(pager.to || 0)
        }
        this.summary=response.data.summary || this.summary
      }finally{
        this.loading=false
      }
    },

    applyFilters(){
      this.load(1)
    },

    async resetFilters(){
      this.filters={
        search:'',
        branch_id:null,
        bank_account_id:null,
        project_id:null,
        customer_id:null,
        transaction_type:null,
        reconciliation_status:null,
        from:'',
        to:''
      }
      await this.loadOptions()
      await this.load(1)
    },

    openCreate(){
      this.editing=null
      this.errors={}
      this.form=this.blank()
      this.dialog=true
    },

    edit(item){
      this.editing=item
      this.errors={}
      this.form=Object.assign(this.blank(),{
        bank_account_id:item.bank_account_id,
        transaction_date:this.rawDate(item.transaction_date),
        value_date:this.rawDate(item.value_date),
        transaction_type:item.transaction_type,
        amount:item.amount,
        running_balance:item.running_balance === null ? '' : item.running_balance,
        reference_number:item.reference_number || '',
        cheque_number:item.cheque_number || '',
        external_transaction_id:item.external_transaction_id || '',
        project_id:item.project_id,
        customer_id:item.customer_id,
        journal_entry_id:item.journal_entry_id,
        description:item.description || '',
        notes:item.notes || ''
      })
      this.dialog=true
    },

    closeDialog(){
      this.dialog=false
      this.editing=null
      this.errors={}
      this.saving=false
    },

    payload(){
      const amount=Number(this.form.amount || 0)
      return{
        bank_account_id:this.form.bank_account_id,
        transaction_date:this.form.transaction_date,
        value_date:this.form.value_date || null,
        debit:this.form.transaction_type === 'deposit' ? amount : 0,
        credit:this.form.transaction_type === 'withdrawal' ? amount : 0,
        running_balance:this.form.running_balance === '' ? null : this.form.running_balance,
        reference_number:this.form.reference_number || null,
        cheque_number:this.form.cheque_number || null,
        external_transaction_id:this.form.external_transaction_id || null,
        project_id:this.form.project_id || null,
        customer_id:this.form.customer_id || null,
        journal_entry_id:this.form.journal_entry_id || null,
        description:this.form.description || null,
        notes:this.form.notes || null
      }
    },

    async save(){
      this.errors={}

      if(!this.form.amount || Number(this.form.amount) <= 0){
        this.errors={amount:['Enter an amount greater than zero.']}
        return
      }

      this.saving=true

      try{
        if(this.editing){
          await api.put('/accounting/bank-transactions/'+this.editing.id,this.payload(),{skipGlobalError:true})
        }else{
          await api.post('/accounting/bank-transactions',this.payload(),{skipGlobalError:true})
        }

        this.closeDialog()
        await this.load(this.pagination.current_page)
      }catch(error){
        if(error.response && error.response.status === 422){
          this.errors=error.response.data.errors || {}
          if(!Object.keys(this.errors).length && error.response.data.message){
            this.$root.$emit('show-error',error.response.data.message)
          }
        }else{
          this.$root.$emit(
            'show-error',
            (error.response && error.response.data && error.response.data.message) || 'Unable to save bank transaction.'
          )
        }
      }finally{
        this.saving=false
      }
    },

    money(value){
      return new Intl.NumberFormat('en-PK',{
        minimumFractionDigits:2,
        maximumFractionDigits:2
      }).format(Number(value || 0))
    },

    rawDate(value){
      if(!value)return ''
      return String(value).slice(0,10)
    },

    dateLabel(value){
      if(!value)return '—'
      const raw=this.rawDate(value)
      const parts=raw.split('-')
      if(parts.length !== 3)return raw
      return new Intl.DateTimeFormat('en-PK',{day:'2-digit',month:'short',year:'numeric'})
        .format(new Date(Number(parts[0]),Number(parts[1])-1,Number(parts[2])))
    },

    statusColor(status){
      if(status === 'reconciled')return 'success'
      if(status === 'matched')return 'info'
      return 'warning'
    },

    statusLabel(status){
      if(!status)return 'Unmatched'
      return status.charAt(0).toUpperCase()+status.slice(1)
    }
  }
}
</script>

<style scoped>
.bank-transactions-page{width:100%}
.hero{border-left:4px solid #165134}
.summary-card,.filter-card,.table-card{border:1px solid rgba(22,81,52,.08);border-radius:15px!important}
.ref-width{max-width:180px}
.bank-transactions-page ::v-deep .v-data-table__wrapper{overflow-x:auto}
</style>
