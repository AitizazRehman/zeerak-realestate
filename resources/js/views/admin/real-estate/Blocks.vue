<template>
<div class="blocks-page">
  <v-card flat class="hero pa-5 mb-4">
    <div class="d-flex align-center flex-wrap">
      <div>
        <div class="text-overline">REAL ESTATE STRUCTURE</div>
        <h1 class="text-h5 font-weight-bold">Project Blocks</h1>
        <div class="grey--text">Organize projects into blocks, phases and sellable inventory groups.</div>
      </div>
      <v-spacer/>
      <v-btn v-if="$can('projects.create')" color="#165134" dark depressed class="rounded-lg" @click="openCreate">
        <v-icon left>mdi-plus-box-multiple-outline</v-icon>Add Block
      </v-btn>
    </div>
  </v-card>

  <v-row class="mb-1">
    <v-col cols="12" sm="4">
      <v-card flat class="summary-card pa-4">
        <div class="d-flex align-center">
          <v-avatar size="44" class="soft-green mr-3"><v-icon color="#165134">mdi-city-variant-outline</v-icon></v-avatar>
          <div><div class="caption grey--text">Total Blocks</div><div class="text-h6 font-weight-bold">{{total}}</div></div>
        </div>
      </v-card>
    </v-col>
    <v-col cols="12" sm="4">
      <v-card flat class="summary-card pa-4">
        <div class="d-flex align-center">
          <v-avatar size="44" class="soft-blue mr-3"><v-icon color="blue">mdi-toggle-switch</v-icon></v-avatar>
          <div><div class="caption grey--text">Active Shown</div><div class="text-h6 font-weight-bold">{{activeShown}}</div></div>
        </div>
      </v-card>
    </v-col>
    <v-col cols="12" sm="4">
      <v-card flat class="summary-card pa-4">
        <div class="d-flex align-center">
          <v-avatar size="44" class="soft-amber mr-3"><v-icon color="orange darken-2">mdi-home-group</v-icon></v-avatar>
          <div><div class="caption grey--text">Units Shown</div><div class="text-h6 font-weight-bold">{{unitsShown}}</div></div>
        </div>
      </v-card>
    </v-col>
  </v-row>

  <v-card flat class="filter-card pa-4 mb-4">
    <v-row dense align="center">
      <v-col cols="12" md="5"><v-select v-model="filters.project_id" :items="projects" item-text="name" item-value="id" label="Project" outlined dense hide-details clearable prepend-inner-icon="mdi-office-building" @change="projectFilterChanged"/></v-col>
      <v-col cols="12" md="5"><v-text-field v-model="filters.search" label="Search block name or code" prepend-inner-icon="mdi-magnify" outlined dense hide-details clearable @keyup.enter="loadBlocks" @click:clear="loadBlocks"/></v-col>
      <v-col cols="12" md="2" class="text-md-right"><v-btn color="#165134" dark depressed @click="loadBlocks"><v-icon left>mdi-magnify</v-icon>Search</v-btn></v-col>
    </v-row>
  </v-card>

  <v-card flat class="table-card">
    <v-data-table :headers="headers" :items="blocks" :server-items-length="total" :options.sync="options" @update:options="loadBlocks">
      <template v-slot:item.name="{item}">
        <div class="d-flex align-center py-2">
          <v-avatar size="38" class="soft-green mr-3"><v-icon color="#165134">mdi-view-grid-plus-outline</v-icon></v-avatar>
          <div><div class="font-weight-bold">{{item.name}}</div><div class="caption grey--text">{{item.code}}</div></div>
        </div>
      </template>
      <template v-slot:item.project="{item}"><div class="font-weight-medium">{{item.project ? item.project.name : '—'}}</div></template>
      <template v-slot:item.total_area="{item}">{{item.total_area || 0}} {{item.area_unit || 'Marla'}}</template>
      <template v-slot:item.total_units="{item}"><span class="font-weight-medium">{{Number(item.total_units||0).toLocaleString()}}</span></template>
      <template v-slot:item.is_active="{item}"><v-chip x-small dark :color="item.is_active?'success':'grey'">{{item.is_active?'Active':'Inactive'}}</v-chip></template>
      <template v-slot:item.actions="{item}">
        <v-btn v-if="$can('properties.view')" icon small title="View block properties" @click="viewProperties(item)"><v-icon small>mdi-home-group</v-icon></v-btn>
        <v-btn v-if="$can('projects.edit')" icon small title="Edit block" @click="editBlock(item)"><v-icon small>mdi-pencil-outline</v-icon></v-btn>
        <v-btn v-if="$can('projects.delete')" icon small color="error" title="Delete block" @click="openDelete(item)"><v-icon small>mdi-delete-outline</v-icon></v-btn>
      </template>
      <template v-slot:no-data><div class="pa-10 text-center grey--text"><v-icon size="54" color="grey lighten-1">mdi-city-variant-outline</v-icon><div class="mt-2">No blocks found.</div></div></template>
    </v-data-table>
  </v-card>

  <v-dialog v-model="dialog" max-width="680" persistent>
    <v-card>
      <v-card-title>
        <div><div class="text-h6 font-weight-bold">{{editing?'Edit Block':'Add Block'}}</div><div class="caption grey--text">{{editing?'Update block information.':'Create a new block within a project.'}}</div></div>
        <v-spacer/><v-btn icon @click="dialog=false"><v-icon>mdi-close</v-icon></v-btn>
      </v-card-title>
      <v-divider/>
      <v-card-text class="pt-5">
        <v-form ref="form">
          <v-row>
            <v-col cols="12"><v-select v-model="form.project_id" :items="projects" item-text="name" item-value="id" label="Project *" outlined dense :rules="[required]" :disabled="!canSave"/></v-col>
            <v-col cols="12" md="6"><v-text-field v-model="form.name" label="Block Name *" outlined dense :rules="[required]" :disabled="!canSave"/></v-col>
            <v-col cols="12" md="6"><v-text-field v-model="form.code" label="Block Code *" outlined dense :rules="[required]" :disabled="!canSave"/></v-col>
            <v-col cols="12" md="8"><v-text-field v-model="form.total_area" label="Total Area" type="number" min="0" outlined dense :disabled="!canSave"/></v-col>
            <v-col cols="12" md="4"><v-select v-model="form.area_unit" :items="areaUnits" label="Area Unit" outlined dense :disabled="!canSave"/></v-col>
            <v-col cols="12" md="6"><v-text-field v-model="form.total_units" label="Total Units" type="number" min="0" outlined dense :disabled="!canSave"/></v-col>
            <v-col cols="12" md="6" class="d-flex align-center"><v-switch v-model="form.is_active" label="Active block" color="#165134" :disabled="!canSave"/></v-col>
            <v-col cols="12"><v-textarea v-model="form.description" label="Description" outlined dense rows="3" :disabled="!canSave"/></v-col>
          </v-row>
        </v-form>
      </v-card-text>
      <v-card-actions class="px-6 pb-5"><v-spacer/><v-btn text @click="dialog=false">Cancel</v-btn><v-btn v-if="canSave" color="#165134" dark depressed @click="saveBlock">Save Block</v-btn></v-card-actions>
    </v-card>
  </v-dialog>

  <v-dialog v-model="deleteDialog" max-width="520" persistent>
    <v-card>
      <v-card-title>Delete Block</v-card-title>
      <v-card-text><v-alert type="warning" outlined dense>Delete <strong>{{deleteItem ? deleteItem.name : ''}}</strong>? Blocks containing properties are protected and cannot be deleted.</v-alert></v-card-text>
      <v-card-actions><v-spacer/><v-btn text :disabled="deleting" @click="deleteDialog=false">Cancel</v-btn><v-btn color="error" @click="confirmDelete">Delete Block</v-btn></v-card-actions>
    </v-card>
  </v-dialog>
</div>
</template>

<script>
import api from '../../../services/api'
export default{
 name:'Blocks',
 data(){return{loading:false,saving:false,deleting:false,blocks:[],projects:[],total:0,dialog:false,deleteDialog:false,deleteItem:null,editing:false,selectedId:null,options:{page:1,itemsPerPage:20},filters:{project_id:null,search:''},areaUnits:['Marla','Kanal','Sq Ft','Sq Yard','Acre'],form:{project_id:null,name:'',code:'',description:'',total_area:null,area_unit:'Marla',total_units:0,is_active:true},headers:[{text:'Block',value:'name'},{text:'Project',value:'project'},{text:'Area',value:'total_area'},{text:'Units',value:'total_units'},{text:'Status',value:'is_active'},{text:'Actions',value:'actions',sortable:false,align:'right'}]}},
 computed:{canSave(){return this.editing?this.$can('projects.edit'):this.$can('projects.create')},activeShown(){return this.blocks.filter(x=>x.is_active).length},unitsShown(){return this.blocks.reduce((n,x)=>n+Number(x.total_units||0),0)}},
 async mounted(){if(this.$route.query.project_id)this.filters.project_id=Number(this.$route.query.project_id);await this.loadProjects();await this.loadBlocks();if(this.$route.query.create==='1'&&this.$can('projects.create'))this.openCreate()},
 methods:{
  blank(projectId){return{project_id:projectId||null,name:'',code:'',description:'',total_area:null,area_unit:'Marla',total_units:0,is_active:true}},
  required(v){return!!v||'This field is required'},
  async loadProjects(){try{const r=await api.get('/projects',{params:{per_page:100,is_active:1}});this.projects=r.data.data||[]}catch(e){this.$root.$emit('show-error','Unable to load projects.')}},
  async loadBlocks(){this.loading=true;try{const r=await api.get('/project-blocks',{params:{page:this.options.page,per_page:this.options.itemsPerPage,project_id:this.filters.project_id,search:this.filters.search}});this.blocks=r.data.data||[];this.total=r.data.total||0}catch(e){this.$root.$emit('show-error',(e.response&&e.response.data&&e.response.data.message)||'Unable to load blocks.')}finally{this.loading=false}},
  projectFilterChanged(){this.options.page=1;this.loadBlocks()},
  openCreate(){if(!this.$can('projects.create'))return;this.editing=false;this.selectedId=null;this.form=this.blank(this.filters ? this.filters.project_id : null);this.dialog=true},
  editBlock(block){if(!this.$can('projects.edit'))return;this.editing=true;this.selectedId=block.id;this.form={project_id:block.project_id,name:block.name,code:block.code,description:block.description||'',total_area:block.total_area,area_unit:block.area_unit||'Marla',total_units:block.total_units||0,is_active:!!block.is_active};this.dialog=true},
  async saveBlock(){if(!this.canSave||!this.$refs.form.validate())return;this.saving=true;try{const r=this.editing?await api.put('/project-blocks/'+this.selectedId,this.form):await api.post('/project-blocks',this.form);this.dialog=false;await this.loadBlocks();this.$root.$emit('show-success',(r.data&&r.data.message)||'Block saved successfully.')}catch(e){this.$root.$emit('show-error',(e.response&&e.response.data&&e.response.data.message)||'Unable to save block.')}finally{this.saving=false}},
  openDelete(block){if(!this.$can('projects.delete'))return;this.deleteItem=block;this.deleteDialog=true},
  async confirmDelete(){if(!this.deleteItem)return;this.deleting=true;try{const r=await api.delete('/project-blocks/'+this.deleteItem.id);this.deleteDialog=false;this.deleteItem=null;await this.loadBlocks();this.$root.$emit('show-success',(r.data&&r.data.message)||'Block deleted successfully.')}catch(e){this.$root.$emit('show-error',(e.response&&e.response.data&&e.response.data.message)||'Unable to delete block.')}finally{this.deleting=false}},
  viewProperties(block){if(!this.$can('properties.view'))return;this.$router.push({name:'properties',query:{project_id:block.project_id,block_id:block.id}})}
 }}
</script>

<style scoped>
.blocks-page{width:100%}.hero{border-left:4px solid #165134}.summary-card,.filter-card,.table-card{border:1px solid rgba(22,81,52,.08);border-radius:15px!important}.soft-green{background:#e8f5ee!important}.soft-blue{background:#eaf2ff!important}.soft-amber{background:#fff5df!important}
</style>