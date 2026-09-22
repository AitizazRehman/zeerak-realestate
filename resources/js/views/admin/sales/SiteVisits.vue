<template>
<div class="page">
  <v-card flat class="hero pa-5 mb-4">
    <div class="d-flex align-center flex-wrap">
      <div>
        <div class="text-overline">SALES CRM</div>
        <h1 class="text-h5 font-weight-bold">Site Visits</h1>
        <div class="grey--text">Plan visits, track outcomes and keep the sales journey organized.</div>
      </div>
      <v-spacer/>
      <v-btn v-if="$can('site_visits.create')" color="#165134" dark depressed class="rounded-lg" @click="open">
        <v-icon left>mdi-calendar-plus</v-icon>Schedule Visit
      </v-btn>
    </div>
  </v-card>

  <v-row class="mb-1">
    <v-col v-for="s in summaries" :key="s.status" cols="6" sm="3">
      <v-card flat class="summary-card pa-4" @click="toggleStatus(s.status)">
        <div class="d-flex align-center">
          <v-avatar size="42" :class="s.softClass" class="mr-3"><v-icon :color="s.color">{{s.icon}}</v-icon></v-avatar>
          <div><div class="caption grey--text">{{s.label}}</div><div class="text-h6 font-weight-bold">{{s.count}}</div></div>
        </div>
      </v-card>
    </v-col>
  </v-row>

  <v-card flat class="filter-card pa-4 mb-4">
    <v-row dense align="center">
      <v-col cols="12" md="6"><v-text-field v-model="search" outlined dense hide-details clearable prepend-inner-icon="mdi-magnify" label="Search customer, property or notes"/></v-col>
      <v-col cols="12" md="3"><v-select v-model="statusFilter" :items="statusOptions" outlined dense hide-details clearable label="Status"/></v-col>
      <v-col cols="12" md="3" class="text-md-right"><v-btn text color="#165134" :loading="loading" @click="load"><v-icon left>mdi-refresh</v-icon>Refresh</v-btn></v-col>
    </v-row>
  </v-card>

  <v-card flat class="table-card">
    <v-data-table :headers="headers" :items="filteredItems" :loading="loading" :items-per-page="15">
      <template v-slot:item.customer.name="{item}">
        <div class="py-2"><div class="font-weight-medium">{{item.customer ? item.customer.name : '—'}}</div><div v-if="item.lead" class="caption grey--text">Lead: {{item.lead.name}}</div></div>
      </template>
      <template v-slot:item.property.property_number="{item}">
        <div v-if="item.property"><div class="font-weight-medium">{{item.property.property_number}}</div><div class="caption grey--text">{{item.property.project ? item.property.project.name : ''}}</div></div><span v-else>—</span>
      </template>
      <template v-slot:item.visit_at="{item}">{{formatDate(item.visit_at)}}</template>
      <template v-slot:item.status="{item}"><v-chip x-small :color="statusColor(item.status)" dark>{{statusLabel(item.status)}}</v-chip></template>
      <template v-slot:item.actions="{item}">
        <v-btn v-if="$can('site_visits.edit')" icon small title="Edit visit" @click="edit(item)"><v-icon small>mdi-pencil</v-icon></v-btn>
        <v-btn v-if="$can('site_visits.delete')" icon small color="error" title="Delete visit" @click="openDelete(item)"><v-icon small>mdi-delete-outline</v-icon></v-btn>
      </template>
      <template v-slot:no-data><div class="pa-8 text-center grey--text"><v-icon large color="grey lighten-1">mdi-calendar-search</v-icon><div class="mt-2">No site visits found.</div></div></template>
    </v-data-table>
  </v-card>

  <v-dialog v-model="dialog" max-width="720" persistent>
    <v-card>
      <v-card-title><div><div class="text-h6 font-weight-bold">{{editing?'Edit Site Visit':'Schedule Site Visit'}}</div><div class="caption grey--text">{{editing?'Update visit details and outcome.':'Create a new customer property visit.'}}</div></div><v-spacer/><v-btn icon @click="dialog=false"><v-icon>mdi-close</v-icon></v-btn></v-card-title>
      <v-divider/>
      <v-card-text class="pt-5">
        <v-row>
          <v-col cols="12" md="6"><v-autocomplete v-model="form.lead_id" :items="leads" item-text="name" item-value="id" outlined dense clearable label="Lead" :loading="loadingLeads" prepend-inner-icon="mdi-account-search" @change="leadChanged"><template v-slot:item="{item}"><v-list-item-content><v-list-item-title>{{item.name}} — {{item.phone}}</v-list-item-title><v-list-item-subtitle>{{item.lead_number}} · {{statusLabel(item.status)}}</v-list-item-subtitle></v-list-item-content></template></v-autocomplete></v-col>
          <v-col cols="12" md="6"><v-autocomplete v-model="form.customer_id" :items="customers" item-text="name" item-value="id" outlined dense clearable label="Customer" :loading="loadingCustomers" prepend-inner-icon="mdi-account"/></v-col>
          <v-col cols="12" md="6"><v-autocomplete v-model="form.property_id" :items="properties" item-text="property_number" item-value="id" outlined dense clearable label="Property" :loading="loadingProperties" prepend-inner-icon="mdi-home-city"><template v-slot:item="{item}"><v-list-item-content><v-list-item-title>{{item.property_number}} — {{item.project ? item.project.name : 'Property'}}</v-list-item-title><v-list-item-subtitle>{{item.status}}</v-list-item-subtitle></v-list-item-content></template></v-autocomplete></v-col>
          <v-col cols="12" md="6"><v-text-field v-model="form.visit_date" outlined dense type="date" label="Visit Date *"/></v-col>
          <v-col cols="12" md="6"><v-text-field v-model="form.visit_time" outlined dense type="time" label="Time"/></v-col>
          <v-col v-if="editing" cols="12" md="6"><v-select v-model="form.status" :items="statusOptions" outlined dense label="Visit Status"/></v-col>
          <v-col v-if="editing" cols="12"><v-textarea v-model="form.feedback" outlined dense rows="2" label="Visit Feedback" hint="Record customer response, interest level or next action" persistent-hint/></v-col>
          <v-col cols="12"><v-textarea v-model="form.notes" outlined dense rows="3" label="Notes"/></v-col>
        </v-row>
      </v-card-text>
      <v-card-actions class="px-6 pb-5"><v-spacer/><v-btn text @click="dialog=false">Cancel</v-btn><v-btn v-if="editing ? $can('site_visits.edit') : $can('site_visits.create')" color="#165134" dark depressed :loading="saving" :disabled="(!form.customer_id && !form.lead_id) || !form.visit_date" @click="save">{{editing?'Update Visit':'Schedule Visit'}}</v-btn></v-card-actions>
    </v-card>
  </v-dialog>

  <v-dialog v-model="deleteDialog" max-width="500" persistent><v-card><v-card-title>Delete Site Visit</v-card-title><v-card-text><v-alert type="warning" outlined dense>Delete this scheduled site visit? Completed/no-show visits and visits with feedback are retained as CRM history.</v-alert></v-card-text><v-card-actions><v-spacer/><v-btn text :disabled="deleting" @click="deleteDialog=false">Cancel</v-btn><v-btn color="error" :loading="deleting" @click="confirmDelete">Delete Visit</v-btn></v-card-actions></v-card></v-dialog>
</div>
</template>
<script>
import api from '../../../services/api'
export default{
 name:'SiteVisits',
 data(){return{loading:false,saving:false,dialog:false,deleteDialog:false,deleting:false,deleteItem:null,editing:null,items:[],customers:[],properties:[],leads:[],loadingCustomers:false,loadingProperties:false,loadingLeads:false,search:'',statusFilter:null,statusOptions:[{text:'Scheduled',value:'scheduled'},{text:'Completed',value:'completed'},{text:'Cancelled',value:'cancelled'},{text:'No Show',value:'no_show'}],headers:[{text:'Customer',value:'customer.name'},{text:'Property',value:'property.property_number'},{text:'Visit',value:'visit_at'},{text:'Status',value:'status'},{text:'Notes',value:'notes'},{text:'Actions',value:'actions',sortable:false}],form:this.blank()}},
 computed:{
  filteredItems(){const q=(this.search||'').toLowerCase().trim();return this.items.filter(i=>{if(this.statusFilter&&i.status!==this.statusFilter)return false;if(!q)return true;const hay=[i.customer&&i.customer.name,i.lead&&i.lead.name,i.property&&i.property.property_number,i.property&&i.property.project&&i.property.project.name,i.notes].filter(Boolean).join(' ').toLowerCase();return hay.indexOf(q)!==-1})},
  summaries(){return[
   {status:'scheduled',label:'Scheduled',count:this.items.filter(x=>x.status==='scheduled').length,color:'orange darken-2',icon:'mdi-calendar-clock',softClass:'soft-amber'},
   {status:'completed',label:'Completed',count:this.items.filter(x=>x.status==='completed').length,color:'green darken-2',icon:'mdi-check-circle',softClass:'soft-green'},
   {status:'no_show',label:'No Show',count:this.items.filter(x=>x.status==='no_show').length,color:'red',icon:'mdi-account-off',softClass:'soft-red'},
   {status:'cancelled',label:'Cancelled',count:this.items.filter(x=>x.status==='cancelled').length,color:'grey darken-1',icon:'mdi-calendar-remove',softClass:'soft-grey'}]}
 },
 mounted(){this.load();if(this.$route.query.lead_id)this.openFromQuery()},
 methods:{
  blank(){return{customer_id:null,lead_id:null,property_id:null,assigned_to:null,visit_date:'',visit_time:'',status:'scheduled',feedback:'',notes:''}},
  toggleStatus(s){this.statusFilter=this.statusFilter===s?null:s},
  async loadLookups(){this.loadingCustomers=true;this.loadingProperties=true;this.loadingLeads=true;try{const[c,p]=await Promise.all([api.get('/customers',{params:{per_page:100}}),api.get('/properties',{params:{per_page:100}})]);this.customers=c.data.data||[];this.properties=p.data.data||[];if(this.$can('leads.view')){try{const l=await api.get('/leads',{params:{per_page:100}});this.leads=(l.data.data||[]).filter(x=>x.status!=='lost')}catch(e){this.leads=[]}}}catch(e){this.$root.$emit('show-error','Unable to load visit selections.')}finally{this.loadingCustomers=false;this.loadingProperties=false;this.loadingLeads=false}},
  async load(){this.loading=true;try{const r=await api.get('/site-visits',{params:{per_page:100}});this.items=r.data.data||[]}catch(e){this.$root.$emit('show-error','Unable to load site visits.')}finally{this.loading=false}},
  open(){this.editing=null;this.form=this.blank();this.form.visit_date=new Date().toISOString().slice(0,10);this.form.visit_time='10:00';this.dialog=true;this.loadLookups()},async openFromQuery(){this.editing=null;this.form=this.blank();this.form.visit_date=new Date().toISOString().slice(0,10);this.form.visit_time='10:00';this.dialog=true;await this.loadLookups();const leadId=Number(this.$route.query.lead_id||0);const lead=this.leads.find(x=>Number(x.id)===leadId);if(lead){this.form.lead_id=lead.id;this.form.customer_id=lead.customer_id||Number(this.$route.query.customer_id||0)||null;this.form.assigned_to=lead.assigned_to||null}},leadChanged(id){const lead=this.leads.find(x=>Number(x.id)===Number(id));if(lead){this.form.customer_id=lead.customer_id||null;this.form.assigned_to=lead.assigned_to||null}},
  edit(item){this.editing=item;const d=item.visit_at?new Date(item.visit_at):new Date();this.form={customer_id:item.customer_id||null,lead_id:item.lead_id||null,property_id:item.property_id||null,assigned_to:item.assigned_to||null,visit_date:d.toISOString().slice(0,10),visit_time:d.toTimeString().slice(0,5),status:item.status||'scheduled',feedback:item.feedback||'',notes:item.notes||''};this.dialog=true;this.loadLookups()},
  async save(){this.saving=true;try{const visit_at=this.form.visit_date+' '+(this.form.visit_time||'00:00');const payload={customer_id:this.form.customer_id||null,lead_id:this.form.lead_id||null,property_id:this.form.property_id||null,assigned_to:this.form.assigned_to||null,visit_at,status:this.editing?this.form.status:'scheduled',feedback:this.editing?this.form.feedback||null:null,notes:this.form.notes};if(this.editing)await api.put('/site-visits/'+this.editing.id,payload);else await api.post('/site-visits',payload);this.dialog=false;await this.load();this.$root.$emit('show-success',this.editing?'Site visit updated successfully.':'Site visit scheduled successfully.');this.editing=null}catch(e){this.$root.$emit('show-error',(e.response&&e.response.data&&e.response.data.message)||'Unable to save site visit.')}finally{this.saving=false}},
  openDelete(item){if(!this.$can('site_visits.delete'))return;this.deleteItem=item;this.deleteDialog=true},
  async confirmDelete(){if(!this.deleteItem)return;this.deleting=true;try{await api.delete('/site-visits/'+this.deleteItem.id);this.deleteDialog=false;this.deleteItem=null;await this.load();this.$root.$emit('show-success','Site visit deleted successfully.')}catch(e){this.$root.$emit('show-error',(e.response&&e.response.data&&e.response.data.message)||'Unable to delete site visit.')}finally{this.deleting=false}},
  formatDate(v){return v?new Date(v).toLocaleString('en-PK',{dateStyle:'medium',timeStyle:'short'}):'—'},
  statusLabel(s){return String(s||'').replace('_',' ').replace(/\b\w/g,x=>x.toUpperCase())},
  statusColor(s){return{scheduled:'orange',completed:'success',cancelled:'grey',no_show:'error'}[s]||'grey'}
 }}
</script>
<style scoped>
.page{width:100%}.hero{border-left:4px solid #165134}.summary-card,.filter-card,.table-card{border:1px solid rgba(22,81,52,.08);border-radius:14px!important}.summary-card{cursor:pointer;transition:.2s}.summary-card:hover{transform:translateY(-2px);box-shadow:0 8px 22px rgba(20,50,35,.08)!important}.soft-green{background:#e8f5ee!important}.soft-amber{background:#fff5df!important}.soft-red{background:#fdecec!important}.soft-grey{background:#f1f3f2!important}
</style>