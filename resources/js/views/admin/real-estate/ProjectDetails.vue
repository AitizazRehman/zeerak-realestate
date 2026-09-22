<template>
<div class="project-details-page">
  <v-card flat class="hero pa-5 mb-4">
    <div class="d-flex align-center flex-wrap">
      <v-btn icon class="mr-2" @click="$router.push('/admin/projects')"><v-icon>mdi-arrow-left</v-icon></v-btn>
      <div>
        <div class="text-overline">REAL ESTATE PROJECT</div>
        <h1 class="text-h5 font-weight-bold">{{project ? project.name : 'Project Details'}}</h1>
        <div class="grey--text">{{project ? project.code : 'Project overview'}}</div>
      </div>
      <v-spacer/>
      <v-chip v-if="project" dark :color="statusColor(project.status)" class="mr-2">{{formatStatus(project.status)}}</v-chip>
      <v-btn v-if="project && $can('projects.edit')" color="#165134" dark depressed :to="'/admin/projects/'+project.id+'/edit'"><v-icon left>mdi-pencil-outline</v-icon>Edit Project</v-btn>
    </div>
  </v-card>

  <v-progress-linear v-if="loading" indeterminate color="#165134" class="mb-4"/>

  <template v-if="project">
    <v-row class="mb-1">
      <v-col cols="6" md="3"><v-card flat class="summary-card pa-4"><div class="caption grey--text">Properties</div><div class="text-h5 font-weight-bold">{{properties.length}}</div></v-card></v-col>
      <v-col cols="6" md="3"><v-card flat class="summary-card pa-4"><div class="caption grey--text">Blocks</div><div class="text-h5 font-weight-bold">{{blocks.length}}</div></v-card></v-col>
      <v-col cols="6" md="3"><v-card flat class="summary-card pa-4"><div class="caption grey--text">Available</div><div class="text-h5 font-weight-bold success--text">{{availableCount}}</div></v-card></v-col>
      <v-col cols="6" md="3"><v-card flat class="summary-card pa-4"><div class="caption grey--text">Sold</div><div class="text-h5 font-weight-bold">{{soldCount}}</div></v-card></v-col>
    </v-row>

    <v-row>
      <v-col cols="12" lg="8">
        <v-card flat class="section-card mb-4">
          <v-card-title><v-icon left color="#165134">mdi-office-building-outline</v-icon>Project Information</v-card-title>
          <v-divider/>
          <v-card-text class="pt-5">
            <v-row>
              <v-col cols="6" md="3"><div class="field-label">Branch</div><div class="field-value">{{project.branch ? project.branch.name : '—'}}</div></v-col>
              <v-col cols="6" md="3"><div class="field-label">City</div><div class="field-value">{{project.city || '—'}}</div></v-col>
              <v-col cols="6" md="3"><div class="field-label">Location</div><div class="field-value">{{project.location || '—'}}</div></v-col>
              <v-col cols="6" md="3"><div class="field-label">Status</div><div class="field-value">{{formatStatus(project.status)}}</div></v-col>
              <v-col cols="6" md="3"><div class="field-label">Total Area</div><div class="field-value">{{project.total_area || '—'}} {{project.area_unit || ''}}</div></v-col>
              <v-col cols="6" md="3"><div class="field-label">Budget</div><div class="field-value">PKR {{money(project.budget)}}</div></v-col>
              <v-col cols="6" md="3"><div class="field-label">Actual Cost</div><div class="field-value">PKR {{money(project.actual_cost)}}</div></v-col>
              <v-col cols="6" md="3"><div class="field-label">Active</div><div class="field-value">{{project.is_active ? 'Yes' : 'No'}}</div></v-col>
            </v-row>
            <v-divider class="my-5"/>
            <div class="field-label mb-2">Construction Progress</div>
            <v-progress-linear :value="Number(project.construction_progress||0)" height="14" rounded color="#165134"><template v-slot:default="{value}"><strong class="white--text">{{Math.ceil(value)}}%</strong></template></v-progress-linear>
            <div v-if="project.description" class="mt-5"><div class="field-label">Description</div><div class="field-value description">{{project.description}}</div></div>
          </v-card-text>
        </v-card>

        <v-card flat class="section-card">
          <v-card-title><v-icon left color="#165134">mdi-city-variant-outline</v-icon>Project Blocks <v-spacer/><v-btn v-if="$can('projects.view')" text small color="#165134" @click="openBlocks">Manage Blocks</v-btn></v-card-title>
          <v-divider/>
          <v-data-table :headers="blockHeaders" :items="blocks" :items-per-page="10">
            <template v-slot:item.total_area="{item}">{{item.total_area || 0}} {{item.area_unit || 'Marla'}}</template>
            <template v-slot:item.is_active="{item}"><v-chip x-small dark :color="item.is_active?'success':'grey'">{{item.is_active?'Active':'Inactive'}}</v-chip></template>
            <template v-slot:item.actions="{item}"><v-btn v-if="$can('properties.view')" icon small title="View properties" @click="openBlockProperties(item)"><v-icon small>mdi-home-group</v-icon></v-btn></template>
            <template v-slot:no-data><div class="pa-6 text-center grey--text">No blocks added to this project.</div></template>
          </v-data-table>
        </v-card>
      </v-col>

      <v-col cols="12" lg="4">
        <v-card flat class="section-card mb-4">
          <v-card-title><v-icon left color="#165134">mdi-lightning-bolt-outline</v-icon>Quick Actions</v-card-title>
          <v-divider/>
          <v-card-text>
            <v-btn v-if="$can('properties.view')" block outlined color="#165134" class="mb-3" @click="openProperties"><v-icon left>mdi-home-search-outline</v-icon>View Properties</v-btn>
            <v-btn v-if="$can('properties.create')" block outlined color="#165134" class="mb-3" @click="$router.push('/admin/properties/create')"><v-icon left>mdi-home-plus-outline</v-icon>Add Property</v-btn>
            <v-btn v-if="$can('projects.create')" block outlined color="#165134" @click="$router.push('/admin/blocks')"><v-icon left>mdi-plus-box-multiple-outline</v-icon>Add Block</v-btn>
          </v-card-text>
        </v-card>

        <v-card flat class="section-card">
          <v-card-title><v-icon left color="#165134">mdi-home-analytics</v-icon>Property Mix</v-card-title>
          <v-divider/>
          <v-card-text>
            <div v-for="s in propertyMix" :key="s.status" class="mb-4">
              <div class="d-flex justify-space-between mb-1"><span>{{s.label}}</span><strong>{{s.count}}</strong></div>
              <v-progress-linear :value="s.percent" :color="s.color" height="8" rounded/>
            </div>
            <div v-if="!properties.length" class="grey--text text-center py-5">No property inventory yet.</div>
          </v-card-text>
        </v-card>
      </v-col>
    </v-row>
  </template>
</div>
</template>
<script>
import api from '../../../services/api'
export default{
 name:'ProjectDetails',
 data(){return{loading:false,project:null,blockHeaders:[{text:'Block',value:'name'},{text:'Code',value:'code'},{text:'Area',value:'total_area'},{text:'Units',value:'total_units'},{text:'Status',value:'is_active'},{text:'Actions',value:'actions',sortable:false,align:'right'}]}},
 computed:{
  blocks(){return(this.project&&this.project.blocks)||[]},
  properties(){return(this.project&&this.project.properties)||[]},
  availableCount(){return this.properties.filter(x=>x.status==='available').length},
  soldCount(){return this.properties.filter(x=>x.status==='sold').length},
  propertyMix(){const defs=[['available','Available','green'],['reserved','Reserved','orange'],['booked','Booked','blue'],['sold','Sold','red']];const total=Math.max(this.properties.length,1);return defs.map(d=>({status:d[0],label:d[1],color:d[2],count:this.properties.filter(x=>x.status===d[0]).length,percent:this.properties.filter(x=>x.status===d[0]).length/total*100}))}
 },
 created(){this.load()},
 methods:{
  async load(){this.loading=true;try{const r=await api.get('/projects/'+this.$route.params.id);this.project=r.data.data}catch(e){this.$root.$emit('show-error',(e.response&&e.response.data&&e.response.data.message)||'Unable to load project.')}finally{this.loading=false}},
  openProperties(){this.$router.push({name:'properties',query:{project_id:this.project.id}})},
  openBlocks(){this.$router.push({name:'blocks',query:{project_id:this.project.id}})},
  openBlockProperties(item){this.$router.push({name:'properties',query:{project_id:this.project.id,block_id:item.id}})},
  money(v){return new Intl.NumberFormat('en-PK',{maximumFractionDigits:0}).format(Number(v||0))},
  formatStatus(v){return v?String(v).replace(/_/g,' ').replace(/\b\w/g,function(x){return x.toUpperCase()}):'—'},
  statusColor(s){return({planning:'grey',approved:'info',active:'success',under_construction:'warning',completed:'#165134',on_hold:'orange',cancelled:'error'})[s]||'grey'}
 }}
</script>
<style scoped>
.project-details-page{width:100%}.hero{border-left:4px solid #165134}.summary-card,.section-card{border:1px solid rgba(22,81,52,.08);border-radius:15px!important}.field-label{font-size:12px;color:#7a847f;margin-bottom:4px}.field-value{font-weight:500;color:#26332d}.description{line-height:1.7}
</style>