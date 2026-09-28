<template>
  <div class="fixed-assets-page">
    <v-card flat class="hero pa-5 mb-4">
      <div class="d-flex align-center flex-wrap">
        <div>
          <div class="text-overline">FIXED ASSET ACCOUNTING</div>
          <h1 class="text-h5 font-weight-bold mb-1">Asset Register & Depreciation</h1>
          <div class="grey--text">Company-owned assets, straight-line depreciation, GL postings, and controlled disposal.</div>
        </div>
        <v-spacer/>
        <v-btn v-if="$can('accounting.edit')" text color="#165134" class="mr-2" @click="openPeriodRun">
          <v-icon left>mdi-calendar-sync</v-icon>Run Depreciation
        </v-btn>
        <v-btn v-if="$can('accounting.create')" color="#165134" dark depressed @click="openAssetCreate">
          <v-icon left>mdi-package-variant-plus</v-icon>Add Asset
        </v-btn>
      </div>
    </v-card>

    <v-card flat class="filter-card pa-4 mb-4">
      <v-row dense>
        <v-col cols="12" md="3">
          <v-text-field v-model="filters.search" outlined dense hide-details label="Asset / Serial / Location" prepend-inner-icon="mdi-magnify" @keyup.enter="loadAssets"/>
        </v-col>
        <v-col v-if="branches.length" cols="12" md="2">
          <v-select v-model="filters.branch_id" :items="branches" item-text="name" item-value="id" clearable outlined dense hide-details label="Branch" @change="filterBranchChanged"/>
        </v-col>
        <v-col cols="12" md="2">
          <v-autocomplete v-model="filters.project_id" :items="projectsForFilter" item-text="display" item-value="id" clearable outlined dense hide-details label="Project"/>
        </v-col>
        <v-col cols="12" md="2">
          <v-select v-model="filters.category_id" :items="categories" item-text="name" item-value="id" clearable outlined dense hide-details label="Category"/>
        </v-col>
        <v-col cols="12" md="1">
          <v-select v-model="filters.status" :items="['active','disposed']" clearable outlined dense hide-details label="Status"/>
        </v-col>
        <v-col cols="12" md="2">
          <v-btn block color="#165134" dark depressed :loading="loading" @click="loadAssets">Apply</v-btn>
        </v-col>
      </v-row>
    </v-card>

    <v-row class="mb-2">
      <v-col cols="6" md="3"><v-card flat class="summary-card pa-4"><div class="caption grey--text">Assets Shown</div><div class="text-h6 font-weight-bold">{{ total }}</div></v-card></v-col>
      <v-col cols="6" md="3"><v-card flat class="summary-card pa-4"><div class="caption grey--text">Cost</div><div class="text-h6 font-weight-bold">PKR {{ money(visibleCost) }}</div></v-card></v-col>
      <v-col cols="6" md="3"><v-card flat class="summary-card pa-4"><div class="caption grey--text">Accumulated Depreciation</div><div class="text-h6 font-weight-bold">PKR {{ money(visibleAccumulated) }}</div></v-card></v-col>
      <v-col cols="6" md="3"><v-card flat class="summary-card pa-4"><div class="caption grey--text">Net Book Value</div><div class="text-h6 font-weight-bold">PKR {{ money(visibleNbv) }}</div></v-card></v-col>
    </v-row>

    <v-card flat class="table-card">
      <v-tabs v-model="tab" color="#165134">
        <v-tab>Asset Register</v-tab>
        <v-tab>Categories</v-tab>
      </v-tabs>
      <v-divider/>

      <v-tabs-items v-model="tab">
        <v-tab-item>
          <v-data-table
            :headers="headers"
            :items="items"
            :loading="loading"
            :server-items-length="total"
            :page.sync="page"
            :items-per-page.sync="perPage"
            :footer-props="{'items-per-page-options':[10,25,50,100]}"
            @update:page="loadAssets"
          >
            <template v-slot:item.asset="{item}">
              <div class="font-weight-medium">{{ item.name }}</div>
              <div class="caption grey--text">{{ item.asset_number }}<span v-if="item.serial_number"> · {{ item.serial_number }}</span></div>
            </template>
            <template v-slot:item.scope="{item}">
              <div>{{ item.branch ? item.branch.name : '—' }}</div>
              <div class="caption grey--text">{{ item.project ? item.project.name : 'General / Unassigned' }}</div>
            </template>
            <template v-slot:item.cost="{item}">PKR {{ money(item.cost) }}</template>
            <template v-slot:item.accumulated_depreciation="{item}">PKR {{ money(item.accumulated_depreciation) }}</template>
            <template v-slot:item.net_book_value="{item}"><strong>PKR {{ money(item.net_book_value) }}</strong></template>
            <template v-slot:item.status="{item}">
              <v-chip x-small :color="item.status==='active'?'success':'grey'" dark>{{ item.status }}</v-chip>
            </template>
            <template v-slot:item.actions="{item}">
              <v-btn icon small color="#165134" title="View asset" @click="openDetail(item)"><v-icon small>mdi-eye-outline</v-icon></v-btn>
              <v-btn v-if="$can('accounting.edit') && item.status==='active'" icon small title="Edit" @click="openAssetEdit(item)"><v-icon small>mdi-pencil-outline</v-icon></v-btn>
              <v-btn v-if="$can('accounting.edit') && item.status==='active'" icon small color="info" title="Post depreciation" @click="openDepreciation(item)"><v-icon small>mdi-calculator-variant-outline</v-icon></v-btn>
              <v-btn v-if="$can('accounting.edit') && item.status==='active'" icon small color="warning" title="Dispose asset" @click="openDispose(item)"><v-icon small>mdi-package-variant-remove</v-icon></v-btn>
            </template>
          </v-data-table>
        </v-tab-item>

        <v-tab-item>
          <div class="pa-4">
            <div class="d-flex mb-3">
              <v-spacer/>
              <v-btn v-if="$can('accounting.create')" color="#165134" dark depressed small @click="openCategoryCreate">
                <v-icon left small>mdi-plus</v-icon>Add Category
              </v-btn>
            </div>
            <v-data-table :headers="categoryHeaders" :items="allCategories" :loading="categoryLoading" :items-per-page="25">
              <template v-slot:item.asset_account="{item}">{{ accountLabel(item.asset_account) }}</template>
              <template v-slot:item.accumulated="{item}">{{ accountLabel(item.accumulated_depreciation_account) }}</template>
              <template v-slot:item.expense_account="{item}">{{ accountLabel(item.depreciation_expense_account) }}</template>
              <template v-slot:item.residual_value_percent="{item}">{{ item.residual_value_percent }}%</template>
              <template v-slot:item.is_active="{item}"><v-chip x-small :color="item.is_active?'success':'grey'" dark>{{ item.is_active?'Active':'Inactive' }}</v-chip></template>
              <template v-slot:item.actions="{item}">
                <v-btn v-if="$can('accounting.edit')" icon small @click="openCategoryEdit(item)"><v-icon small>mdi-pencil-outline</v-icon></v-btn>
              </template>
            </v-data-table>
          </div>
        </v-tab-item>
      </v-tabs-items>
    </v-card>

    <v-dialog v-model="assetDialog" max-width="950" persistent>
      <v-card>
        <v-card-title>{{ assetForm.id ? 'Edit Fixed Asset' : 'Register Fixed Asset' }}</v-card-title>
        <v-card-text>
          <v-alert v-if="assetForm.id && assetForm.financial_locked" type="info" text dense>Financial fields are locked because accounting history exists.</v-alert>
          <v-row>
            <v-col cols="12" md="4"><v-autocomplete v-model="assetForm.fixed_asset_category_id" :items="categories" item-text="name" item-value="id" outlined dense label="Category *" :disabled="assetForm.financial_locked" @change="categoryChanged"/></v-col>
            <v-col v-if="branches.length" cols="12" md="4"><v-select v-model="assetForm.branch_id" :items="branches" item-text="name" item-value="id" outlined dense label="Branch *" :disabled="!!assetForm.id" @change="assetBranchChanged"/></v-col>
            <v-col cols="12" md="4"><v-autocomplete v-model="assetForm.project_id" :items="projectsForAsset" item-text="display" item-value="id" clearable outlined dense label="Project"/></v-col>
            <v-col cols="12" md="6"><v-text-field v-model="assetForm.name" outlined dense label="Asset Name *"/></v-col>
            <v-col cols="12" md="3"><v-text-field v-model="assetForm.serial_number" outlined dense label="Serial Number"/></v-col>
            <v-col cols="12" md="3"><v-text-field v-model="assetForm.location" outlined dense label="Location"/></v-col>
            <v-col cols="12" md="3"><v-text-field v-model="assetForm.purchase_date" type="date" outlined dense label="Purchase Date *" :disabled="assetForm.financial_locked"/></v-col>
            <v-col cols="12" md="3"><v-text-field v-model="assetForm.in_service_date" type="date" outlined dense label="In-Service Date *" :disabled="assetForm.financial_locked"/></v-col>
            <v-col cols="12" md="2"><v-text-field v-model.number="assetForm.cost" type="number" min="0.01" step="0.01" outlined dense label="Cost *" :disabled="assetForm.financial_locked"/></v-col>
            <v-col cols="12" md="2"><v-text-field v-model.number="assetForm.residual_value" type="number" min="0" step="0.01" outlined dense label="Residual" :disabled="assetForm.financial_locked"/></v-col>
            <v-col cols="12" md="2"><v-text-field v-model.number="assetForm.useful_life_months" type="number" min="1" outlined dense label="Life (Months)" :disabled="assetForm.financial_locked"/></v-col>
            <template v-if="!assetForm.id">
              <v-col cols="12" md="3"><v-select v-model="assetForm.acquisition_type" :items="acquisitionTypes" item-text="text" item-value="value" outlined dense label="Acquisition Posting *"/></v-col>
              <v-col v-if="assetForm.acquisition_type==='cash_bank'" cols="12" md="3"><v-autocomplete v-model="assetForm.acquisition_cash_bank_account_id" :items="cashAccounts" item-text="display" item-value="id" outlined dense label="Cash / Bank *"/></v-col>
            </template>
            <v-col cols="12"><v-textarea v-model="assetForm.notes" outlined dense rows="2" label="Notes"/></v-col>
          </v-row>
        </v-card-text>
        <v-card-actions><v-spacer/><v-btn text @click="assetDialog=false">Cancel</v-btn><v-btn color="#165134" dark depressed :loading="assetSaving" @click="saveAsset">Save Asset</v-btn></v-card-actions>
      </v-card>
    </v-dialog>

    <v-dialog v-model="categoryDialog" max-width="800" persistent>
      <v-card>
        <v-card-title>{{ categoryForm.id ? 'Edit Asset Category' : 'Add Asset Category' }}</v-card-title>
        <v-card-text>
          <v-alert v-if="categoryForm.id && Number(categoryForm.assets_count||0)>0" type="info" text dense>GL mappings are locked because this category already has assets.</v-alert>
          <v-row>
            <v-col cols="12" md="6"><v-text-field v-model="categoryForm.name" outlined dense label="Category Name *"/></v-col>
            <v-col cols="12" md="3"><v-text-field v-model.number="categoryForm.useful_life_months" type="number" min="1" outlined dense label="Default Life (Months) *"/></v-col>
            <v-col cols="12" md="3"><v-text-field v-model.number="categoryForm.residual_value_percent" type="number" min="0" max="99.99" step="0.01" outlined dense label="Residual %"/></v-col>
            <v-col cols="12"><v-autocomplete v-model="categoryForm.asset_account_id" :items="assetAccounts" item-text="display" item-value="id" outlined dense label="Asset Account *" :disabled="categoryMappingsLocked"/></v-col>
            <v-col cols="12"><v-autocomplete v-model="categoryForm.accumulated_depreciation_account_id" :items="accumulatedAccounts" item-text="display" item-value="id" outlined dense label="Accumulated Depreciation Account *" :disabled="categoryMappingsLocked"/></v-col>
            <v-col cols="12"><v-autocomplete v-model="categoryForm.depreciation_expense_account_id" :items="expenseAccounts" item-text="display" item-value="id" outlined dense label="Depreciation Expense Account *" :disabled="categoryMappingsLocked"/></v-col>
            <v-col cols="12" md="4"><v-switch v-model="categoryForm.is_active" inset label="Active"/></v-col>
            <v-col cols="12"><v-textarea v-model="categoryForm.notes" outlined dense rows="2" label="Notes"/></v-col>
          </v-row>
        </v-card-text>
        <v-card-actions><v-spacer/><v-btn text @click="categoryDialog=false">Cancel</v-btn><v-btn color="#165134" dark depressed :loading="categorySaving" @click="saveCategory">Save Category</v-btn></v-card-actions>
      </v-card>
    </v-dialog>

    <v-dialog v-model="depreciationDialog" max-width="600" persistent>
      <v-card>
        <v-card-title>Post Depreciation</v-card-title>
        <v-card-text>
          <v-alert v-if="selectedAsset" type="info" text dense>
            {{ selectedAsset.asset_number }} · Monthly depreciation PKR {{ money(selectedAsset.monthly_depreciation) }} · Remaining PKR {{ money(selectedAsset.remaining_depreciable) }}
          </v-alert>
          <v-select v-model="depreciationForm.accounting_period_id" :items="openPeriods" item-text="display" item-value="id" outlined dense label="Open Accounting Period *"/>
        </v-card-text>
        <v-card-actions><v-spacer/><v-btn text @click="depreciationDialog=false">Cancel</v-btn><v-btn color="#165134" dark depressed :loading="depreciationSaving" @click="postDepreciation">Post</v-btn></v-card-actions>
      </v-card>
    </v-dialog>

    <v-dialog v-model="periodRunDialog" max-width="650" persistent>
      <v-card>
        <v-card-title>Run Period Depreciation</v-card-title>
        <v-card-text>
          <v-alert type="warning" text dense>This posts one straight-line depreciation entry for every eligible active asset in the selected scope. Existing postings for the period are skipped.</v-alert>
          <v-select v-model="periodRunForm.accounting_period_id" :items="openPeriods" item-text="display" item-value="id" outlined dense label="Open Accounting Period *"/>
          <v-select v-if="branches.length" v-model="periodRunForm.branch_id" :items="branches" item-text="name" item-value="id" clearable outlined dense label="Branch"/>
          <v-autocomplete v-model="periodRunForm.project_id" :items="projects" item-text="display" item-value="id" clearable outlined dense label="Project"/>
        </v-card-text>
        <v-card-actions><v-spacer/><v-btn text @click="periodRunDialog=false">Cancel</v-btn><v-btn color="#165134" dark depressed :loading="periodRunSaving" @click="runPeriodDepreciation">Run Depreciation</v-btn></v-card-actions>
      </v-card>
    </v-dialog>

    <v-dialog v-model="disposeDialog" max-width="650" persistent>
      <v-card>
        <v-card-title>Dispose Fixed Asset</v-card-title>
        <v-card-text>
          <v-alert type="warning" text dense>Disposal removes asset cost and accumulated depreciation from the ledger and posts any resulting gain or loss.</v-alert>
          <v-text-field v-model="disposeForm.disposed_on" type="date" outlined dense label="Disposal Date *"/>
          <v-text-field v-model.number="disposeForm.disposal_proceeds" type="number" min="0" step="0.01" outlined dense label="Disposal Proceeds"/>
          <v-autocomplete v-if="Number(disposeForm.disposal_proceeds)>0" v-model="disposeForm.disposal_cash_bank_account_id" :items="cashAccounts" item-text="display" item-value="id" outlined dense label="Cash / Bank Account *"/>
          <v-textarea v-model="disposeForm.disposal_reason" outlined dense rows="3" label="Reason *"/>
        </v-card-text>
        <v-card-actions><v-spacer/><v-btn text @click="disposeDialog=false">Cancel</v-btn><v-btn color="warning" dark depressed :loading="disposeSaving" @click="disposeAsset">Post Disposal</v-btn></v-card-actions>
      </v-card>
    </v-dialog>

    <v-dialog v-model="detailDialog" max-width="1000" scrollable>
      <v-card>
        <v-card-title>
          <div v-if="detail">
            <div class="text-h6 font-weight-bold">{{ detail.asset.name }}</div>
            <div class="caption grey--text">{{ detail.asset.asset_number }}</div>
          </div>
          <v-spacer/><v-btn icon @click="detailDialog=false"><v-icon>mdi-close</v-icon></v-btn>
        </v-card-title>
        <v-divider/>
        <v-card-text class="pt-4">
          <template v-if="detail">
            <v-row>
              <v-col cols="6" md="3"><div class="caption grey--text">Cost</div><strong>PKR {{ money(detail.book_values.cost) }}</strong></v-col>
              <v-col cols="6" md="3"><div class="caption grey--text">Accumulated Depreciation</div><strong>PKR {{ money(detail.book_values.accumulated_depreciation) }}</strong></v-col>
              <v-col cols="6" md="3"><div class="caption grey--text">Net Book Value</div><strong>PKR {{ money(detail.book_values.net_book_value) }}</strong></v-col>
              <v-col cols="6" md="3"><div class="caption grey--text">Monthly Depreciation</div><strong>PKR {{ money(detail.book_values.monthly_depreciation) }}</strong></v-col>
            </v-row>
            <v-simple-table dense class="mt-4">
              <thead><tr><th>Period</th><th>Date</th><th>Journal</th><th class="text-right">Amount</th><th>Status</th><th></th></tr></thead>
              <tbody>
                <tr v-for="dep in detail.asset.depreciations || []" :key="dep.id">
                  <td>{{ dep.period ? dep.period.name : '—' }}</td>
                  <td>{{ dateLabel(dep.depreciation_date) }}</td>
                  <td>{{ dep.journal_entry ? dep.journal_entry.entry_number : '—' }}</td>
                  <td class="text-right">PKR {{ money(dep.amount) }}</td>
                  <td><v-chip x-small :color="dep.reversed_at?'grey':'success'" dark>{{ dep.reversed_at?'Reversed':'Posted' }}</v-chip></td>
                  <td class="text-right"><v-btn v-if="$can('accounting.edit') && !dep.reversed_at && detail.asset.status==='active'" x-small text color="warning" @click="openReverse(dep)">Reverse</v-btn></td>
                </tr>
                <tr v-if="!(detail.asset.depreciations || []).length"><td colspan="6" class="text-center grey--text">No depreciation posted yet.</td></tr>
              </tbody>
            </v-simple-table>
          </template>
        </v-card-text>
      </v-card>
    </v-dialog>

    <v-dialog v-model="reverseDialog" max-width="550" persistent>
      <v-card>
        <v-card-title>Reverse Depreciation</v-card-title>
        <v-card-text>
          <v-text-field v-model="reverseForm.reversal_date" type="date" outlined dense label="Reversal Date *"/>
          <v-textarea v-model="reverseForm.reason" outlined dense rows="3" label="Reason *"/>
        </v-card-text>
        <v-card-actions><v-spacer/><v-btn text @click="reverseDialog=false">Cancel</v-btn><v-btn color="warning" dark depressed :loading="reverseSaving" @click="reverseDepreciation">Reverse</v-btn></v-card-actions>
      </v-card>
    </v-dialog>
  </div>
</template>

<script>
import api from '../../../services/api'

export default {
  name:'FixedAssets',
  data(){
    return{
      tab:0,loading:false,categoryLoading:false,assetSaving:false,categorySaving:false,depreciationSaving:false,periodRunSaving:false,disposeSaving:false,reverseSaving:false,
      items:[],total:0,page:1,perPage:25,allCategories:[],categories:[],branches:[],projects:[],assetAccounts:[],accumulatedAccounts:[],expenseAccounts:[],cashAccounts:[],openPeriods:[],
      assetDialog:false,categoryDialog:false,depreciationDialog:false,periodRunDialog:false,disposeDialog:false,detailDialog:false,reverseDialog:false,
      selectedAsset:null,selectedDepreciation:null,detail:null,
      filters:{search:'',branch_id:null,project_id:null,category_id:null,status:'active'},
      acquisitionTypes:[{text:'Already Capitalized in GL',value:'existing_gl'},{text:'Cash / Bank Purchase',value:'cash_bank'}],
      assetForm:{},categoryForm:{},depreciationForm:{},periodRunForm:{},disposeForm:{},reverseForm:{},
      headers:[
        {text:'Asset',value:'asset'},{text:'Category',value:'category.name'},{text:'Scope',value:'scope'},{text:'In Service',value:'in_service_date'},
        {text:'Cost',value:'cost',align:'right'},{text:'Accum. Dep.',value:'accumulated_depreciation',align:'right'},{text:'NBV',value:'net_book_value',align:'right'},
        {text:'Status',value:'status'},{text:'',value:'actions',sortable:false}
      ],
      categoryHeaders:[
        {text:'Category',value:'name'},{text:'Asset Account',value:'asset_account'},{text:'Accumulated Dep.',value:'accumulated'},
        {text:'Depreciation Expense',value:'expense_account'},{text:'Life (Months)',value:'useful_life_months'},
        {text:'Residual %',value:'residual_value_percent'},{text:'Assets',value:'assets_count'},{text:'Status',value:'is_active'},{text:'',value:'actions',sortable:false}
      ]
    }
  },
  computed:{
    projectsForFilter(){return this.filters.branch_id?this.projects.filter(p=>Number(p.branch_id)===Number(this.filters.branch_id)):this.projects},
    projectsForAsset(){return this.assetForm.branch_id?this.projects.filter(p=>Number(p.branch_id)===Number(this.assetForm.branch_id)):this.projects},
    visibleCost(){return this.items.reduce((s,x)=>s+Number(x.cost||0),0)},
    visibleAccumulated(){return this.items.reduce((s,x)=>s+Number(x.accumulated_depreciation||0),0)},
    visibleNbv(){return this.items.reduce((s,x)=>s+Number(x.net_book_value||0),0)},
    categoryMappingsLocked(){return !!this.categoryForm.id && Number(this.categoryForm.assets_count||0)>0}
  },
  async mounted(){await this.loadOptions();await Promise.all([this.loadAssets(),this.loadCategories()])},
  methods:{
    async loadOptions(){
      const r=await api.get('/accounting/fixed-assets/options',{skipGlobalLoader:true})
      this.branches=r.data.branches||[]
      this.projects=(r.data.projects||[]).map(p=>Object.assign({},p,{display:(p.code?p.code+' · ':'')+p.name}))
      this.categories=r.data.categories||[]
      this.assetAccounts=(r.data.asset_accounts||[]).map(a=>Object.assign({},a,{display:a.code+' · '+a.name}))
      this.accumulatedAccounts=(r.data.accumulated_depreciation_accounts||[]).map(a=>Object.assign({},a,{display:a.code+' · '+a.name}))
      this.expenseAccounts=(r.data.depreciation_expense_accounts||[]).map(a=>Object.assign({},a,{display:a.code+' · '+a.name}))
      this.cashAccounts=(r.data.cash_accounts||[]).map(a=>Object.assign({},a,{display:a.code+' · '+a.name}))
      this.openPeriods=(r.data.open_periods||[]).map(p=>Object.assign({},p,{display:p.name+' · '+String(p.starts_on).slice(0,10)+' to '+String(p.ends_on).slice(0,10)}))
    },
    async loadAssets(){
      this.loading=true
      try{
        const r=await api.get('/accounting/fixed-assets',{params:{...this.filters,page:this.page,per_page:this.perPage},skipGlobalLoader:true})
        this.items=r.data.data||[];this.total=Number(r.data.total||0)
      }finally{this.loading=false}
    },
    async loadCategories(){
      this.categoryLoading=true
      try{const r=await api.get('/accounting/fixed-assets/categories',{skipGlobalLoader:true});this.allCategories=r.data||[]}
      finally{this.categoryLoading=false}
    },
    filterBranchChanged(){this.filters.project_id=null},
    blankAsset(){return{id:null,financial_locked:false,fixed_asset_category_id:null,branch_id:this.filters.branch_id||null,project_id:this.filters.project_id||null,name:'',serial_number:'',location:'',purchase_date:this.today(),in_service_date:this.today(),cost:null,residual_value:null,useful_life_months:null,acquisition_type:'existing_gl',acquisition_cash_bank_account_id:null,notes:''}},
    openAssetCreate(){this.assetForm=this.blankAsset();this.assetDialog=true},
    categoryChanged(id){
      const c=this.categories.find(x=>Number(x.id)===Number(id));if(!c)return
      if(!this.assetForm.useful_life_months)this.assetForm.useful_life_months=Number(c.useful_life_months||0)
      if(this.assetForm.cost!==null&&this.assetForm.cost!==''&&(this.assetForm.residual_value===null||this.assetForm.residual_value===''))this.assetForm.residual_value=Number(this.assetForm.cost||0)*Number(c.residual_value_percent||0)/100
    },
    assetBranchChanged(){this.assetForm.project_id=null},
    openAssetEdit(item){
      const locked=!!item.acquisition_journal_entry_id || Number(item.accumulated_depreciation||0)>0
      this.assetForm={id:item.id,financial_locked:locked,fixed_asset_category_id:item.fixed_asset_category_id,branch_id:item.branch_id,project_id:item.project_id,name:item.name,serial_number:item.serial_number||'',location:item.location||'',purchase_date:String(item.purchase_date).slice(0,10),in_service_date:String(item.in_service_date).slice(0,10),cost:Number(item.cost),residual_value:Number(item.residual_value),useful_life_months:Number(item.useful_life_months),notes:item.notes||''}
      this.assetDialog=true
    },
    async saveAsset(){
      this.assetSaving=true
      try{
        const payload={...this.assetForm};delete payload.id;delete payload.financial_locked
        if(this.assetForm.id)await api.put('/accounting/fixed-assets/'+this.assetForm.id,payload,{skipGlobalError:true})
        else await api.post('/accounting/fixed-assets',payload,{skipGlobalError:true})
        this.assetDialog=false;await Promise.all([this.loadAssets(),this.loadOptions()])
      }catch(e){this.$root.$emit('show-error',this.errorText(e,'Unable to save fixed asset.'))}
      finally{this.assetSaving=false}
    },
    blankCategory(){return{id:null,name:'',asset_account_id:null,accumulated_depreciation_account_id:null,depreciation_expense_account_id:null,useful_life_months:60,residual_value_percent:0,depreciation_method:'straight_line',is_active:true,notes:'',assets_count:0}},
    openCategoryCreate(){this.categoryForm=this.blankCategory();this.categoryDialog=true},
    openCategoryEdit(item){this.categoryForm={...this.blankCategory(),...item};this.categoryDialog=true},
    async saveCategory(){
      this.categorySaving=true
      try{
        const payload={...this.categoryForm};delete payload.id;delete payload.assets_count;delete payload.asset_account;delete payload.accumulated_depreciation_account;delete payload.depreciation_expense_account
        if(this.categoryForm.id)await api.put('/accounting/fixed-assets/categories/'+this.categoryForm.id,payload,{skipGlobalError:true})
        else await api.post('/accounting/fixed-assets/categories',payload,{skipGlobalError:true})
        this.categoryDialog=false;await Promise.all([this.loadCategories(),this.loadOptions()])
      }catch(e){this.$root.$emit('show-error',this.errorText(e,'Unable to save asset category.'))}
      finally{this.categorySaving=false}
    },
    openDepreciation(item){this.selectedAsset=item;this.depreciationForm={accounting_period_id:null};this.depreciationDialog=true},
    async postDepreciation(){
      this.depreciationSaving=true
      try{
        await api.post('/accounting/fixed-assets/'+this.selectedAsset.id+'/depreciation',this.depreciationForm,{skipGlobalError:true})
        this.depreciationDialog=false;await this.loadAssets()
      }catch(e){this.$root.$emit('show-error',this.errorText(e,'Unable to post depreciation.'))}
      finally{this.depreciationSaving=false}
    },
    openPeriodRun(){this.periodRunForm={accounting_period_id:null,branch_id:this.filters.branch_id||null,project_id:this.filters.project_id||null};this.periodRunDialog=true},
    async runPeriodDepreciation(){
      this.periodRunSaving=true
      try{
        const r=await api.post('/accounting/fixed-assets/depreciation/run',this.periodRunForm,{skipGlobalError:true})
        this.periodRunDialog=false;await this.loadAssets()
        this.$root.$emit('show-success',r.data.message)
      }catch(e){this.$root.$emit('show-error',this.errorText(e,'Unable to run period depreciation.'))}
      finally{this.periodRunSaving=false}
    },
    openDispose(item){this.selectedAsset=item;this.disposeForm={disposed_on:this.today(),disposal_proceeds:0,disposal_cash_bank_account_id:null,disposal_reason:''};this.disposeDialog=true},
    async disposeAsset(){
      this.disposeSaving=true
      try{
        await api.post('/accounting/fixed-assets/'+this.selectedAsset.id+'/dispose',this.disposeForm,{skipGlobalError:true})
        this.disposeDialog=false;await this.loadAssets()
      }catch(e){this.$root.$emit('show-error',this.errorText(e,'Unable to dispose fixed asset.'))}
      finally{this.disposeSaving=false}
    },
    async openDetail(item){
      try{const r=await api.get('/accounting/fixed-assets/'+item.id,{skipGlobalLoader:true});this.detail=r.data;this.detailDialog=true}
      catch(e){this.$root.$emit('show-error',this.errorText(e,'Unable to load fixed asset.'))}
    },
    openReverse(dep){this.selectedDepreciation=dep;this.reverseForm={reversal_date:this.today(),reason:''};this.reverseDialog=true},
    async reverseDepreciation(){
      this.reverseSaving=true
      try{
        await api.post('/accounting/fixed-assets/depreciations/'+this.selectedDepreciation.id+'/reverse',this.reverseForm,{skipGlobalError:true})
        this.reverseDialog=false
        if(this.detail&&this.detail.asset)await this.openDetail(this.detail.asset)
        await this.loadAssets()
      }catch(e){this.$root.$emit('show-error',this.errorText(e,'Unable to reverse depreciation.'))}
      finally{this.reverseSaving=false}
    },
    accountLabel(a){return a?a.code+' · '+a.name:'—'},
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
