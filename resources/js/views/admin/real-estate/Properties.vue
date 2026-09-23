<template>
<div class="properties-page">
  <v-card flat class="hero pa-5 mb-4">
    <div class="d-flex align-center flex-wrap">
      <div>
        <div class="text-overline">REAL ESTATE INVENTORY</div>
        <h1 class="text-h5 font-weight-bold">Properties</h1>
        <div class="grey--text">Browse availability, pricing, projects and property status in one place.</div>
      </div>
      <v-spacer/>
      <v-btn v-if="$can('properties.create')" color="#165134" dark depressed class="rounded-lg" to="/admin/properties/create">
        <v-icon left>mdi-home-plus</v-icon>Add Property
      </v-btn>
    </div>
  </v-card>

  <v-row class="mb-1">
    <v-col v-for="card in summaryCards" :key="card.key" cols="6" sm="4" md="3" lg="2">
      <v-card flat class="summary-card pa-3" :class="{'summary-active':filters.status===card.status}" @click="applyStatus(card.status)">
        <div class="d-flex align-center">
          <v-avatar size="40" :class="card.softClass" class="mr-3"><v-icon :color="card.color">{{card.icon}}</v-icon></v-avatar>
          <div class="min-width-0">
            <div class="caption grey--text">{{card.label}}</div>
            <div class="text-h6 font-weight-bold">{{summary[card.key] || 0}}</div>
          </div>
        </div>
      </v-card>
    </v-col>
  </v-row>

  <v-card flat class="filter-card pa-4 mb-4">
    <v-row dense align="center">
      <v-col cols="12" md="3"><v-select v-model="filters.project_id" :items="projects" item-text="name" item-value="id" label="Project" outlined dense hide-details clearable prepend-inner-icon="mdi-office-building" @change="projectChanged"/></v-col>
      <v-col cols="12" md="2"><v-select v-model="filters.block_id" :items="filteredBlocks" item-text="name" item-value="id" label="Block" outlined dense hide-details clearable prepend-inner-icon="mdi-city" :disabled="!filters.project_id" @change="loadData"/></v-col>
      <v-col cols="12" md="3"><v-text-field v-model="filters.search" label="Search property" placeholder="A-101, address..." outlined dense hide-details clearable prepend-inner-icon="mdi-magnify" @keyup.enter="loadData" @click:clear="loadData"/></v-col>
      <v-col cols="12" md="2"><v-select v-model="filters.property_type" :items="propertyTypes" item-text="text" item-value="value" label="Type" outlined dense hide-details clearable/></v-col>
      <v-col cols="12" md="2"><v-select v-model="filters.status" :items="statuses" item-text="text" item-value="value" label="Status" outlined dense hide-details clearable @change="loadData"/></v-col>
      <v-col cols="12" sm="6" md="2"><v-text-field v-model="filters.min_price" label="Min price" type="number" outlined dense hide-details min="0"/></v-col>
      <v-col cols="12" sm="6" md="2"><v-text-field v-model="filters.max_price" label="Max price" type="number" outlined dense hide-details min="0"/></v-col>
      <v-col cols="12" md="8" class="d-flex align-center justify-end flex-wrap filter-actions">
        <v-btn text color="grey darken-1" @click="resetFilters"><v-icon left>mdi-filter-remove</v-icon>Clear</v-btn>
        <v-btn color="#165134" dark depressed @click="loadData"><v-icon left>mdi-filter</v-icon>Apply Filters</v-btn>
      </v-col>
    </v-row>
  </v-card>

  <div class="d-flex align-center mb-3">
    <div>
      <div class="text-h6 font-weight-bold">Property Inventory</div>
      <div class="caption grey--text">{{properties.length}} properties shown</div>
    </div>
    <v-spacer/>
    <v-btn-toggle v-model="viewMode" dense mandatory class="view-toggle">
      <v-btn value="grid" title="Grid view"><v-icon>mdi-view-grid-outline</v-icon></v-btn>
      <v-btn value="list" title="List view"><v-icon>mdi-view-list-outline</v-icon></v-btn>
    </v-btn-toggle>
  </div>

  <v-card v-if="loading" flat class="state-card pa-12 text-center">
    <v-progress-circular indeterminate color="#165134" size="46"/>
    <div class="grey--text mt-3">Loading property inventory...</div>
  </v-card>

  <v-card v-else-if="properties.length===0" flat class="state-card pa-12 text-center">
    <v-icon size="64" color="grey lighten-1">mdi-home-search-outline</v-icon>
    <div class="text-h6 font-weight-bold mt-3">No properties found</div>
    <div class="grey--text mb-4">Try clearing or changing your filters.</div>
    <v-btn outlined color="#165134" @click="resetFilters">Clear Filters</v-btn>
  </v-card>

  <v-row v-else-if="viewMode==='grid'">
    <v-col v-for="property in properties" :key="property.id" cols="12" sm="6" md="4" lg="3">
      <v-card flat class="property-card fill-height" @click="viewProperty(property)">
        <div class="image-wrap">
          <v-img v-if="primaryImage(property)" :src="primaryImage(property)" height="190" class="grey lighten-3"/>
          <div v-else class="image-placeholder"><v-icon size="54" color="grey lighten-1">mdi-home-city-outline</v-icon></div>
          <v-chip x-small dark :color="statusColor(property.status)" class="status-chip">{{formatStatus(property.status)}}</v-chip>
          <v-chip v-if="property.images && property.images.length" x-small class="photo-chip"><v-icon x-small left>mdi-camera</v-icon>{{property.images.length}}</v-chip>
        </div>
        <v-card-text class="pa-4">
          <div class="d-flex align-start">
            <div class="min-width-0">
              <div class="text-h6 font-weight-bold text-truncate">{{property.property_number || 'Property'}}</div>
              <div class="caption grey--text text-truncate">{{property.project ? property.project.name : 'No project'}}<span v-if="property.block"> · {{property.block.name}}</span></div>
            </div>
            <v-spacer/>
            <v-icon :color="statusColor(property.status)">mdi-home</v-icon>
          </div>
          <div class="property-meta mt-4">
            <span><v-icon small>mdi-shape-outline</v-icon>{{formatPropertyType(property.property_type)}}</span>
            <span><v-icon small>mdi-ruler-square</v-icon>{{property.size || '—'}} {{property.size_unit || ''}}</span>
          </div>
          <div class="price mt-4">PKR {{formatPrice(property.price)}}</div>
          <div v-if="property.assigned_agent" class="caption grey--text mt-1"><v-icon x-small>mdi-account-tie</v-icon> {{property.assigned_agent.name}}</div>
        </v-card-text>
        <v-divider/>
        <v-card-actions>
          <v-btn text small color="#165134" @click.stop="viewProperty(property)">View Details</v-btn>
          <v-spacer/>
          <v-btn v-if="$can('properties.edit')" icon small title="Edit property" @click.stop="editProperty(property)"><v-icon small>mdi-pencil-outline</v-icon></v-btn>
          <v-btn v-if="$can('properties.delete')" icon small color="error" title="Delete property" @click.stop="deleteProperty(property)"><v-icon small>mdi-delete-outline</v-icon></v-btn>
        </v-card-actions>
      </v-card>
    </v-col>
  </v-row>

  <v-card v-else flat class="table-card">
    <v-data-table :headers="tableHeaders" :items="properties" :items-per-page="itemsPerPage" item-key="id" hide-default-footer>
      <template v-slot:item.property_number="{item}">
        <div class="d-flex align-center py-2 property-link" @click="viewProperty(item)">
          <v-avatar tile size="44" class="mr-3 rounded-lg">
            <v-img v-if="primaryImage(item)" :src="primaryImage(item)"/>
            <v-icon v-else color="grey">mdi-home-outline</v-icon>
          </v-avatar>
          <div><div class="font-weight-bold primary--text">{{item.property_number}}</div><div class="caption grey--text">{{formatPropertyType(item.property_type)}}</div></div>
        </div>
      </template>
      <template v-slot:item.project="{item}"><div>{{item.project ? item.project.name : '—'}}</div><div class="caption grey--text">{{item.block ? item.block.name : ''}}</div></template>
      <template v-slot:item.size="{item}">{{item.size || '—'}} {{item.size_unit || ''}}</template>
      <template v-slot:item.price="{item}"><span class="font-weight-medium">PKR {{formatPrice(item.price)}}</span></template>
      <template v-slot:item.status="{item}"><v-chip x-small dark :color="statusColor(item.status)">{{formatStatus(item.status)}}</v-chip></template>
      <template v-slot:item.actions="{item}">
        <v-btn icon small title="View" @click="viewProperty(item)"><v-icon small>mdi-eye-outline</v-icon></v-btn>
        <v-btn v-if="$can('properties.edit')" icon small title="Edit" @click="editProperty(item)"><v-icon small>mdi-pencil-outline</v-icon></v-btn>
        <v-btn v-if="$can('properties.delete')" icon small color="error" title="Delete" @click="deleteProperty(item)"><v-icon small>mdi-delete-outline</v-icon></v-btn>
      </template>
    </v-data-table>
  </v-card>

  <v-dialog v-model="deleteDialog" max-width="520" persistent>
    <v-card>
      <v-card-title>Delete Property</v-card-title>
      <v-card-text><v-alert type="warning" outlined dense>Delete <strong>{{deleteItem && deleteItem.property_number}}</strong>? Properties with booking or meaningful status history cannot be deleted.</v-alert></v-card-text>
      <v-card-actions><v-spacer/><v-btn text :disabled="deleting" @click="deleteDialog=false">Cancel</v-btn><v-btn color="error" @click="confirmDelete">Delete Property</v-btn></v-card-actions>
    </v-card>
  </v-dialog>
</div>
</template>
<script>
import api from '../../../services/api'
export default{
 name:'Properties',
 data(){return{loading:false,deleteDialog:false,deleting:false,deleteItem:null,viewMode:localStorage.getItem('zeerak_property_view')||'grid',projects:[],blocks:[],properties:[],itemsPerPage:100,propertyTypes:[{text:'Residential',value:'residential'},{text:'Commercial',value:'commercial'},{text:'Industrial',value:'industrial'}],summary:{total:0,available:0,reserved:0,booked:0,sold:0,under_construction:0,rented:0,unavailable:0},filters:{project_id:null,block_id:null,search:'',status:null,property_type:null,min_price:null,max_price:null},statuses:[{text:'Available',value:'available'},{text:'Reserved',value:'reserved'},{text:'Booked',value:'booked'},{text:'Sold',value:'sold'},{text:'Under Construction',value:'under_construction'},{text:'Rented',value:'rented'},{text:'Unavailable',value:'unavailable'},{text:'Cancelled',value:'cancelled'}],tableHeaders:[{text:'Property',value:'property_number'},{text:'Project / Block',value:'project'},{text:'Size',value:'size'},{text:'Price',value:'price'},{text:'Status',value:'status'},{text:'Actions',value:'actions',sortable:false,align:'right'}]}},
 computed:{
  summaryCards(){return[
   {key:'total',status:null,label:'Total',icon:'mdi-home-group',color:'#165134',softClass:'soft-green'},
   {key:'available',status:'available',label:'Available',icon:'mdi-home-check-outline',color:'green darken-2',softClass:'soft-green'},
   {key:'reserved',status:'reserved',label:'Reserved',icon:'mdi-home-clock-outline',color:'orange darken-2',softClass:'soft-amber'},
   {key:'booked',status:'booked',label:'Booked',icon:'mdi-calendar-check-outline',color:'blue',softClass:'soft-blue'},
   {key:'sold',status:'sold',label:'Sold',icon:'mdi-home-lock-outline',color:'red',softClass:'soft-red'},
   {key:'under_construction',status:'under_construction',label:'Construction',icon:'mdi-hard-hat',color:'purple',softClass:'soft-purple'}]},
  filteredBlocks(){if(!this.filters.project_id)return[];return this.blocks.filter(b=>Number(b.project_id)===Number(this.filters.project_id))}
 },
 watch:{viewMode(v){localStorage.setItem('zeerak_property_view',v)}},
 mounted(){this.applyRouteFilters();this.loadProjects();this.loadBlocks();this.loadData()},
 methods:{
  applyRouteFilters(){if(this.$route.query.project_id)this.filters.project_id=Number(this.$route.query.project_id);if(this.$route.query.block_id)this.filters.block_id=Number(this.$route.query.block_id);if(this.$route.query.status)this.filters.status=this.$route.query.status},
  async loadProjects(){try{const r=await api.get('/projects',{params:{per_page:100}});this.projects=r.data.data||[]}catch(e){}},
  async loadBlocks(){try{const r=await api.get('/project-blocks',{params:{per_page:100}});this.blocks=r.data.data||[]}catch(e){}},
  async loadData(){this.loading=true;try{const params={per_page:this.itemsPerPage};Object.keys(this.filters).forEach(k=>{const v=this.filters[k];if(v!==null&&v!==''&&typeof v!=='undefined')params[k]=v});const r=await api.get('/properties/inventory',{params:params});this.properties=(r.data.properties&&r.data.properties.data)||[];this.summary=r.data.summary||this.summary}catch(e){this.$root.$emit('show-error',(e.response&&e.response.data&&e.response.data.message)||'Unable to load properties.')}finally{this.loading=false}},
  applyStatus(status){this.filters.status=this.filters.status===status&&status!==null?null:status;this.loadData()},
  projectChanged(){this.filters.block_id=null;this.loadData()},
  resetFilters(){this.filters={project_id:null,block_id:null,search:'',status:null,property_type:null,min_price:null,max_price:null};if(this.$route.query&&Object.keys(this.$route.query).length)this.$router.replace({name:'properties'}).catch(()=>{});this.loadData()},
  primaryImage(p){if(!p.images||!p.images.length)return null;const x=p.images.find(i=>i.is_primary)||p.images[0];return x&&x.url?x.url:null},
  viewProperty(p){if(this.$can('properties.view'))this.$router.push('/admin/properties/'+p.id)},
  editProperty(p){if(this.$can('properties.edit'))this.$router.push('/admin/properties/'+p.id+'/edit')},
  deleteProperty(p){if(!this.$can('properties.delete'))return;this.deleteItem=p;this.deleteDialog=true},
  async confirmDelete(){if(!this.deleteItem)return;this.deleting=true;try{await api.delete('/properties/'+this.deleteItem.id);this.deleteDialog=false;this.deleteItem=null;await this.loadData();this.$root.$emit('show-success','Property deleted successfully.')}catch(e){this.$root.$emit('show-error',(e.response&&e.response.data&&e.response.data.message)||'Unable to delete property.')}finally{this.deleting=false}},
  formatStatus(s){const x=this.statuses.find(i=>i.value===s);return x?x.text:s},
  formatPropertyType(s){return s?String(s).replace(/_/g,' ').replace(/\b\w/g,function(x){return x.toUpperCase()}):'—'},
  formatPrice(v){return new Intl.NumberFormat('en-PK',{maximumFractionDigits:0}).format(Number(v||0))},
  statusColor(s){return({available:'green',reserved:'orange',booked:'blue',sold:'red',under_construction:'purple',rented:'teal',unavailable:'grey',cancelled:'black'})[s]||'grey'}
 }}
</script>
<style scoped>
.properties-page{width:100%}.hero{border-left:4px solid #165134}.summary-card,.filter-card,.table-card,.state-card,.property-card{border:1px solid rgba(22,81,52,.08);border-radius:15px!important}.summary-card{cursor:pointer;transition:.2s}.summary-card:hover,.summary-active{transform:translateY(-2px);box-shadow:0 8px 22px rgba(20,50,35,.08)!important}.summary-active{border-color:#165134!important}.property-card{overflow:hidden;cursor:pointer;transition:.2s}.property-card:hover{transform:translateY(-3px);box-shadow:0 12px 28px rgba(20,50,35,.10)!important}.image-wrap{height:190px;position:relative;background:#f2f4f3}.image-placeholder{height:190px;display:flex;align-items:center;justify-content:center}.status-chip{position:absolute;top:12px;left:12px}.photo-chip{position:absolute;top:12px;right:12px;background:rgba(255,255,255,.92)!important}.property-meta{display:flex;gap:14px;flex-wrap:wrap;color:#66706a;font-size:12px}.property-meta span{display:flex;align-items:center;gap:4px}.price{font-size:18px;font-weight:700;color:#165134}.filter-actions{gap:8px}.view-toggle{border:1px solid rgba(22,81,52,.12)}.property-link{cursor:pointer}.soft-green{background:#e8f5ee!important}.soft-amber{background:#fff5df!important}.soft-blue{background:#eaf2ff!important}.soft-red{background:#fdecec!important}.soft-purple{background:#f3eefe!important}.min-width-0{min-width:0}
</style>