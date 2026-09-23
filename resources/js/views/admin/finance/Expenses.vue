<template>
  <div class="page">
    <v-card flat class="hero pa-5 mb-4">
      <div class="d-flex flex-wrap align-center">
        <div>
          <div class="text-overline">FINANCE</div>
          <h1 class="text-h5 font-weight-bold">Expenses</h1>
          <div class="grey--text">Track project and operational expenses with supporting receipts and invoices.</div>
        </div>
        <v-spacer/>
        <v-btn v-if="$can('expenses.create')" color="#165134" dark depressed class="rounded-lg" @click="openCreate">
          <v-icon left>mdi-cash-minus</v-icon>Record Expense
        </v-btn>
      </div>
    </v-card>

    <v-row class="mb-1">
      <v-col cols="6" md="3"><v-card flat class="summary-card pa-4"><div class="caption grey--text">Expenses Shown</div><div class="text-h6 font-weight-bold">{{items.length}}</div></v-card></v-col>
      <v-col cols="6" md="3"><v-card flat class="summary-card pa-4"><div class="caption grey--text">Amount Shown</div><div class="text-h6 font-weight-bold">PKR {{money(amountShown)}}</div></v-card></v-col>
      <v-col cols="6" md="3"><v-card flat class="summary-card pa-4"><div class="caption grey--text">This Month Shown</div><div class="text-h6 font-weight-bold">PKR {{money(monthAmountShown)}}</div></v-card></v-col>
      <v-col cols="6" md="3"><v-card flat class="summary-card pa-4"><div class="caption grey--text">Categories Shown</div><div class="text-h6 font-weight-bold">{{categoryCount}}</div></v-card></v-col>
    </v-row>

    <v-card flat class="filter-card mb-4">
      <v-card-text>
        <v-row dense align="center">
          <v-col cols="12" md="3"><v-text-field v-model="filters.search" outlined dense hide-details clearable prepend-inner-icon="mdi-magnify" label="Expense, vendor or description" @keyup.enter="load" @click:clear="load"/></v-col>
          <v-col cols="12" md="2"><v-select v-model="filters.project_id" :items="projects" item-text="name" item-value="id" outlined dense hide-details clearable label="Project" @change="load"/></v-col>
          <v-col cols="12" md="2"><v-select v-model="filters.category" :items="categories" outlined dense hide-details clearable label="Category" @change="load"/></v-col>
          <v-col cols="6" md="2"><v-text-field v-model="filters.from" type="date" outlined dense hide-details label="From" @change="load"/></v-col>
          <v-col cols="6" md="2"><v-text-field v-model="filters.to" type="date" outlined dense hide-details label="To" @change="load"/></v-col>
          <v-col cols="12" md="1" class="text-md-right"><v-btn icon :loading="loading" @click="load"><v-icon>mdi-refresh</v-icon></v-btn></v-col>
        </v-row>
      </v-card-text>
    </v-card>

    <v-card flat class="table-card">
      <v-data-table :headers="headers" :items="items" :loading="loading" :options.sync="options" :server-items-length="total">
        <template v-slot:item.expense_number="{item}"><div class="font-weight-bold primary--text">{{item.expense_number}}</div><div class="caption grey--text">{{item.vendor_name || 'No vendor'}}</div></template>
        <template v-slot:item.project.name="{item}"><div>{{item.project ? item.project.name : (item.branch ? item.branch.name+' / General' : 'General / Unassigned')}}</div><div v-if="item.property" class="caption grey--text">{{item.property.property_number}}</div></template>
        <template v-slot:item.amount="{item}"><strong>PKR {{money(item.amount)}}</strong></template>
        <template v-slot:item.expense_date="{item}">{{dateOnly(item.expense_date)}}</template>
        <template v-slot:item.payment_method="{item}">{{formatText(item.payment_method)}}</template>
        <template v-slot:item.actions="{item}">
          <v-badge v-if="$can('expenses.view')" :content="item.financial_documents_count" :value="item.financial_documents_count" color="#165134" overlap><v-btn icon small color="blue-grey" title="Receipts / invoices / documents" @click="openDocuments(item)"><v-icon small>mdi-paperclip</v-icon></v-btn></v-badge>
          <v-btn v-if="$can('expenses.edit')" icon small title="Edit expense" @click="edit(item)"><v-icon small>mdi-pencil-outline</v-icon></v-btn>
          <v-btn v-if="$can('expenses.delete')" icon small color="error" title="Delete expense" @click="openDelete(item)"><v-icon small>mdi-delete-outline</v-icon></v-btn>
        </template>
        <template v-slot:no-data><div class="pa-10 text-center grey--text"><v-icon size="52" color="grey lighten-1">mdi-receipt-text-outline</v-icon><div class="mt-2">No expenses found.</div></div></template>
      </v-data-table>
    </v-card>

    <v-dialog v-model="dialog" max-width="760" persistent>
      <v-card>
        <v-card-title>
          <div><div class="text-h6 font-weight-bold">{{editing ? 'Edit Expense' : 'Record Expense'}}</div><div class="caption grey--text">Capture the expense details, then attach a receipt, invoice or voucher if required.</div></div>
          <v-spacer/><v-btn icon :disabled="saving" @click="dialog=false"><v-icon>mdi-close</v-icon></v-btn>
        </v-card-title>
        <v-divider/>
        <v-card-text class="pt-5">
          <v-row>
            <v-col cols="12" md="6"><v-select v-model="form.project_id" :items="projects" item-text="name" item-value="id" outlined dense clearable label="Project" @change="formProjectChanged"/></v-col>
            <v-col cols="12" md="6"><v-autocomplete v-model="form.property_id" :items="properties" item-text="property_number" item-value="id" outlined dense clearable label="Property (optional)" :loading="loadingProperties" :disabled="!form.project_id"/></v-col>
            <v-col cols="12" md="6"><v-select v-model="form.category" :items="categories" outlined dense label="Category *"/></v-col>
            <v-col cols="12" md="6"><v-text-field v-model="form.vendor_name" outlined dense label="Vendor / Payee"/></v-col>
            <v-col cols="12"><v-text-field v-model="form.description" outlined dense label="Description *"/></v-col>
            <v-col cols="12" md="6"><v-text-field v-model="form.amount" type="number" min="0.01" step="0.01" outlined dense label="Amount *" prefix="PKR"/></v-col>
            <v-col cols="12" md="6"><v-text-field v-model="form.expense_date" type="date" outlined dense label="Expense Date *"/></v-col>
            <v-col cols="12" md="6"><v-select v-model="form.payment_method" :items="methods" outlined dense label="Payment Method *"/></v-col>
            <v-col cols="12" md="6"><v-text-field v-model="form.reference_number" outlined dense label="Reference #"/></v-col>
            <v-col cols="12"><v-textarea v-model="form.notes" outlined dense rows="2" label="Notes"/></v-col>
          </v-row>
          <v-alert type="info" text dense>After saving, you can attach a receipt, invoice, voucher, bank slip, cheque copy, agreement or other supporting document.</v-alert>
        </v-card-text>
        <v-card-actions class="px-6 pb-5">
          <v-spacer/>
          <v-btn text :disabled="saving" @click="dialog=false">Cancel</v-btn>
          <v-btn v-if="(editing && $can('expenses.edit')) || (!editing && $can('expenses.create'))" outlined color="#165134" :loading="saving" @click="save(false)">Save</v-btn>
          <v-btn v-if="(editing && $can('expenses.edit')) || (!editing && $can('expenses.create'))" color="#165134" dark depressed :loading="saving" @click="save(true)"><v-icon left>mdi-paperclip</v-icon>Save & Add Document</v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>

    <financial-documents-dialog
      v-model="documentsDialog"
      entity-type="expense"
      :entity-id="documentEntity ? documentEntity.id : null"
      :title="documentEntity ? 'Expense Documents — '+documentEntity.expense_number : 'Expense Documents'"
      :can-upload="$can('expenses.create') || $can('expenses.edit')"
      :can-delete="$can('expenses.edit') || $can('expenses.delete')"
      @updated="load"
    />

    <v-dialog v-model="deleteDialog" max-width="500" persistent>
      <v-card>
        <v-card-title>Delete Expense</v-card-title>
        <v-card-text><v-alert type="warning" outlined dense>This permanently removes <strong>{{deleteItem && deleteItem.expense_number}}</strong>. Expenses with supporting documents must have those documents removed first. The financial audit history is retained.</v-alert></v-card-text>
        <v-card-actions><v-spacer/><v-btn text :disabled="deleting" @click="deleteDialog=false">Cancel</v-btn><v-btn color="error" :loading="deleting" @click="confirmDelete">Delete Expense</v-btn></v-card-actions>
      </v-card>
    </v-dialog>
  </div>
</template>

<script>
import api from '../../../services/api'
import FinancialDocumentsDialog from '../../../components/FinancialDocumentsDialog.vue'

export default {
  name:'Expenses',
  components:{FinancialDocumentsDialog},
  data:()=>({
    loading:false,saving:false,dialog:false,deleteDialog:false,deleting:false,deleteItem:null,
    documentsDialog:false,documentEntity:null,editing:null,items:[],total:0,projects:[],properties:[],loadingProperties:false,
    options:{page:1,itemsPerPage:15},
    filters:{search:'',project_id:null,category:null,from:'',to:''},
    categories:['Land & Development','Construction','Materials','Labour','Utilities','Marketing','Office','Transport','Legal','Maintenance','Other'],
    methods:['cash','bank_transfer','cheque','online','other'],
    headers:[
      {text:'Expense # / Vendor',value:'expense_number'},{text:'Category',value:'category'},{text:'Description',value:'description'},
      {text:'Project / Property',value:'project.name'},{text:'Amount',value:'amount',align:'right'},{text:'Date',value:'expense_date'},
      {text:'Method',value:'payment_method'},{text:'Actions',value:'actions',sortable:false,align:'right'}
    ],
    form:{project_id:null,property_id:null,category:'Construction',description:'',amount:null,expense_date:new Date().toISOString().slice(0,10),payment_method:'cash',vendor_name:'',reference_number:'',notes:''}
  }),
  computed:{
    amountShown(){return this.items.reduce(function(n,x){return n+Number(x.amount||0)},0)},
    monthAmountShown(){const month=new Date().toISOString().slice(0,7);return this.items.filter(function(x){return String(x.expense_date||'').slice(0,7)===month}).reduce(function(n,x){return n+Number(x.amount||0)},0)},
    categoryCount(){return new Set(this.items.map(function(x){return x.category}).filter(Boolean)).size}
  },
  watch:{options:{deep:true,handler(){this.load()}}},
  mounted(){this.load();this.loadProjects()},
  methods:{
    dateOnly(v){return v?String(v).slice(0,10):'—'},
    formatText(v){return v?String(v).replace(/_/g,' ').replace(/\b\w/g,function(x){return x.toUpperCase()}):'—'},
    openDocuments(item){this.documentEntity=item;this.documentsDialog=true},
    async load(){this.loading=true;try{const r=await api.get('/expenses',{params:Object.assign({},this.filters,{page:this.options.page,per_page:this.options.itemsPerPage})});this.items=r.data.data||[];this.total=r.data.total||0}catch(e){this.$root.$emit('show-error',(e.response&&e.response.data&&e.response.data.message)||'Unable to load expenses.')}finally{this.loading=false}},
    async loadProjects(){try{const r=await api.get('/projects',{params:{per_page:100}});this.projects=r.data.data||r.data||[]}catch(e){this.projects=[]}},
    async loadProperties(projectId){this.properties=[];if(!projectId)return;this.loadingProperties=true;try{const r=await api.get('/properties',{params:{project_id:projectId,per_page:100}});this.properties=r.data.data||[]}catch(e){this.properties=[]}finally{this.loadingProperties=false}},
    async formProjectChanged(id){this.form.property_id=null;await this.loadProperties(id)},
    openCreate(){this.editing=null;this.reset();this.properties=[];this.dialog=true},
    reset(){this.form={project_id:null,property_id:null,category:'Construction',description:'',amount:null,expense_date:new Date().toISOString().slice(0,10),payment_method:'cash',vendor_name:'',reference_number:'',notes:''}},
    async edit(item){this.editing=item;this.form={project_id:item.project_id||(item.project&&item.project.id)||null,property_id:item.property_id||(item.property&&item.property.id)||null,category:item.category,description:item.description,amount:item.amount,expense_date:String(item.expense_date).slice(0,10),payment_method:item.payment_method,vendor_name:item.vendor_name||'',reference_number:item.reference_number||'',notes:item.notes||''};if(this.form.project_id)await this.loadProperties(this.form.project_id);this.dialog=true},
    async save(addDocument){if(!this.form.category||!this.form.description||!Number(this.form.amount)){this.$root.$emit('show-error','Category, description and amount are required.');return}this.saving=true;try{let r;if(this.editing)r=await api.put('/expenses/'+this.editing.id,this.form);else r=await api.post('/expenses',this.form);const saved=(r.data&&r.data.expense)||this.editing;this.dialog=false;await this.load();this.$root.$emit('show-success',(r.data&&r.data.message)||'Expense saved successfully.');if(addDocument&&saved){this.documentEntity=saved;this.documentsDialog=true}this.editing=null}catch(e){this.$root.$emit('show-error',(e.response&&e.response.data&&e.response.data.message)||'Unable to save expense.')}finally{this.saving=false}},
    openDelete(item){if(!this.$can('expenses.delete'))return;this.deleteItem=item;this.deleteDialog=true},
    async confirmDelete(){if(!this.deleteItem)return;this.deleting=true;try{await api.delete('/expenses/'+this.deleteItem.id);this.deleteDialog=false;this.deleteItem=null;await this.load();this.$root.$emit('show-success','Expense deleted successfully.')}catch(e){this.$root.$emit('show-error',(e.response&&e.response.data&&e.response.data.message)||'Unable to delete expense.')}finally{this.deleting=false}},
    money(v){return new Intl.NumberFormat('en-PK',{maximumFractionDigits:0}).format(Number(v||0))}
  }
}
</script>

<style scoped>
.page{width:100%}.hero{border-left:4px solid #165134}.summary-card,.filter-card,.table-card{border:1px solid rgba(22,81,52,.08);border-radius:15px!important}.primary--text{color:#165134!important}.page ::v-deep .v-data-table__wrapper{overflow-x:auto}
</style>