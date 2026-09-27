<template>
  <div class="statement-import-page">
    <v-card flat class="hero pa-5 mb-4">
      <div class="d-flex align-center flex-wrap">
        <div>
          <div class="text-overline">BANK RECONCILIATION</div>
          <h1 class="text-h5 font-weight-bold mb-1">Bank Statement Import</h1>
          <div class="grey--text">Upload CSV/XLSX statements, map bank columns, and import only new transactions.</div>
        </div>
        <v-spacer/>
        <v-btn text color="#165134" to="/admin/accounting/bank-transactions">
          <v-icon left>mdi-bank-transfer</v-icon>
          Bank Transactions
        </v-btn>
      </div>
    </v-card>

    <v-stepper v-model="step" flat class="stepper-card mb-4">
      <v-stepper-header>
        <v-stepper-step :complete="step > 1" step="1">Upload</v-stepper-step>
        <v-divider/>
        <v-stepper-step :complete="step > 2" step="2">Map Columns</v-stepper-step>
        <v-divider/>
        <v-stepper-step step="3">Result</v-stepper-step>
      </v-stepper-header>

      <v-stepper-items>
        <v-stepper-content step="1">
          <v-alert type="info" text dense>
            Supported formats: CSV and XLSX. Files are stored privately and retained with the import batch for audit history.
          </v-alert>

          <v-row>
            <v-col cols="12" md="6">
              <v-autocomplete
                v-model="upload.bank_account_id"
                :items="bankAccounts"
                item-text="display"
                item-value="id"
                outlined dense
                label="Bank / Cash Account *"
                :error-messages="uploadErrors.bank_account_id"
              />
            </v-col>
            <v-col cols="12" md="2">
              <v-text-field
                v-model.number="upload.header_row"
                type="number"
                min="1"
                max="50"
                outlined dense
                label="Header Row"
                :error-messages="uploadErrors.header_row"
              />
            </v-col>
            <v-col cols="12" md="4">
              <v-file-input
                v-model="upload.file"
                accept=".csv,.txt,.xlsx"
                outlined dense
                prepend-icon="mdi-file-excel-outline"
                label="Statement File *"
                show-size
                :error-messages="uploadErrors.file"
              />
            </v-col>
          </v-row>

          <div class="text-right">
            <v-btn
              color="#165134"
              dark
              depressed
              :loading="uploading"
              :disabled="!upload.bank_account_id || !upload.file"
              @click="uploadPreview"
            >
              Preview Statement
              <v-icon right>mdi-arrow-right</v-icon>
            </v-btn>
          </div>
        </v-stepper-content>

        <v-stepper-content step="2">
          <v-alert v-if="duplicateFileWarning" type="warning" text dense>
            {{ duplicateFileWarning.message }}
          </v-alert>

          <div class="d-flex align-center flex-wrap mb-4">
            <div>
              <div class="font-weight-bold">{{ currentImport ? currentImport.original_filename : '' }}</div>
              <div class="caption grey--text">
                {{ currentAccountLabel }} · {{ detectedRows }} data rows detected
              </div>
            </div>
            <v-spacer/>
            <v-text-field
              v-model.number="headerRow"
              type="number"
              min="1"
              max="50"
              outlined dense hide-details
              label="Header Row"
              class="header-row-field mr-2"
            />
            <v-btn outlined color="#165134" :loading="refreshing" @click="refreshPreview">
              Re-read
            </v-btn>
          </div>

          <v-card flat class="mapping-card pa-4 mb-4">
            <div class="subtitle-1 font-weight-bold mb-1">Column Mapping</div>
            <div class="caption grey--text mb-4">
              Map your bank's headings to ERP fields. For bank statements, bank <strong>Credit</strong> normally maps to <strong>Deposit</strong>, while bank <strong>Debit</strong> maps to <strong>Withdrawal</strong>.
            </div>

            <v-row>
              <v-col
                v-for="field in mappingFields"
                :key="field.key"
                cols="12"
                sm="6"
                md="4"
              >
                <v-select
                  v-model="mapping[field.key]"
                  :items="columnOptions"
                  item-text="text"
                  item-value="value"
                  outlined dense clearable
                  :label="field.label + (field.required ? ' *' : '')"
                />
              </v-col>
            </v-row>

            <v-alert v-if="usesSignedAmount" type="info" text dense>
              This mapping uses one signed Amount column. Choose how positive values should be interpreted.
            </v-alert>

            <v-radio-group v-if="usesSignedAmount" v-model="amountMode" row class="mt-0">
              <v-radio label="Positive amount = Deposit / Money In" value="positive_deposit" color="#165134"/>
              <v-radio label="Positive amount = Withdrawal / Money Out" value="positive_withdrawal" color="#165134"/>
            </v-radio-group>

            <v-row>
              <v-col cols="12" md="4">
                <v-text-field
                  v-model="statementOpeningBalance"
                  type="number"
                  step="0.01"
                  outlined dense
                  prefix="PKR"
                  label="Statement Opening Balance"
                />
              </v-col>
              <v-col cols="12" md="4">
                <v-text-field
                  v-model="statementClosingBalance"
                  type="number"
                  step="0.01"
                  outlined dense
                  prefix="PKR"
                  label="Statement Closing Balance"
                />
              </v-col>
              <v-col cols="12" md="4">
                <v-text-field v-model="notes" outlined dense label="Import Notes"/>
              </v-col>
            </v-row>

            <v-alert v-if="mappingError" type="error" text dense class="mb-0">
              {{ mappingError }}
            </v-alert>
          </v-card>

          <v-card flat class="preview-card mb-4">
            <v-card-title class="subtitle-1">Statement Preview</v-card-title>
            <v-divider/>
            <div class="preview-scroll">
              <table class="preview-table">
                <thead>
                  <tr>
                    <th v-for="header in headers" :key="header.index">{{ header.label }}</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="(row,rowIndex) in previewRows" :key="rowIndex">
                    <td v-for="header in headers" :key="header.index">
                      {{ row[header.index] || '' }}
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
          </v-card>

          <div class="d-flex justify-space-between">
            <v-btn text @click="startOver">
              <v-icon left>mdi-arrow-left</v-icon>
              Start Over
            </v-btn>
            <v-btn color="#165134" dark depressed :loading="importing" @click="commitImport">
              Import Statement
              <v-icon right>mdi-database-import-outline</v-icon>
            </v-btn>
          </div>
        </v-stepper-content>

        <v-stepper-content step="3">
          <v-alert type="success" prominent text>
            <div class="font-weight-bold">Statement import completed.</div>
            Duplicate transactions were skipped automatically and were not inserted again.
          </v-alert>

          <v-row v-if="result">
            <v-col cols="6" md="3">
              <v-card flat class="summary-card pa-4">
                <div class="caption grey--text">Rows Read</div>
                <div class="text-h6 font-weight-bold">{{ result.total_rows || 0 }}</div>
              </v-card>
            </v-col>
            <v-col cols="6" md="3">
              <v-card flat class="summary-card pa-4">
                <div class="caption grey--text">Imported</div>
                <div class="text-h6 font-weight-bold success--text">{{ result.imported_rows || 0 }}</div>
              </v-card>
            </v-col>
            <v-col cols="6" md="3">
              <v-card flat class="summary-card pa-4">
                <div class="caption grey--text">Duplicates</div>
                <div class="text-h6 font-weight-bold warning--text">{{ result.duplicate_rows || 0 }}</div>
              </v-card>
            </v-col>
            <v-col cols="6" md="3">
              <v-card flat class="summary-card pa-4">
                <div class="caption grey--text">Skipped / Invalid</div>
                <div class="text-h6 font-weight-bold">{{ result.skipped_rows || 0 }}</div>
              </v-card>
            </v-col>
          </v-row>

          <v-card v-if="result && result.issues && result.issues.length" flat class="mapping-card pa-4 mb-4">
            <div class="subtitle-2 font-weight-bold mb-2">Sample skipped rows</div>
            <div v-for="issue in result.issues" :key="issue.row" class="caption mb-1">
              Row {{ issue.row }} — {{ issue.reason }}
            </div>
          </v-card>

          <div class="d-flex flex-wrap justify-end">
            <v-btn text color="#165134" class="mr-2" @click="startOver">Import Another</v-btn>
            <v-btn color="#165134" dark depressed to="/admin/accounting/bank-transactions">
              View Bank Transactions
            </v-btn>
          </div>
        </v-stepper-content>
      </v-stepper-items>
    </v-stepper>

    <v-card flat class="history-card">
      <v-card-title>
        <div>
          <div class="subtitle-1 font-weight-bold">Import History</div>
          <div class="caption grey--text">Audit trail for uploaded and imported bank statement files.</div>
        </div>
        <v-spacer/>
        <v-btn icon @click="loadHistory"><v-icon>mdi-refresh</v-icon></v-btn>
      </v-card-title>
      <v-divider/>
      <v-data-table
        :headers="historyHeaders"
        :items="history"
        :loading="historyLoading"
        :items-per-page="10"
      >
        <template v-slot:item.account="{item}">
          <div v-if="item.bank_account">
            <div class="font-weight-medium">{{ item.bank_account.name }}</div>
            <div class="caption grey--text">{{ item.bank_account.bank_name || 'Cash account' }}</div>
          </div>
        </template>
        <template v-slot:item.status="{item}">
          <v-chip x-small :color="historyStatusColor(item.status)" dark>{{ item.status }}</v-chip>
        </template>
        <template v-slot:item.counts="{item}">
          <div class="caption">
            {{ item.imported_rows || 0 }} imported · {{ item.duplicate_rows || 0 }} duplicate · {{ item.skipped_rows || 0 }} skipped
          </div>
        </template>
        <template v-slot:item.created_at="{item}">
          {{ dateTime(item.imported_at || item.created_at) }}
        </template>
      </v-data-table>
    </v-card>
  </div>
</template>

<script>
import api from '../../../services/api'

export default {
  name:'BankStatementImports',

  data(){
    return{
      step:1,
      uploading:false,
      refreshing:false,
      importing:false,
      historyLoading:false,
      upload:{bank_account_id:null,header_row:1,file:null},
      uploadErrors:{},
      bankAccounts:[],
      currentImport:null,
      duplicateFileWarning:null,
      headers:[],
      previewRows:[],
      detectedRows:0,
      mappingFields:[],
      mapping:{},
      amountMode:null,
      headerRow:1,
      statementOpeningBalance:'',
      statementClosingBalance:'',
      notes:'',
      mappingError:'',
      result:null,
      history:[],
      historyHeaders:[
        {text:'File',value:'original_filename'},
        {text:'Account',value:'account'},
        {text:'Status',value:'status'},
        {text:'Rows',value:'counts',sortable:false},
        {text:'Uploaded By',value:'uploaded_by.name'},
        {text:'Date',value:'created_at'}
      ]
    }
  },

  computed:{
    columnOptions(){
      return this.headers.map(function(header){
        return {value:header.index,text:header.label}
      })
    },

    usesSignedAmount(){
      return this.isMapped('amount') && !this.isMapped('deposit') && !this.isMapped('withdrawal')
    },

    currentAccountLabel(){
      if(!this.currentImport || !this.currentImport.bank_account)return ''
      const account=this.currentImport.bank_account
      return (account.bank_name ? account.bank_name+' · ' : '')+account.name
    }
  },

  async mounted(){
    await Promise.all([this.loadAccounts(),this.loadHistory()])
  },

  methods:{
    async loadAccounts(){
      const response=await api.get('/accounting/bank-transactions/options',{skipGlobalLoader:true})
      this.bankAccounts=(response.data.bank_accounts || []).map(function(item){
        const bank=item.bank_name ? item.bank_name+' · ' : ''
        const number=item.account_number ? ' · '+item.account_number : ''
        return Object.assign({},item,{display:bank+item.name+number})
      })
    },

    async loadHistory(){
      this.historyLoading=true
      try{
        const response=await api.get('/accounting/bank-statement-imports',{
          params:{per_page:50},
          skipGlobalLoader:true
        })
        this.history=(response.data && response.data.data) || []
      }finally{
        this.historyLoading=false
      }
    },

    async uploadPreview(){
      this.uploadErrors={}
      this.uploading=true

      try{
        const form=new FormData()
        form.append('bank_account_id',this.upload.bank_account_id)
        form.append('header_row',this.upload.header_row || 1)
        form.append('file',this.upload.file)

        const response=await api.post('/accounting/bank-statement-imports/preview',form,{skipGlobalError:true})
        this.applyPreview(response.data)
        this.currentImport=response.data.import
        this.duplicateFileWarning=response.data.duplicate_file_warning || null
        this.headerRow=Number(this.currentImport.header_row || 1)
        this.autoMap()
        this.step=2
      }catch(error){
        if(error.response && error.response.status === 422){
          this.uploadErrors=error.response.data.errors || {}
        }else{
          this.$root.$emit('show-error',this.errorMessage(error,'Unable to preview bank statement.'))
        }
      }finally{
        this.uploading=false
      }
    },

    applyPreview(data){
      this.headers=data.headers || []
      this.previewRows=data.rows || []
      this.detectedRows=Number(data.detected_rows || 0)
      this.mappingFields=data.mapping_fields || this.mappingFields
    },

    async refreshPreview(){
      if(!this.currentImport)return
      this.refreshing=true

      try{
        const response=await api.post(
          '/accounting/bank-statement-imports/'+this.currentImport.id+'/preview',
          {header_row:this.headerRow},
          {skipGlobalError:true}
        )
        this.currentImport=response.data.import
        this.applyPreview(response.data)
        this.autoMap()
      }catch(error){
        this.$root.$emit('show-error',this.errorMessage(error,'Unable to re-read the statement.'))
      }finally{
        this.refreshing=false
      }
    },

    autoMap(){
      const mapping={}
      ;(this.mappingFields || []).forEach(function(field){mapping[field.key]=null})
      this.mapping=mapping

      const synonyms={
        transaction_date:['transactiondate','txndate','postingdate','date'],
        value_date:['valuedate'],
        deposit:['creditamount','credits','credit','depositamount','deposits','deposit','moneyin','cr'],
        withdrawal:['debitamount','debits','debit','withdrawalamount','withdrawals','withdrawal','moneyout','dr'],
        amount:['transactionamount','txnamount','amount'],
        running_balance:['runningbalance','closingbalance','availablebalance','balance'],
        reference_number:['referencenumber','referenceno','reference','refno','ref'],
        cheque_number:['chequenumber','chequeno','checknumber','checkno','cheque'],
        external_transaction_id:['transactionid','transactionnumber','txnid','txnnumber','banktransactionid'],
        description:['description','narration','particulars','remarks','details','memo']
      }

      const normalizedHeaders=this.headers.map(header=>{
        return {
          index:header.index,
          normalized:this.normalizeHeader(header.label)
        }
      })

      Object.keys(synonyms).forEach(key=>{
        const wanted=synonyms[key]
        const found=normalizedHeaders.find(header=>wanted.indexOf(header.normalized)!==-1)
        if(found)this.$set(this.mapping,key,found.index)
      })

      if(this.isMapped('deposit') || this.isMapped('withdrawal')){
        this.$set(this.mapping,'amount',null)
        this.amountMode=null
      }
    },

    normalizeHeader(value){
      return String(value || '').toLowerCase().replace(/[^a-z0-9]/g,'')
    },

    isMapped(key){
      return this.mapping[key] !== null && this.mapping[key] !== undefined && this.mapping[key] !== ''
    },

    validateMapping(){
      if(!this.isMapped('transaction_date')){
        return 'Map the Transaction Date column.'
      }

      const split=this.isMapped('deposit') || this.isMapped('withdrawal')
      const amount=this.isMapped('amount')

      if(!split && !amount){
        return 'Map Deposit/Withdrawal columns or one signed Amount column.'
      }

      if(split && this.isMapped('deposit') && this.isMapped('withdrawal') &&
        Number(this.mapping.deposit) === Number(this.mapping.withdrawal)){
        return 'Deposit and Withdrawal cannot use the same column.'
      }

      if(amount && !split && !this.amountMode){
        return 'Choose how positive values in the Amount column should be interpreted.'
      }

      return ''
    },

    async commitImport(){
      this.mappingError=this.validateMapping()
      if(this.mappingError)return

      this.importing=true

      try{
        const response=await api.post(
          '/accounting/bank-statement-imports/'+this.currentImport.id+'/commit',
          {
            mapping:this.mapping,
            amount_mode:this.usesSignedAmount ? this.amountMode : null,
            statement_opening_balance:this.statementOpeningBalance === '' ? null : this.statementOpeningBalance,
            statement_closing_balance:this.statementClosingBalance === '' ? null : this.statementClosingBalance,
            notes:this.notes || null
          },
          {skipGlobalError:true}
        )

        this.result=response.data.summary || {}
        this.currentImport=response.data.import
        this.step=3
        await this.loadHistory()
      }catch(error){
        if(error.response && error.response.status === 422){
          const errors=error.response.data.errors || {}
          const first=Object.keys(errors)[0]
          this.mappingError=first && errors[first] ? errors[first][0] : (error.response.data.message || 'Import validation failed.')
        }else{
          this.$root.$emit('show-error',this.errorMessage(error,'Unable to import bank statement.'))
        }
      }finally{
        this.importing=false
      }
    },

    startOver(){
      this.step=1
      this.upload={bank_account_id:null,header_row:1,file:null}
      this.uploadErrors={}
      this.currentImport=null
      this.duplicateFileWarning=null
      this.headers=[]
      this.previewRows=[]
      this.detectedRows=0
      this.mapping={}
      this.amountMode=null
      this.headerRow=1
      this.statementOpeningBalance=''
      this.statementClosingBalance=''
      this.notes=''
      this.mappingError=''
      this.result=null
    },

    historyStatusColor(status){
      if(status === 'imported')return 'success'
      if(status === 'failed')return 'error'
      return 'warning'
    },

    dateTime(value){
      if(!value)return '—'
      const date=new Date(value)
      if(Number.isNaN(date.getTime()))return value
      return date.toLocaleString('en-PK',{dateStyle:'medium',timeStyle:'short'})
    },

    errorMessage(error,fallback){
      return (error.response && error.response.data && error.response.data.message) || fallback
    }
  }
}
</script>

<style scoped>
.statement-import-page{width:100%}
.hero{border-left:4px solid #165134}
.stepper-card,.mapping-card,.preview-card,.history-card,.summary-card{border:1px solid rgba(22,81,52,.08);border-radius:15px!important}
.header-row-field{max-width:150px}
.preview-scroll{overflow:auto;max-height:440px}
.preview-table{border-collapse:collapse;min-width:100%;white-space:nowrap}
.preview-table th,.preview-table td{padding:10px 12px;border-bottom:1px solid rgba(128,128,128,.18);text-align:left;font-size:13px}
.preview-table th{position:sticky;top:0;background:var(--v-background-base,#fff);z-index:1;font-weight:600}
.theme--dark .preview-table th{background:#121a16}
</style>
