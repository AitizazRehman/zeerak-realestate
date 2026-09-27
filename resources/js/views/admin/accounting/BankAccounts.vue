<template>
  <div class="bank-accounts-page">
    <v-card flat class="hero pa-5 mb-4">
      <div class="d-flex align-center flex-wrap">
        <div>
          <div class="text-overline">BANKING FOUNDATION</div>
          <h1 class="text-h5 font-weight-bold mb-1">Bank & Cash Accounts</h1>
          <div class="grey--text">Map physical bank and cash accounts to posting accounts in the General Ledger.</div>
        </div>
        <v-spacer/>
        <v-btn
          v-if="$can('accounting.create')"
          color="#165134"
          dark
          depressed
          class="rounded-lg mt-3 mt-md-0"
          @click="openCreate"
        >
          <v-icon left>mdi-bank-plus</v-icon>
          Add Account
        </v-btn>
      </div>
    </v-card>

    <v-row class="mb-1">
      <v-col cols="6" md="3">
        <v-card flat class="summary-card pa-4">
          <div class="caption grey--text">Total Accounts</div>
          <div class="text-h6 font-weight-bold">{{summary.total || 0}}</div>
        </v-card>
      </v-col>
      <v-col cols="6" md="3">
        <v-card flat class="summary-card pa-4">
          <div class="caption grey--text">Bank Accounts</div>
          <div class="text-h6 font-weight-bold primary--text">{{summary.bank || 0}}</div>
        </v-card>
      </v-col>
      <v-col cols="6" md="3">
        <v-card flat class="summary-card pa-4">
          <div class="caption grey--text">Cash Accounts</div>
          <div class="text-h6 font-weight-bold">{{summary.cash || 0}}</div>
        </v-card>
      </v-col>
      <v-col cols="6" md="3">
        <v-card flat class="summary-card pa-4">
          <div class="caption grey--text">GL Balance</div>
          <div class="text-h6 font-weight-bold">PKR {{money(summary.current_balance)}}</div>
        </v-card>
      </v-col>
    </v-row>

    <v-card flat class="filter-card pa-4 mb-4">
      <v-row dense align="center">
        <v-col cols="12" md="5">
          <v-text-field
            v-model="search"
            outlined
            dense
            hide-details
            clearable
            prepend-inner-icon="mdi-magnify"
            label="Search bank, account title, number or IBAN"
            @keyup.enter="load"
            @click:clear="load"
          />
        </v-col>
        <v-col cols="12" md="3">
          <v-select
            v-model="typeFilter"
            :items="accountTypes"
            item-text="text"
            item-value="value"
            outlined
            dense
            hide-details
            clearable
            label="Account Type"
            @change="load"
          />
        </v-col>
        <v-col v-if="canAccessAllBranches" cols="12" md="3">
          <v-select
            v-model="branchFilter"
            :items="branches"
            item-text="name"
            item-value="id"
            outlined
            dense
            hide-details
            clearable
            label="Branch"
            @change="load"
          />
        </v-col>
        <v-col cols="12" :md="canAccessAllBranches ? 1 : 4" class="text-md-right">
          <v-btn icon color="#165134" title="Refresh" @click="load">
            <v-icon>mdi-refresh</v-icon>
          </v-btn>
        </v-col>
      </v-row>
    </v-card>

    <v-card flat class="table-card">
      <v-data-table
        :headers="headers"
        :items="accounts"
        :items-per-page="25"
        mobile-breakpoint="760"
      >
        <template v-slot:item.name="{item}">
          <div class="d-flex align-center py-2">
            <v-avatar size="38" class="account-icon mr-3">
              <v-icon small color="#165134">{{item.account_type === 'cash' ? 'mdi-cash' : 'mdi-bank-outline'}}</v-icon>
            </v-avatar>
            <div>
              <div class="font-weight-medium">{{item.name}}</div>
              <div class="caption grey--text">
                {{item.bank_name || (item.account_type === 'cash' ? 'Cash account' : 'Bank')}}
              </div>
            </div>
          </div>
        </template>

        <template v-slot:item.account_number="{item}">
          <div>
            <div>{{maskedAccount(item.account_number)}}</div>
            <div v-if="item.iban" class="caption grey--text">{{maskedIban(item.iban)}}</div>
          </div>
        </template>

        <template v-slot:item.chart_of_account="{item}">
          <div v-if="item.chart_of_account">
            <span class="font-weight-bold primary--text">{{item.chart_of_account.code}}</span>
            <span class="ml-1">{{item.chart_of_account.name}}</span>
          </div>
          <span v-else>—</span>
        </template>

        <template v-slot:item.branch="{item}">
          {{item.branch ? item.branch.name : 'Company-wide'}}
        </template>

        <template v-slot:item.current_balance="{item}">
          <span class="font-weight-bold">{{item.currency || 'PKR'}} {{money(item.current_balance)}}</span>
        </template>

        <template v-slot:item.is_active="{item}">
          <v-chip x-small :color="item.is_active ? 'success' : 'grey'" dark>
            {{item.is_active ? 'Active' : 'Inactive'}}
          </v-chip>
        </template>

        <template v-slot:item.actions="{item}">
          <v-btn
            v-if="$can('accounting.edit')"
            icon
            small
            title="Edit account"
            @click="edit(item)"
          >
            <v-icon small>mdi-pencil-outline</v-icon>
          </v-btn>
          <v-btn
            v-if="$can('accounting.delete')"
            icon
            small
            color="error"
            title="Delete or deactivate account"
            @click="askDelete(item)"
          >
            <v-icon small>mdi-delete-outline</v-icon>
          </v-btn>
        </template>

        <template v-slot:no-data>
          <div class="pa-10 text-center grey--text">
            <v-icon size="48" color="grey lighten-1">mdi-bank-outline</v-icon>
            <div class="mt-2">No bank or cash accounts configured yet.</div>
          </div>
        </template>
      </v-data-table>
    </v-card>

    <v-dialog v-model="dialog" max-width="780" persistent>
      <v-card>
        <v-card-title>
          <div>
            <div class="text-h6 font-weight-bold">{{editing ? 'Edit Account' : 'Add Bank / Cash Account'}}</div>
            <div class="caption grey--text">Each account must map to one posting-level Asset account in the Chart of Accounts.</div>
          </div>
          <v-spacer/>
          <v-btn icon @click="closeDialog"><v-icon>mdi-close</v-icon></v-btn>
        </v-card-title>

        <v-divider/>

        <v-card-text class="pt-5">
          <v-row>
            <v-col cols="12" md="4">
              <v-select
                v-model="form.account_type"
                :items="accountTypes"
                item-text="text"
                item-value="value"
                outlined
                dense
                label="Account Type *"
                :error-messages="errors.account_type"
              />
            </v-col>

            <v-col cols="12" md="8">
              <v-text-field
                v-model.trim="form.name"
                outlined
                dense
                label="Display Name *"
                placeholder="e.g. HBL Main Collection Account"
                :error-messages="errors.name"
              />
            </v-col>

            <v-col v-if="canAccessAllBranches" cols="12" md="6">
              <v-select
                v-model="form.branch_id"
                :items="branches"
                item-text="name"
                item-value="id"
                outlined
                dense
                clearable
                label="Branch"
                hint="Leave blank for a company-wide account"
                persistent-hint
                :error-messages="errors.branch_id"
              />
            </v-col>

            <v-col cols="12" :md="canAccessAllBranches ? 6 : 12">
              <v-autocomplete
                v-model="form.chart_of_account_id"
                :items="ledgerAccounts"
                item-text="display"
                item-value="id"
                outlined
                dense
                label="GL Account *"
                :error-messages="errors.chart_of_account_id"
              />
            </v-col>

            <v-col v-if="form.account_type === 'bank'" cols="12" md="6">
              <v-text-field
                v-model.trim="form.bank_name"
                outlined
                dense
                label="Bank Name *"
                placeholder="e.g. HBL"
                :error-messages="errors.bank_name"
              />
            </v-col>

            <v-col cols="12" md="6">
              <v-text-field
                v-model.trim="form.account_title"
                outlined
                dense
                label="Account Title"
                :error-messages="errors.account_title"
              />
            </v-col>

            <v-col v-if="form.account_type === 'bank'" cols="12" md="6">
              <v-text-field
                v-model.trim="form.account_number"
                outlined
                dense
                label="Account Number"
                :error-messages="errors.account_number"
              />
            </v-col>

            <v-col v-if="form.account_type === 'bank'" cols="12" md="6">
              <v-text-field
                v-model.trim="form.iban"
                outlined
                dense
                label="IBAN"
                :error-messages="errors.iban"
              />
            </v-col>

            <v-col v-if="form.account_type === 'bank'" cols="12" md="6">
              <v-text-field
                v-model.trim="form.bank_branch"
                outlined
                dense
                label="Bank Branch"
                :error-messages="errors.bank_branch"
              />
            </v-col>

            <v-col cols="12" md="6">
              <v-text-field
                v-model.trim="form.currency"
                outlined
                dense
                maxlength="3"
                label="Currency *"
                :error-messages="errors.currency"
              />
            </v-col>

            <v-col cols="12">
              <v-textarea
                v-model="form.notes"
                outlined
                dense
                rows="2"
                label="Notes"
                :error-messages="errors.notes"
              />
            </v-col>

            <v-col cols="12">
              <v-switch
                v-model="form.is_active"
                color="#165134"
                label="Active"
                hide-details
              />
            </v-col>
          </v-row>
        </v-card-text>

        <v-card-actions>
          <v-spacer/>
          <v-btn text @click="closeDialog">Cancel</v-btn>
          <v-btn color="#165134" dark depressed @click="save">
            {{editing ? 'Update Account' : 'Create Account'}}
          </v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>

    <v-dialog v-model="deleteDialog" max-width="500" persistent>
      <v-card>
        <v-card-title>Remove Bank Account</v-card-title>
        <v-card-text>
          <v-alert type="warning" outlined dense>
            Remove <strong>{{deleteItem ? deleteItem.name : ''}}</strong>?
            If accounting history exists, the account will be deactivated instead of deleted.
          </v-alert>
        </v-card-text>
        <v-card-actions>
          <v-spacer/>
          <v-btn text @click="deleteDialog=false">Cancel</v-btn>
          <v-btn color="error" depressed @click="confirmDelete">Continue</v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>
  </div>
</template>

<script>
import api from '../../../services/api'

export default {
  name:'BankAccounts',

  data(){
    return{
      search:'',
      typeFilter:null,
      branchFilter:null,
      accounts:[],
      branches:[],
      ledgerAccounts:[],
      summary:{total:0,active:0,bank:0,cash:0,current_balance:'0.00'},
      dialog:false,
      editing:null,
      deleteDialog:false,
      deleteItem:null,
      errors:{},
      form:this.blank(),
      accountTypes:[
        {text:'Bank Account',value:'bank'},
        {text:'Cash / Petty Cash',value:'cash'}
      ],
      headers:[
        {text:'Account',value:'name'},
        {text:'Account / IBAN',value:'account_number'},
        {text:'GL Mapping',value:'chart_of_account'},
        {text:'Branch',value:'branch'},
        {text:'Balance',value:'current_balance',align:'right'},
        {text:'Status',value:'is_active'},
        {text:'Actions',value:'actions',sortable:false,align:'right'}
      ]
    }
  },

  computed:{
    currentUser(){
      return this.$store.getters['auth/user'] || null
    },

    canAccessAllBranches(){
      const roles=this.$store.getters['auth/roles'] || []
      return roles.some(function(role){
        const name=typeof role === 'string' ? role : role.name
        return name === 'Super Admin' || name === 'Admin'
      })
    }
  },

  mounted(){
    this.loadOptions()
    this.load()
  },

  methods:{
    blank(){
      return{
        branch_id:null,
        chart_of_account_id:null,
        account_type:'bank',
        name:'',
        bank_name:'',
        account_title:'',
        account_number:'',
        iban:'',
        bank_branch:'',
        currency:'PKR',
        is_active:true,
        notes:''
      }
    },

    async loadOptions(){
      const response=await api.get('/accounting/bank-accounts/options',{skipGlobalLoader:true})
      this.branches=response.data.branches || []
      this.ledgerAccounts=(response.data.ledger_accounts || []).map(function(account){
        return Object.assign({},account,{display:account.code+' · '+account.name})
      })
    },

    async load(){
      const response=await api.get('/accounting/bank-accounts',{
        params:{
          search:this.search || undefined,
          account_type:this.typeFilter || undefined,
          branch_id:this.branchFilter || undefined
        }
      })
      this.accounts=response.data.data || []
      this.summary=response.data.summary || this.summary
    },

    openCreate(){
      this.editing=null
      this.errors={}
      this.form=this.blank()

      if(!this.canAccessAllBranches && this.currentUser){
        this.form.branch_id=this.currentUser.branch_id || null
      }

      this.dialog=true
    },

    edit(item){
      this.editing=item
      this.errors={}

      if(item.chart_of_account && !this.ledgerAccounts.some(function(account){
        return Number(account.id) === Number(item.chart_of_account.id)
      })){
        this.ledgerAccounts.push({
          id:item.chart_of_account.id,
          code:item.chart_of_account.code,
          name:item.chart_of_account.name,
          display:item.chart_of_account.code+' · '+item.chart_of_account.name
        })
      }

      this.form=Object.assign(this.blank(),{
        branch_id:item.branch_id,
        chart_of_account_id:item.chart_of_account_id,
        account_type:item.account_type,
        name:item.name,
        bank_name:item.bank_name || '',
        account_title:item.account_title || '',
        account_number:item.account_number || '',
        iban:item.iban || '',
        bank_branch:item.bank_branch || '',
        currency:item.currency || 'PKR',
        is_active:!!item.is_active,
        notes:item.notes || ''
      })

      this.dialog=true
    },

    closeDialog(){
      this.dialog=false
      this.editing=null
      this.errors={}
    },

    async save(){
      this.errors={}

      try{
        if(this.editing){
          await api.put('/accounting/bank-accounts/'+this.editing.id,this.form,{skipGlobalError:true})
        }else{
          await api.post('/accounting/bank-accounts',this.form,{skipGlobalError:true})
        }

        this.closeDialog()
        await this.loadOptions()
        await this.load()
      }catch(error){
        if(error.response && error.response.status === 422){
          this.errors=error.response.data.errors || {}
        }else{
          this.$root.$emit(
            'show-error',
            (error.response && error.response.data && error.response.data.message) || 'Unable to save bank account.'
          )
        }
      }
    },

    askDelete(item){
      this.deleteItem=item
      this.deleteDialog=true
    },

    async confirmDelete(){
      if(!this.deleteItem)return

      try{
        await api.delete('/accounting/bank-accounts/'+this.deleteItem.id)
        this.deleteDialog=false
        this.deleteItem=null
        await this.loadOptions()
        await this.load()
      }catch(error){
        this.$root.$emit(
          'show-error',
          (error.response && error.response.data && error.response.data.message) || 'Unable to remove bank account.'
        )
      }
    },

    money(value){
      return new Intl.NumberFormat('en-PK',{
        minimumFractionDigits:2,
        maximumFractionDigits:2
      }).format(Number(value || 0))
    },

    maskedAccount(value){
      const text=String(value || '')
      if(!text)return '—'
      if(text.length <= 4)return text
      return '•••• '+text.slice(-4)
    },

    maskedIban(value){
      const text=String(value || '').replace(/\s+/g,'')
      if(!text)return ''
      if(text.length <= 8)return text
      return text.slice(0,4)+' •••• •••• '+text.slice(-4)
    }
  }
}
</script>

<style scoped>
.bank-accounts-page{width:100%}
.hero{border-left:4px solid #165134}
.summary-card,.filter-card,.table-card{border:1px solid rgba(22,81,52,.08);border-radius:15px!important}
.account-icon{background:#e8f5ee!important}
.bank-accounts-page ::v-deep .v-data-table__wrapper{overflow-x:auto}
.theme--dark .account-icon{background:rgba(91,183,125,.13)!important}
</style>
