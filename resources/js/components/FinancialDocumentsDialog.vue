<template>
  <v-dialog :value="value" max-width="860" persistent @input="$emit('input',$event)">
    <v-card>
      <v-card-title>
        <div>
          <div class="text-h6 font-weight-bold">{{ title || 'Documents' }}</div>
          <div class="caption grey--text">Attach receipts, invoices, vouchers, bank slips, cheque copies, agreements or other supporting files.</div>
        </div>
        <v-spacer/>
        <v-btn icon :disabled="uploading" @click="$emit('input',false)"><v-icon>mdi-close</v-icon></v-btn>
      </v-card-title>
      <v-divider/>

      <v-card-text class="pt-5">
        <v-alert type="info" text dense>
          Documents are stored privately and are only available to authorized users.
        </v-alert>

        <v-row v-if="canUpload" dense class="mb-3">
          <v-col cols="12" md="3">
            <v-select v-model="form.document_type" :items="documentTypes" item-text="text" item-value="value" outlined dense label="Document Type *"/>
          </v-col>
          <v-col cols="12" md="5">
            <v-file-input v-model="form.document" outlined dense show-size :rules="fileRules" accept=".pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png,.webp" label="Choose document *" prepend-icon="mdi-paperclip"/>
          </v-col>
          <v-col cols="12" md="4">
            <v-text-field v-model="form.notes" outlined dense label="Notes"/>
          </v-col>
          <v-col cols="12" class="text-right">
            <v-btn color="#165134" dark depressed :loading="uploading" :disabled="!form.document || !form.document_type" @click="upload">
              <v-icon left>mdi-upload</v-icon>Upload Document
            </v-btn>
          </v-col>
        </v-row>

        <v-data-table :headers="headers" :items="documents" :loading="loading" :items-per-page="10">
          <template v-slot:item.document_type="{item}"><v-chip x-small outlined>{{typeLabel(item.document_type)}}</v-chip></template>
          <template v-slot:item.file_size="{item}">{{fileSize(item.file_size)}}</template>
          <template v-slot:item.created_at="{item}">{{dateTime(item.created_at)}}</template>
          <template v-slot:item.actions="{item}">
            <v-btn icon small color="#165134" title="Download" @click="download(item)"><v-icon small>mdi-download</v-icon></v-btn>
            <v-btn v-if="canDelete" icon small color="error" title="Delete" @click="askDelete(item)"><v-icon small>mdi-delete-outline</v-icon></v-btn>
          </template>
          <template v-slot:no-data>
            <div class="pa-8 text-center grey--text">
              <v-icon size="48" color="grey lighten-1">mdi-file-document-outline</v-icon>
              <div class="mt-2">No documents attached yet.</div>
            </div>
          </template>
        </v-data-table>
      </v-card-text>
    </v-card>

    <v-dialog v-model="deleteDialog" max-width="480" persistent>
      <v-card>
        <v-card-title>Delete Document</v-card-title>
        <v-card-text><v-alert type="warning" outlined dense>Delete <strong>{{deleteItem ? deleteItem.name : ''}}</strong>? The action will be recorded in the financial audit trail.</v-alert></v-card-text>
        <v-card-actions><v-spacer/><v-btn text :disabled="deleting" @click="deleteDialog=false">Cancel</v-btn><v-btn color="error" :loading="deleting" @click="confirmDelete">Delete</v-btn></v-card-actions>
      </v-card>
    </v-dialog>
  </v-dialog>
</template>

<script>
import api from '../services/api'

export default {
  name: 'FinancialDocumentsDialog',
  props: {
    value: { type: Boolean, default: false },
    entityType: { type: String, required: true },
    entityId: { type: [Number, String], default: null },
    title: { type: String, default: 'Supporting Documents' },
    canUpload: { type: Boolean, default: false },
    canDelete: { type: Boolean, default: false }
  },
  data() {
    return {
      loading:false,uploading:false,deleting:false,deleteDialog:false,deleteItem:null,documents:[],
      documentTypes:[
        {text:'Receipt',value:'receipt'},{text:'Invoice',value:'invoice'},{text:'Voucher',value:'voucher'},
        {text:'Bank Slip',value:'bank_slip'},{text:'Cheque Copy',value:'cheque'},{text:'Agreement',value:'agreement'},{text:'Other',value:'other'}
      ],
      form:{document_type:'receipt',document:null,notes:''},
      headers:[
        {text:'Type',value:'document_type'},{text:'File',value:'name'},{text:'Size',value:'file_size'},
        {text:'Uploaded By',value:'uploader.name'},{text:'Uploaded',value:'created_at'},{text:'Actions',value:'actions',sortable:false,align:'right'}
      ],
      fileRules:[v=>!v||v.size<=10485760||'Maximum file size is 10 MB']
    }
  },
  watch:{
    value(v){if(v&&this.entityId)this.load()},
    entityId(v){if(v&&this.value)this.load()}
  },
  methods:{
    async load(){
      if(!this.entityId)return
      this.loading=true
      try{
        const r=await api.get('/financial-documents/'+this.entityType+'/'+this.entityId)
        this.documents=r.data||[]
      }catch(e){
        this.documents=[]
        this.$root.$emit('show-error',(e.response&&e.response.data&&e.response.data.message)||'Unable to load documents.')
      }finally{this.loading=false}
    },
    async upload(){
      if(!this.entityId||!this.form.document||!this.form.document_type)return
      if(this.form.document.size>10485760){this.$root.$emit('show-error','Maximum document size is 10 MB.');return}
      const fd=new FormData()
      fd.append('document',this.form.document)
      fd.append('document_type',this.form.document_type)
      if(this.form.notes)fd.append('notes',this.form.notes)
      this.uploading=true
      try{
        const r=await api.post('/financial-documents/'+this.entityType+'/'+this.entityId,fd)
        this.form={document_type:'receipt',document:null,notes:''}
        await this.load()
        this.$root.$emit('show-success',(r.data&&r.data.message)||'Document uploaded successfully.')
        this.$emit('updated')
      }catch(e){
        this.$root.$emit('show-error',(e.response&&e.response.data&&e.response.data.message)||'Unable to upload document.')
      }finally{this.uploading=false}
    },
    async download(item){
      try{
        const r=await api.get('/financial-document-files/'+item.id,{responseType:'blob'})
        const url=window.URL.createObjectURL(new Blob([r.data]))
        const a=document.createElement('a')
        a.href=url
        a.download=item.name||'document'
        document.body.appendChild(a)
        a.click()
        a.remove()
        window.URL.revokeObjectURL(url)
      }catch(e){this.$root.$emit('show-error','Unable to download document.')}
    },
    askDelete(item){this.deleteItem=item;this.deleteDialog=true},
    async confirmDelete(){
      if(!this.deleteItem)return
      this.deleting=true
      try{
        await api.delete('/financial-documents/'+this.deleteItem.id)
        this.deleteDialog=false
        this.deleteItem=null
        await this.load()
        this.$root.$emit('show-success','Document deleted successfully.')
        this.$emit('updated')
      }catch(e){
        this.$root.$emit('show-error',(e.response&&e.response.data&&e.response.data.message)||'Unable to delete document.')
      }finally{this.deleting=false}
    },
    typeLabel(v){const x=this.documentTypes.find(function(i){return i.value===v});return x?x.text:v},
    fileSize(bytes){const n=Number(bytes||0);if(!n)return'—';if(n<1024)return n+' B';if(n<1048576)return(n/1024).toFixed(1)+' KB';return(n/1048576).toFixed(1)+' MB'},
    dateTime(v){return v?new Date(v).toLocaleString('en-PK',{dateStyle:'medium',timeStyle:'short'}):'—'}
  }
}
</script>
