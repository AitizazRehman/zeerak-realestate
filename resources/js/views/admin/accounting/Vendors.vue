<template>
  <div class="vendors-page">
    <v-card flat class="hero pa-5 mb-4">
      <div class="d-flex align-center flex-wrap">
        <div>
          <div class="text-overline">PAYABLES MASTER DATA</div>
          <h1 class="text-h5 font-weight-bold mb-1">Vendor Master</h1>
          <div class="grey--text">Maintain approved suppliers used by Accounts Payable.</div>
        </div>
        <v-spacer/>
        <v-btn v-if="$can('vendors.create')" color="#165134" dark depressed @click="openCreate">
          <v-icon left>mdi-store-plus-outline</v-icon>
          Add Vendor
        </v-btn>
      </div>
    </v-card>

    <v-card flat class="filter-card pa-4 mb-4">
      <v-row dense>
        <v-col cols="12" md="5">
          <v-text-field v-model="search" outlined dense hide-details label="Search vendor" prepend-inner-icon="mdi-magnify" @keyup.enter="load"/>
        </v-col>
        <v-col v-if="branches.length" cols="12" md="3">
          <v-select v-model="branchId" :items="branches" item-text="name" item-value="id" outlined dense hide-details clearable label="Branch" @change="load"/>
        </v-col>
        <v-col cols="12" md="2">
          <v-select v-model="active" :items="activeOptions" item-text="text" item-value="value" outlined dense hide-details clearable label="Status" @change="load"/>
        </v-col>
        <v-col cols="12" md="2">
          <v-btn block outlined color="#165134" :loading="loading" @click="load">Refresh</v-btn>
        </v-col>
      </v-row>
    </v-card>

    <v-card flat class="table-card">
      <v-data-table
        :headers="headers"
        :items="items"
        :loading="loading"
        :server-items-length="total"
        :page.sync="page"
        :items-per-page.sync="perPage"
        :footer-props="{'items-per-page-options':[10,25,50,100]}"
        @update:page="load"
        @update:items-per-page="perPageChanged"
      >
        <template v-slot:item.vendor="{item}">
          <div class="font-weight-medium">{{ item.name }}</div>
          <div class="caption grey--text">{{ item.vendor_number }}</div>
        </template>
        <template v-slot:item.contact="{item}">
          <div>{{ item.contact_person || '—' }}</div>
          <div class="caption grey--text">{{ item.phone || item.email || 'No contact' }}</div>
        </template>
        <template v-slot:item.bank="{item}">
          <div>{{ item.bank_name || '—' }}</div>
          <div class="caption grey--text">{{ item.iban || item.account_number || '' }}</div>
        </template>
        <template v-slot:item.is_active="{item}">
          <v-chip x-small :color="item.is_active ? 'success' : 'grey'" dark>{{ item.is_active ? 'Active' : 'Inactive' }}</v-chip>
        </template>
        <template v-slot:item.actions="{item}">
          <v-btn v-if="$can('vendors.edit')" icon small @click="openEdit(item)"><v-icon small>mdi-pencil-outline</v-icon></v-btn>
        </template>
      </v-data-table>
    </v-card>

    <v-dialog v-model="dialog" max-width="900" persistent>
      <v-card>
        <v-card-title>{{ form.id ? 'Edit Vendor' : 'Add Vendor' }}</v-card-title>
        <v-card-text>
          <v-row>
            <v-col cols="12" md="4"><v-select v-model="form.branch_id" :items="branches" item-text="name" item-value="id" outlined dense label="Branch *" :disabled="!branches.length" :error-messages="errors.branch_id"/></v-col>
            <v-col cols="12" md="4"><v-text-field v-model="form.name" outlined dense label="Vendor Name *" :error-messages="errors.name"/></v-col>
            <v-col cols="12" md="4"><v-text-field v-model="form.contact_person" outlined dense label="Contact Person"/></v-col>
            <v-col cols="12" md="4"><v-text-field v-model="form.phone" outlined dense label="Phone"/></v-col>
            <v-col cols="12" md="4"><v-text-field v-model="form.email" outlined dense label="Email" :error-messages="errors.email"/></v-col>
            <v-col cols="12" md="4"><v-text-field v-model="form.tax_number" outlined dense label="NTN / Tax Number"/></v-col>
            <v-col cols="12" md="4"><v-text-field v-model="form.city" outlined dense label="City"/></v-col>
            <v-col cols="12" md="4"><v-text-field v-model.number="form.payment_terms_days" type="number" min="0" max="365" outlined dense label="Payment Terms (Days)"/></v-col>
            <v-col cols="12" md="4"><v-switch v-model="form.is_active" inset label="Active"/></v-col>
            <v-col cols="12"><v-text-field v-model="form.address" outlined dense label="Address"/></v-col>
            <v-col cols="12" md="4"><v-text-field v-model="form.bank_name" outlined dense label="Bank Name"/></v-col>
            <v-col cols="12" md="4"><v-text-field v-model="form.account_title" outlined dense label="Account Title"/></v-col>
            <v-col cols="12" md="4"><v-text-field v-model="form.account_number" outlined dense label="Account Number"/></v-col>
            <v-col cols="12"><v-text-field v-model="form.iban" outlined dense label="IBAN"/></v-col>
            <v-col cols="12"><v-textarea v-model="form.notes" outlined dense rows="2" label="Notes"/></v-col>
          </v-row>
        </v-card-text>
        <v-card-actions>
          <v-spacer/>
          <v-btn text @click="dialog=false">Cancel</v-btn>
          <v-btn color="#165134" dark depressed :loading="saving" @click="save">Save Vendor</v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>
  </div>
</template>

<script>
import api from '../../../services/api'

export default {
  name:'Vendors',
  data(){
    return{
      loading:false,saving:false,dialog:false,items:[],total:0,page:1,perPage:25,search:'',branchId:null,active:true,branches:[],errors:{},
      activeOptions:[{text:'Active',value:true},{text:'Inactive',value:false}],
      headers:[
        {text:'Vendor',value:'vendor'},{text:'Branch',value:'branch.name'},{text:'Contact',value:'contact'},
        {text:'Tax #',value:'tax_number'},{text:'Terms',value:'payment_terms_days'},{text:'Bank',value:'bank'},
        {text:'Bills',value:'bills_count',align:'right'},{text:'Status',value:'is_active'},{text:'',value:'actions',sortable:false}
      ],
      form:{}
    }
  },
  async mounted(){
    await this.loadBranches()
    await this.load()
  },
  methods:{
    async loadBranches(){
      try{
        const r=await api.get('/accounting/accounts-payable/options',{skipGlobalLoader:true})
        this.branches=r.data.branches || []
      }catch(e){}
    },
    async load(){
      this.loading=true
      try{
        const r=await api.get('/vendors',{params:{search:this.search||undefined,branch_id:this.branchId||undefined,active:this.active===null?undefined:this.active,page:this.page,per_page:this.perPage},skipGlobalLoader:true})
        this.items=r.data.data||[];this.total=Number(r.data.total||0)
      }finally{this.loading=false}
    },
    perPageChanged(v){this.perPage=Number(v||25);this.page=1;this.load()},
    blank(){return{id:null,branch_id:this.branchId||null,name:'',contact_person:'',phone:'',email:'',tax_number:'',address:'',city:'',bank_name:'',account_title:'',account_number:'',iban:'',payment_terms_days:30,is_active:true,notes:''}},
    openCreate(){this.form=this.blank();this.errors={};this.dialog=true},
    openEdit(item){this.form=Object.assign(this.blank(),item);this.errors={};this.dialog=true},
    async save(){
      this.saving=true;this.errors={}
      try{
        if(this.form.id) await api.put('/vendors/'+this.form.id,this.form,{skipGlobalError:true})
        else await api.post('/vendors',this.form,{skipGlobalError:true})
        this.dialog=false;await this.load()
      }catch(e){
        if(e.response&&e.response.status===422)this.errors=e.response.data.errors||{}
        else this.$root.$emit('show-error',this.errorText(e,'Unable to save vendor.'))
      }finally{this.saving=false}
    },
    errorText(e,fallback){
      if(e.response&&e.response.data){
        const errors=e.response.data.errors||{};const first=Object.keys(errors)[0]
        if(first&&errors[first]&&errors[first][0])return errors[first][0]
        if(e.response.data.message)return e.response.data.message
      }
      return fallback
    }
  }
}
</script>

<style scoped>
.hero{border-left:4px solid #165134}
.filter-card,.table-card{border:1px solid rgba(22,81,52,.08);border-radius:15px!important}
</style>
