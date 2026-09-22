<template>
<div class="property-details-page">
  <v-card flat class="hero pa-5 mb-4">
    <div class="d-flex align-center flex-wrap">
      <v-btn icon class="mr-2" @click="$router.push('/admin/properties')"><v-icon>mdi-arrow-left</v-icon></v-btn>
      <div>
        <div class="text-overline">PROPERTY PROFILE</div>
        <h1 class="text-h5 font-weight-bold">{{property ? property.property_number : 'Property Details'}}</h1>
        <div class="grey--text">{{property && property.project ? property.project.name : 'Property information and history'}}</div>
      </div>
      <v-spacer/>
      <v-chip v-if="property" dark :color="statusColor(property.status)" class="mr-2">{{statusText(property.status)}}</v-chip>
      <v-btn v-if="property && $can('properties.edit')" color="#165134" dark depressed :to="'/admin/properties/'+property.id+'/edit'"><v-icon left>mdi-pencil-outline</v-icon>Edit Property</v-btn>
    </div>
  </v-card>

  <v-progress-linear v-if="loading" indeterminate color="#165134" class="mb-4"/>

  <template v-if="property">
    <v-row class="mb-1">
      <v-col cols="6" md="3"><v-card flat class="summary-card pa-4"><div class="caption grey--text">Property Price</div><div class="text-h6 font-weight-bold">PKR {{formatNumber(property.price)}}</div></v-card></v-col>
      <v-col cols="6" md="3"><v-card flat class="summary-card pa-4"><div class="caption grey--text">Net Price</div><div class="text-h6 font-weight-bold primary--text">PKR {{formatNumber(property.net_price)}}</div></v-card></v-col>
      <v-col cols="6" md="3"><v-card flat class="summary-card pa-4"><div class="caption grey--text">Property Size</div><div class="text-h6 font-weight-bold">{{property.size || '—'}} {{property.size_unit || ''}}</div></v-card></v-col>
      <v-col cols="6" md="3"><v-card flat class="summary-card pa-4"><div class="caption grey--text">Assigned Agent</div><div class="text-h6 font-weight-bold text-truncate">{{property.assigned_agent ? property.assigned_agent.name : 'Unassigned'}}</div></v-card></v-col>
    </v-row>

    <v-row>
      <v-col cols="12" lg="8">
        <v-card flat class="section-card mb-4">
          <v-card-title><v-icon left color="#165134">mdi-home-city-outline</v-icon>Property Information</v-card-title>
          <v-divider/>
          <v-card-text class="pt-5">
            <v-row>
              <v-col cols="6" md="3"><div class="field-label">Type</div><div class="field-value">{{formatText(property.property_type)}}</div></v-col>
              <v-col cols="6" md="3"><div class="field-label">Project</div><div class="field-value">{{property.project ? property.project.name : '—'}}</div></v-col>
              <v-col cols="6" md="3"><div class="field-label">Block</div><div class="field-value">{{property.block ? property.block.name : '—'}}</div></v-col>
              <v-col cols="6" md="3"><div class="field-label">Status</div><div class="field-value">{{statusText(property.status)}}</div></v-col>
              <v-col cols="6" md="3"><div class="field-label">Bedrooms</div><div class="field-value">{{property.bedrooms || '—'}}</div></v-col>
              <v-col cols="6" md="3"><div class="field-label">Bathrooms</div><div class="field-value">{{property.bathrooms || '—'}}</div></v-col>
              <v-col cols="6" md="3"><div class="field-label">Covered Area</div><div class="field-value">{{property.covered_area || '—'}} {{property.covered_area_unit || ''}}</div></v-col>
              <v-col cols="6" md="3"><div class="field-label">Discount</div><div class="field-value">PKR {{formatNumber(property.discount)}}</div></v-col>
            </v-row>
            <v-divider class="my-5"/>
            <div class="field-label">Address</div><div class="field-value">{{property.address || '—'}}</div>
            <div v-if="property.description" class="mt-5"><div class="field-label">Description</div><div class="field-value description">{{property.description}}</div></div>
          </v-card-text>
        </v-card>

        <property-image-manager :property-id="property.id" class="mb-4"/>

        <v-card flat class="section-card mb-4">
          <v-card-title><v-icon left color="#165134">mdi-file-document-multiple-outline</v-icon>Documents <v-spacer/><v-chip x-small>{{(property.documents || []).length}}</v-chip></v-card-title>
          <v-divider/>
          <v-data-table :headers="documentHeaders" :items="property.documents || []" :items-per-page="10">
            <template v-slot:item.file_size="{item}">{{fileSize(item.file_size)}}</template>
            <template v-slot:item.actions="{item}">
              <v-btn icon small title="Download" @click="downloadDocument(item)"><v-icon small>mdi-download</v-icon></v-btn>
              <v-btn v-if="$can('documents.delete')" icon small color="error" title="Delete" @click="deleteDocument(item)"><v-icon small>mdi-delete-outline</v-icon></v-btn>
            </template>
            <template v-slot:no-data><div class="pa-6 text-center grey--text"><v-icon color="grey lighten-1">mdi-file-outline</v-icon><div>No property documents uploaded.</div></div></template>
          </v-data-table>
        </v-card>

        <v-card flat class="section-card">
          <v-card-title><v-icon left color="#165134">mdi-star-check-outline</v-icon>Features</v-card-title>
          <v-divider/>
          <v-card-text>
            <v-chip v-for="feature in property.features" :key="feature.id" class="ma-1" outlined color="#165134"><v-icon left small>mdi-check-circle-outline</v-icon>{{feature.feature_name}}</v-chip>
            <div v-if="!property.features || !property.features.length" class="grey--text text-center py-6">No features added to this property.</div>
          </v-card-text>
        </v-card>
      </v-col>

      <v-col cols="12" lg="4">
        <v-card flat class="section-card mb-4">
          <v-card-title><v-icon left color="#165134">mdi-swap-horizontal</v-icon>Property Status</v-card-title>
          <v-divider/>
          <v-card-text class="pt-5">
            <template v-if="$can('properties.edit')">
              <v-select v-model="newStatus" :items="statuses" item-text="text" item-value="value" label="Status" outlined dense/>
              <v-textarea v-model="statusNotes" label="Reason / Notes" outlined dense rows="3" hint="Add a note so status history remains clear" persistent-hint/>
              <v-btn block color="#165134" dark depressed class="mt-3" :loading="statusSaving" :disabled="newStatus===property.status" @click="changeStatus">Update Status</v-btn>
            </template>
            <v-alert v-else type="info" text dense>You have view-only access to this property.</v-alert>
          </v-card-text>
        </v-card>

        <v-card flat class="section-card">
          <v-card-title><v-icon left color="#165134">mdi-history</v-icon>Status History</v-card-title>
          <v-divider/>
          <v-card-text>
            <v-timeline dense>
              <v-timeline-item v-for="item in property.status_histories" :key="item.id" small :color="statusColor(item.new_status)">
                <div class="font-weight-bold">{{statusText(item.new_status)}}</div>
                <div class="caption grey--text">{{item.old_status ? statusText(item.old_status) : 'Created'}} → {{statusText(item.new_status)}}</div>
                <div v-if="item.changed_by" class="caption grey--text mt-1">{{item.changed_by.name}}</div>
                <div v-if="item.notes" class="mt-1">{{item.notes}}</div>
              </v-timeline-item>
            </v-timeline>
            <div v-if="!property.status_histories || !property.status_histories.length" class="grey--text text-center py-5">No status history.</div>
          </v-card-text>
        </v-card>
      </v-col>
    </v-row>
  </template>

  <v-snackbar v-model="snackbar" :color="snackbarColor" bottom right>{{snackbarText}}</v-snackbar>

  <v-dialog v-model="deleteDocumentDialog" max-width="480" persistent>
    <v-card>
      <v-card-title>Delete Document</v-card-title>
      <v-card-text><v-alert type="warning" outlined dense>Delete <strong>{{documentToDelete && documentToDelete.name}}</strong>? This cannot be undone.</v-alert></v-card-text>
      <v-card-actions><v-spacer/><v-btn text :disabled="documentDeleting" @click="deleteDocumentDialog=false">Cancel</v-btn><v-btn color="error" :loading="documentDeleting" @click="confirmDeleteDocument">Delete</v-btn></v-card-actions>
    </v-card>
  </v-dialog>
</div>
</template>
<script>
import api from '../../../services/api'
import PropertyImageManager from './PropertyImageManager.vue'
export default{
 name:'PropertyDetails',components:{PropertyImageManager},
 data(){return{loading:false,statusSaving:false,property:null,deleteDocumentDialog:false,documentDeleting:false,documentToDelete:null,documentHeaders:[{text:'Type',value:'document_type'},{text:'File',value:'name'},{text:'Size',value:'file_size'},{text:'Actions',value:'actions',sortable:false}],newStatus:null,statusNotes:'',statuses:[{text:'Available',value:'available'},{text:'Reserved',value:'reserved'},{text:'Booked',value:'booked'},{text:'Sold',value:'sold'},{text:'Under Construction',value:'under_construction'},{text:'Rented',value:'rented'},{text:'Unavailable',value:'unavailable'},{text:'Cancelled',value:'cancelled'}],snackbar:false,snackbarText:'',snackbarColor:'success'}},
 created(){this.loadProperty()},
 methods:{
  async downloadDocument(item){try{const r=await api.get('/property-documents/'+item.id+'/download',{responseType:'blob'});const url=window.URL.createObjectURL(new Blob([r.data]));const a=document.createElement('a');a.href=url;a.download=item.name||'property-document';document.body.appendChild(a);a.click();a.remove();window.URL.revokeObjectURL(url)}catch(error){this.showMessage('Unable to download document.','error')}},
  deleteDocument(item){this.documentToDelete=item;this.deleteDocumentDialog=true},
  async confirmDeleteDocument(){if(!this.documentToDelete)return;this.documentDeleting=true;try{await api.delete('/property-documents/'+this.documentToDelete.id);this.deleteDocumentDialog=false;this.documentToDelete=null;await this.loadProperty();this.showMessage('Document deleted successfully.','success')}catch(error){this.showMessage((error.response&&error.response.data&&error.response.data.message)||'Unable to delete document.','error')}finally{this.documentDeleting=false}},
  fileSize(bytes){const n=Number(bytes||0);if(!n)return'—';if(n<1024)return n+' B';if(n<1048576)return(n/1024).toFixed(1)+' KB';return(n/1048576).toFixed(1)+' MB'},
  async loadProperty(){this.loading=true;try{const response=await api.get('/properties/'+this.$route.params.id);this.property=response.data.property;this.newStatus=this.property.status}catch(error){this.showMessage((error.response&&error.response.data&&error.response.data.message)||'Unable to load property.','error')}finally{this.loading=false}},
  async changeStatus(){if(!this.newStatus||!this.$can('properties.edit')||this.newStatus===this.property.status)return;this.statusSaving=true;try{await api.put('/properties/'+this.property.id+'/status',{status:this.newStatus,notes:this.statusNotes});this.statusNotes='';await this.loadProperty();this.showMessage('Property status updated successfully.','success')}catch(error){this.showMessage((error.response&&error.response.data&&error.response.data.message)||'Unable to update status.','error')}finally{this.statusSaving=false}},
  statusText(status){const item=this.statuses.find(x=>x.value===status);return item?item.text:status},
  statusColor(status){return({available:'green',reserved:'orange',booked:'blue',sold:'red',under_construction:'purple',rented:'teal',unavailable:'grey',cancelled:'black'})[status]||'grey'},
  formatText(v){return v?String(v).replace(/_/g,' ').replace(/\b\w/g,function(x){return x.toUpperCase()}):'—'},
  formatNumber(value){return new Intl.NumberFormat('en-PK',{maximumFractionDigits:0}).format(Number(value||0))},
  showMessage(message,color){this.snackbarText=message;this.snackbarColor=color;this.snackbar=true}
 }}
</script>
<style scoped>
.property-details-page{width:100%}.hero{border-left:4px solid #165134}.summary-card,.section-card{border:1px solid rgba(22,81,52,.08);border-radius:15px!important}.field-label{font-size:12px;color:#7a847f;margin-bottom:4px}.field-value{font-weight:500;color:#26332d}.description{line-height:1.7}.primary--text{color:#165134!important}
</style>