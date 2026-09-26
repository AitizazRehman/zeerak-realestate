<template>
  <div class="coa-page">
    <v-card flat class="hero pa-5 mb-4">
      <div class="d-flex align-center flex-wrap">
        <div>
          <div class="text-overline">ACCOUNTING FOUNDATION</div>
          <h1 class="text-h5 font-weight-bold mb-1">Chart of Accounts</h1>
          <div class="grey--text">Build the account hierarchy that will drive the General Ledger and financial statements.</div>
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
          <v-icon left>mdi-plus</v-icon>
          Add Account
        </v-btn>
      </div>
    </v-card>

    <v-row class="mb-1">
      <v-col cols="12" sm="4">
        <v-card flat class="summary-card pa-4">
          <div class="d-flex align-center">
            <v-avatar size="44" class="soft-green mr-3">
              <v-icon color="#165134">mdi-file-tree-outline</v-icon>
            </v-avatar>
            <div>
              <div class="caption grey--text">Total Accounts</div>
              <div class="text-h6 font-weight-bold">{{ summary.total || 0 }}</div>
            </div>
          </div>
        </v-card>
      </v-col>

      <v-col cols="12" sm="4">
        <v-card flat class="summary-card pa-4">
          <div class="d-flex align-center">
            <v-avatar size="44" class="soft-blue mr-3">
              <v-icon color="blue">mdi-check-circle-outline</v-icon>
            </v-avatar>
            <div>
              <div class="caption grey--text">Active Accounts</div>
              <div class="text-h6 font-weight-bold">{{ summary.active || 0 }}</div>
            </div>
          </div>
        </v-card>
      </v-col>

      <v-col cols="12" sm="4">
        <v-card flat class="summary-card pa-4">
          <div class="d-flex align-center">
            <v-avatar size="44" class="soft-gold mr-3">
              <v-icon color="#9b7424">mdi-lock-outline</v-icon>
            </v-avatar>
            <div>
              <div class="caption grey--text">Control Accounts</div>
              <div class="text-h6 font-weight-bold">{{ summary.control_accounts || 0 }}</div>
            </div>
          </div>
        </v-card>
      </v-col>
    </v-row>

    <v-card flat class="filter-card pa-4 mb-4">
      <v-row dense align="center">
        <v-col cols="12" md="6">
          <v-text-field
            v-model="search"
            outlined
            dense
            hide-details
            clearable
            prepend-inner-icon="mdi-magnify"
            label="Search account code or name"
            @keyup.enter="load"
            @click:clear="load"
          />
        </v-col>

        <v-col cols="12" md="4">
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

        <v-col cols="12" md="2" class="text-md-right">
          <v-btn text color="#165134" @click="load">
            <v-icon left>mdi-refresh</v-icon>
            Refresh
          </v-btn>
        </v-col>
      </v-row>
    </v-card>

    <v-card flat class="table-card">
      <v-data-table
        :headers="headers"
        :items="displayAccounts"
        :items-per-page="50"
        mobile-breakpoint="760"
      >
        <template v-slot:item.code="{item}">
          <div class="d-flex align-center" :style="{paddingLeft:(item._depth * 22)+'px'}">
            <v-icon
              v-if="item.children_count"
              small
              class="mr-2"
              color="grey"
            >
              mdi-file-tree-outline
            </v-icon>
            <v-icon
              v-else
              small
              class="mr-2"
              color="grey lighten-1"
            >
              mdi-subdirectory-arrow-right
            </v-icon>
            <span class="font-weight-bold primary--text">{{item.code}}</span>
          </div>
        </template>

        <template v-slot:item.name="{item}">
          <div class="py-2">
            <div class="font-weight-medium">{{item.name}}</div>
            <div v-if="item.parent" class="caption grey--text">
              Parent: {{item.parent.code}} · {{item.parent.name}}
            </div>
          </div>
        </template>

        <template v-slot:item.account_type="{item}">
          <v-chip x-small outlined :color="typeColor(item.account_type)">
            {{typeLabel(item.account_type)}}
          </v-chip>
        </template>

        <template v-slot:item.normal_balance="{item}">
          <span class="text-capitalize">{{item.normal_balance}}</span>
        </template>

        <template v-slot:item.is_control_account="{item}">
          <v-chip
            x-small
            :color="item.is_control_account ? 'amber darken-2' : 'grey'"
            :outlined="!item.is_control_account"
            :dark="item.is_control_account"
          >
            {{item.is_control_account ? 'Control' : 'Posting'}}
          </v-chip>
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
            v-if="$can('accounting.delete') && !item.is_system"
            icon
            small
            color="error"
            title="Delete account"
            @click="askDelete(item)"
          >
            <v-icon small>mdi-delete-outline</v-icon>
          </v-btn>
        </template>

        <template v-slot:no-data>
          <div class="pa-10 text-center grey--text">
            <v-icon size="48" color="grey lighten-1">mdi-file-tree-outline</v-icon>
            <div class="mt-2">No accounts found.</div>
          </div>
        </template>
      </v-data-table>
    </v-card>

    <v-dialog v-model="dialog" max-width="720" persistent>
      <v-card>
        <v-card-title>
          <div>
            <div class="text-h6 font-weight-bold">{{editing ? 'Edit Account' : 'Add Account'}}</div>
            <div class="caption grey--text">
              {{editing && editing.is_system ? 'Core classification fields are protected for system accounts.' : 'Create a posting or control account in the accounting hierarchy.'}}
            </div>
          </div>
          <v-spacer/>
          <v-btn icon @click="closeDialog">
            <v-icon>mdi-close</v-icon>
          </v-btn>
        </v-card-title>

        <v-divider/>

        <v-card-text class="pt-5">
          <v-form @submit.prevent="save">
            <v-row>
              <v-col cols="12" md="4">
                <v-text-field
                  v-model.trim="form.code"
                  outlined
                  dense
                  label="Account Code *"
                  :disabled="!!(editing && editing.is_system)"
                  :error-messages="errors.code"
                />
              </v-col>

              <v-col cols="12" md="8">
                <v-text-field
                  v-model.trim="form.name"
                  outlined
                  dense
                  label="Account Name *"
                  :error-messages="errors.name"
                />
              </v-col>

              <v-col cols="12" md="6">
                <v-select
                  v-model="form.account_type"
                  :items="accountTypes"
                  item-text="text"
                  item-value="value"
                  outlined
                  dense
                  label="Account Type *"
                  :disabled="!!(editing && editing.is_system)"
                  :error-messages="errors.account_type"
                  @change="onTypeChanged"
                />
              </v-col>

              <v-col cols="12" md="6">
                <v-autocomplete
                  v-model="form.parent_id"
                  :items="parentOptions"
                  item-text="display"
                  item-value="id"
                  outlined
                  dense
                  clearable
                  label="Parent Account"
                  :disabled="!!(editing && editing.is_system)"
                  :error-messages="errors.parent_id"
                />
              </v-col>

              <v-col cols="12" md="4">
                <v-text-field
                  :value="normalBalanceLabel"
                  outlined
                  dense
                  readonly
                  label="Normal Balance"
                />
              </v-col>

              <v-col cols="12" md="4">
                <v-switch
                  v-model="form.is_control_account"
                  color="#165134"
                  label="Control Account"
                  hide-details
                />
              </v-col>

              <v-col cols="12" md="4">
                <v-switch
                  v-model="form.allow_manual_posting"
                  color="#165134"
                  label="Allow Manual Posting"
                  hide-details
                />
              </v-col>

              <v-col cols="12">
                <v-textarea
                  v-model="form.description"
                  outlined
                  dense
                  rows="2"
                  label="Description"
                  :error-messages="errors.description"
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
          </v-form>
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

    <v-dialog v-model="deleteDialog" max-width="480" persistent>
      <v-card>
        <v-card-title>Delete Account</v-card-title>
        <v-card-text>
          <v-alert type="warning" outlined dense>
            Delete <strong>{{deleteItem ? deleteItem.code+' · '+deleteItem.name : ''}}</strong>?
            Accounts with child accounts cannot be deleted.
          </v-alert>
        </v-card-text>
        <v-card-actions>
          <v-spacer/>
          <v-btn text @click="deleteDialog=false">Cancel</v-btn>
          <v-btn color="error" depressed @click="confirmDelete">Delete</v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>
  </div>
</template>

<script>
import api from '../../../services/api'

export default {
  name:'ChartOfAccounts',

  data(){
    return{
      search:'',
      typeFilter:null,
      accounts:[],
      summary:{total:0,active:0,control_accounts:0},
      dialog:false,
      editing:null,
      deleteDialog:false,
      deleteItem:null,
      errors:{},
      form:this.blank(),
      accountTypes:[
        {text:'Asset',value:'asset'},
        {text:'Liability',value:'liability'},
        {text:'Equity',value:'equity'},
        {text:'Revenue',value:'revenue'},
        {text:'Cost of Sales',value:'cost_of_sales'},
        {text:'Expense',value:'expense'}
      ],
      headers:[
        {text:'Code',value:'code'},
        {text:'Account',value:'name'},
        {text:'Type',value:'account_type'},
        {text:'Normal Balance',value:'normal_balance'},
        {text:'Classification',value:'is_control_account',sortable:false},
        {text:'Status',value:'is_active'},
        {text:'Actions',value:'actions',sortable:false,align:'right'}
      ]
    }
  },

  computed:{
    displayAccounts(){
      const byParent={}
      const roots=[]

      this.accounts.forEach(function(account){
        const key=account.parent_id ? String(account.parent_id) : 'root'
        if(!byParent[key])byParent[key]=[]
        byParent[key].push(account)
      })

      Object.keys(byParent).forEach(function(key){
        byParent[key].sort(function(a,b){
          return String(a.code).localeCompare(String(b.code),undefined,{numeric:true})
        })
      })

      const walk=function(parentKey,depth){
        const items=byParent[parentKey]||[]
        items.forEach(function(account){
          roots.push(Object.assign({},account,{_depth:depth}))
          walk(String(account.id),depth+1)
        })
      }

      walk('root',0)

      // Search/filter results may omit parents, so append any records not reached above.
      const seen={}
      roots.forEach(function(item){seen[item.id]=true})
      this.accounts.forEach(function(account){
        if(!seen[account.id])roots.push(Object.assign({},account,{_depth:0}))
      })

      return roots
    },

    parentOptions(){
      const currentId=this.editing ? Number(this.editing.id) : null
      const type=this.form.account_type

      return this.accounts
        .filter(function(account){
          return (!currentId || Number(account.id)!==currentId)
            && (!type || account.account_type===type)
            && account.is_active
        })
        .map(function(account){
          return{
            id:account.id,
            display:account.code+' · '+account.name
          }
        })
    },

    normalBalanceLabel(){
      return ['asset','cost_of_sales','expense'].indexOf(this.form.account_type)!==-1
        ? 'Debit'
        : 'Credit'
    }
  },

  mounted(){
    this.load()
  },

  methods:{
    blank(){
      return{
        parent_id:null,
        code:'',
        name:'',
        account_type:'asset',
        is_control_account:false,
        allow_manual_posting:true,
        is_active:true,
        description:''
      }
    },

    async load(){
      const r=await api.get('/accounting/chart-of-accounts',{
        params:{
          search:this.search||undefined,
          account_type:this.typeFilter||undefined
        }
      })

      this.accounts=r.data.data||[]
      this.summary=r.data.summary||{total:0,active:0,control_accounts:0}
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
      this.form={
        parent_id:item.parent_id||null,
        code:item.code||'',
        name:item.name||'',
        account_type:item.account_type||'asset',
        is_control_account:!!item.is_control_account,
        allow_manual_posting:!!item.allow_manual_posting,
        is_active:!!item.is_active,
        description:item.description||''
      }
      this.dialog=true
    },

    closeDialog(){
      this.dialog=false
      this.editing=null
      this.errors={}
    },

    onTypeChanged(){
      if(this.form.parent_id){
        const parent=this.accounts.find(function(account){
          return Number(account.id)===Number(this.form.parent_id)
        }.bind(this))

        if(parent&&parent.account_type!==this.form.account_type){
          this.form.parent_id=null
        }
      }
    },

    async save(){
      this.errors={}

      try{
        if(this.editing){
          await api.put(
            '/accounting/chart-of-accounts/'+this.editing.id,
            this.form,
            {skipGlobalError:true}
          )
        }else{
          await api.post(
            '/accounting/chart-of-accounts',
            this.form,
            {skipGlobalError:true}
          )
        }

        this.closeDialog()
        await this.load()
      }catch(e){
        if(e.response&&e.response.status===422){
          this.errors=e.response.data.errors||{}
          if(!Object.keys(this.errors).length){
            this.$root.$emit('show-error',e.response.data.message||'Unable to save account.')
          }
        }else{
          this.$root.$emit(
            'show-error',
            (e.response&&e.response.data&&e.response.data.message)||'Unable to save account.'
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
        await api.delete(
          '/accounting/chart-of-accounts/'+this.deleteItem.id,
          {skipGlobalError:true}
        )
        this.deleteDialog=false
        this.deleteItem=null
        await this.load()
      }catch(e){
        this.$root.$emit(
          'show-error',
          (e.response&&e.response.data&&e.response.data.message)||'Unable to delete account.'
        )
      }
    },

    typeLabel(value){
      const item=this.accountTypes.find(function(type){return type.value===value})
      return item ? item.text : value
    },

    typeColor(value){
      return{
        asset:'blue',
        liability:'deep-orange',
        equity:'purple',
        revenue:'success',
        cost_of_sales:'amber darken-2',
        expense:'red'
      }[value]||'grey'
    }
  }
}
</script>

<style scoped>
.coa-page{width:100%}
.hero{border-left:4px solid #165134}
.summary-card,.filter-card,.table-card{
  border:1px solid rgba(22,81,52,.08);
  border-radius:15px!important
}
.soft-green{background:#e8f5ee!important}
.soft-blue{background:#eaf2ff!important}
.soft-gold{background:#fff5d9!important}
.coa-page ::v-deep .v-data-table__wrapper{overflow-x:auto}
</style>
