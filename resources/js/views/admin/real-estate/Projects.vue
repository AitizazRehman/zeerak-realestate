<template>
<div class="projects-page">
  <v-card flat class="hero pa-5 mb-4">
    <div class="d-flex align-center flex-wrap">
      <div>
        <div class="text-overline">REAL ESTATE</div>
        <h1 class="text-h5 font-weight-bold">Projects</h1>
        <div class="grey--text">Manage development projects, progress and inventory access.</div>
      </div>
      <v-spacer/>
      <v-btn v-if="$can('projects.create')" color="#165134" dark depressed class="rounded-lg" to="/admin/projects/create">
        <v-icon left>mdi-office-building-plus-outline</v-icon>Add Project
      </v-btn>
    </div>
  </v-card>

  <v-row class="mb-1">
    <v-col cols="12" sm="4">
      <v-card flat class="summary-card pa-4">
        <div class="d-flex align-center">
          <v-avatar size="44" class="soft-green mr-3"><v-icon color="#165134">mdi-office-building</v-icon></v-avatar>
          <div><div class="caption grey--text">Total Projects</div><div class="text-h6 font-weight-bold">{{total}}</div></div>
        </div>
      </v-card>
    </v-col>
    <v-col cols="12" sm="4">
      <v-card flat class="summary-card pa-4">
        <div class="d-flex align-center">
          <v-avatar size="44" class="soft-blue mr-3"><v-icon color="blue">mdi-progress-wrench</v-icon></v-avatar>
          <div><div class="caption grey--text">Under Construction Shown</div><div class="text-h6 font-weight-bold">{{constructionShown}}</div></div>
        </div>
      </v-card>
    </v-col>
    <v-col cols="12" sm="4">
      <v-card flat class="summary-card pa-4">
        <div class="d-flex align-center">
          <v-avatar size="44" class="soft-grey mr-3"><v-icon color="grey darken-1">mdi-check-decagram-outline</v-icon></v-avatar>
          <div><div class="caption grey--text">Completed Shown</div><div class="text-h6 font-weight-bold">{{completedShown}}</div></div>
        </div>
      </v-card>
    </v-col>
  </v-row>

  <v-card flat class="filter-card pa-4 mb-4">
    <v-row dense align="center">
      <v-col cols="12" md="6">
        <v-text-field v-model="filters.search" label="Search projects" prepend-inner-icon="mdi-magnify" outlined dense hide-details clearable @keyup.enter="loadProjects" @click:clear="loadProjects"/>
      </v-col>
      <v-col cols="12" md="3">
        <v-select v-model="filters.status" :items="statusOptions" item-text="text" item-value="value" label="Status" outlined dense hide-details clearable @change="loadProjects"/>
      </v-col>
      <v-col cols="12" md="3" class="text-md-right">
        <v-btn text color="grey darken-1" @click="resetFilters"><v-icon left>mdi-filter-remove</v-icon>Clear</v-btn>
        <v-btn color="#165134" dark depressed :loading="loading" @click="loadProjects"><v-icon left>mdi-filter</v-icon>Apply</v-btn>
      </v-col>
    </v-row>
  </v-card>

  <v-card flat class="table-card">
    <v-data-table :headers="headers" :items="projects" :loading="loading" :server-items-length="total" :options.sync="options" @update:options="loadProjects">
      <template v-slot:item.name="{item}">
        <div class="d-flex align-center py-2 project-link" @click="viewProject(item)">
          <v-avatar size="40" class="soft-green mr-3"><v-icon color="#165134">mdi-office-building-outline</v-icon></v-avatar>
          <div>
            <div class="font-weight-bold primary--text">{{item.name}}</div>
            <div class="caption grey--text">{{item.code}}</div>
          </div>
        </div>
      </template>

      <template v-slot:item.branch="{item}">
        <div>{{item.branch ? item.branch.name : '—'}}</div>
        <div class="caption grey--text">{{item.city || ''}}</div>
      </template>

      <template v-slot:item.status="{item}">
        <v-chip x-small dark :color="statusColor(item.status)">{{formatStatus(item.status)}}</v-chip>
      </template>

      <template v-slot:item.construction_progress="{item}">
        <div class="progress-wrap">
          <v-progress-linear :value="Number(item.construction_progress||0)" rounded height="8" color="#165134"/>
          <div class="caption grey--text mt-1">{{Number(item.construction_progress||0)}}%</div>
        </div>
      </template>

      <template v-slot:item.is_active="{item}">
        <v-chip x-small dark :color="item.is_active?'success':'grey'">{{item.is_active?'Active':'Inactive'}}</v-chip>
      </template>

      <template v-slot:item.actions="{item}">
        <v-btn v-if="$can('projects.view')" icon small title="Project details" @click="viewProject(item)"><v-icon small>mdi-eye-outline</v-icon></v-btn>
        <v-btn v-if="$can('properties.view')" icon small title="Project properties" @click="viewProperties(item)"><v-icon small>mdi-home-group</v-icon></v-btn>
        <v-btn v-if="$can('projects.edit')" icon small title="Edit project" @click="editProject(item)"><v-icon small>mdi-pencil-outline</v-icon></v-btn>
        <v-btn v-if="$can('projects.delete')" icon small color="error" title="Delete project" @click="deleteProject(item)"><v-icon small>mdi-delete-outline</v-icon></v-btn>
      </template>

      <template v-slot:no-data>
        <div class="pa-10 text-center grey--text">
          <v-icon size="54" color="grey lighten-1">mdi-office-building-marker-outline</v-icon>
          <div class="mt-2">No projects found.</div>
        </div>
      </template>
    </v-data-table>
  </v-card>

  <v-dialog v-model="deleteDialog" max-width="520" persistent>
    <v-card>
      <v-card-title>Delete Project</v-card-title>
      <v-card-text>
        <v-alert type="warning" outlined dense>
          Delete <strong>{{selectedProject ? selectedProject.name : ''}}</strong>? Projects containing blocks or property inventory are protected and cannot be deleted.
        </v-alert>
      </v-card-text>
      <v-card-actions>
        <v-spacer/>
        <v-btn text :disabled="deleting" @click="deleteDialog=false">Cancel</v-btn>
        <v-btn v-if="$can('projects.delete')" color="error" depressed :loading="deleting" @click="confirmDelete">Delete Project</v-btn>
      </v-card-actions>
    </v-card>
  </v-dialog>
</div>
</template>

<script>
import api from '../../../services/api'
export default{
 name:'Projects',
 data(){return{loading:false,deleting:false,projects:[],total:0,options:{page:1,itemsPerPage:15,sortBy:[],sortDesc:[]},filters:{search:'',status:null},statusOptions:[{text:'Planning',value:'planning'},{text:'Approved',value:'approved'},{text:'Active',value:'active'},{text:'Under Construction',value:'under_construction'},{text:'Completed',value:'completed'},{text:'On Hold',value:'on_hold'},{text:'Cancelled',value:'cancelled'}],headers:[{text:'Project',value:'name'},{text:'Branch / City',value:'branch'},{text:'Status',value:'status'},{text:'Progress',value:'construction_progress'},{text:'State',value:'is_active',sortable:false},{text:'Actions',value:'actions',sortable:false,align:'right'}],deleteDialog:false,selectedProject:null}},
 computed:{constructionShown(){return this.projects.filter(x=>x.status==='under_construction').length},completedShown(){return this.projects.filter(x=>x.status==='completed').length}},
 mounted(){this.loadProjects()},
 methods:{
  async loadProjects(){this.loading=true;try{const r=await api.get('/projects',{params:{page:this.options.page,per_page:this.options.itemsPerPage,search:this.filters.search,status:this.filters.status}});this.projects=r.data.data||[];this.total=r.data.total||0}catch(e){this.$root.$emit('show-error',(e.response&&e.response.data&&e.response.data.message)||'Unable to load projects.')}finally{this.loading=false}},
  resetFilters(){this.filters={search:'',status:null};this.options.page=1;this.loadProjects()},
  viewProject(project){this.$router.push({name:'project-details',params:{id:project.id}})},
  viewProperties(project){this.$router.push({name:'properties',query:{project_id:project.id}})},
  editProject(project){this.$router.push({name:'project-edit',params:{id:project.id}})},
  deleteProject(project){if(!this.$can('projects.delete'))return;this.selectedProject=project;this.deleteDialog=true},
  async confirmDelete(){if(!this.selectedProject||!this.$can('projects.delete'))return;this.deleting=true;try{const r=await api.delete('/projects/'+this.selectedProject.id);this.deleteDialog=false;this.selectedProject=null;await this.loadProjects();this.$root.$emit('show-success',r.data.message||'Project deleted successfully.')}catch(e){this.$root.$emit('show-error',(e.response&&e.response.data&&e.response.data.message)||'Unable to delete project.')}finally{this.deleting=false}},
  formatStatus(s){return s?String(s).replace(/_/g,' ').replace(/\b\w/g,function(x){return x.toUpperCase()}):'—'},
  statusColor(s){return({planning:'grey',approved:'info',active:'success',under_construction:'warning',completed:'#165134',on_hold:'orange',cancelled:'error'})[s]||'grey'}
 }}
</script>

<style scoped>
.projects-page{width:100%}.hero{border-left:4px solid #165134}.summary-card,.filter-card,.table-card{border:1px solid rgba(22,81,52,.08);border-radius:15px!important}.project-link{cursor:pointer}.progress-wrap{min-width:130px}.soft-green{background:#e8f5ee!important}.soft-blue{background:#eaf2ff!important}.soft-grey{background:#f1f3f2!important}.primary--text{color:#165134!important}
</style>