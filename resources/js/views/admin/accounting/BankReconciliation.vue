<template>
  <div class="bank-reconciliation-page">
    <v-card flat class="hero pa-5 mb-4">
      <div class="d-flex align-center flex-wrap">
        <div>
          <div class="text-overline">BANK CONTROL</div>
          <h1 class="text-h5 font-weight-bold mb-1">Bank Reconciliation</h1>
          <div class="grey--text">Match statement transactions to posted GL movements and close the reconciliation only when the difference is zero.</div>
        </div>
        <v-spacer/>
        <v-btn text color="#165134" to="/admin/accounting/bank-statement-imports">
          <v-icon left>mdi-file-upload-outline</v-icon>
          Statement Import
        </v-btn>
      </div>
    </v-card>

    <v-card flat class="filter-card pa-4 mb-4">
      <v-row dense align="center">
        <v-col cols="12" md="7">
          <v-autocomplete
            v-model="selectedImportId"
            :items="statementImports"
            item-text="display"
            item-value="id"
            outlined
            dense
            hide-details
            label="Statement Import"
            @change="loadWorkspace(false)"
          />
        </v-col>
        <v-col cols="12" md="3">
          <v-text-field
            v-model="closingBalance"
            type="number"
            step="0.01"
            outlined
            dense
            hide-details
            prefix="PKR"
            label="Statement Closing Balance"
            :disabled="!workspace || isCompleted"
          />
        </v-col>
        <v-col cols="12" md="2">
          <v-btn
            block
            outlined
            color="#165134"
            :disabled="!workspace || closingBalance === '' || closingBalance === null"
            :loading="loading"
            @click="recalculate"
          >
            Recalculate
          </v-btn>
        </v-col>
      </v-row>
    </v-card>

    <v-alert v-if="!selectedImportId && !loading" type="info" text>
      Import a bank statement first, then select it here for reconciliation.
    </v-alert>

    <template v-if="workspace">
      <v-alert v-if="isCompleted" type="success" text prominent class="mb-4">
        <div class="d-flex align-center flex-wrap">
          <div>
            <div class="font-weight-bold">Reconciliation completed</div>
            <div class="caption">
              {{ workspace.reconciliation.completed_at ? dateTime(workspace.reconciliation.completed_at) : '' }}
              <span v-if="workspace.reconciliation.completed_by"> · {{ workspace.reconciliation.completed_by.name }}</span>
            </div>
          </div>
          <v-spacer/>
          <v-btn
            v-if="$can('accounting.edit')"
            outlined
            color="warning"
            class="mt-2 mt-md-0"
            @click="reopenDialog=true"
          >
            <v-icon left>mdi-lock-open-variant-outline</v-icon>
            Reopen
          </v-btn>
        </div>
      </v-alert>

      <v-alert v-else-if="workspace.reconciliation && workspace.reconciliation.status === 'reopened'" type="warning" text dense>
        This reconciliation was reopened. Review changes and complete it again when the statement is fully matched and balanced.
      </v-alert>

      <v-row class="mb-1">
        <v-col cols="6" md="3">
          <v-card flat class="summary-card pa-4">
            <div class="caption grey--text">Statement Balance</div>
            <div class="text-h6 font-weight-bold">PKR {{ money(summary.statement_closing_balance) }}</div>
          </v-card>
        </v-col>
        <v-col cols="6" md="3">
          <v-card flat class="summary-card pa-4">
            <div class="caption grey--text">Outstanding Deposits</div>
            <div class="text-h6 font-weight-bold success--text">+ PKR {{ money(summary.outstanding_deposits) }}</div>
          </v-card>
        </v-col>
        <v-col cols="6" md="3">
          <v-card flat class="summary-card pa-4">
            <div class="caption grey--text">Outstanding Payments</div>
            <div class="text-h6 font-weight-bold error--text">− PKR {{ money(summary.outstanding_payments) }}</div>
          </v-card>
        </v-col>
        <v-col cols="6" md="3">
          <v-card flat class="summary-card pa-4">
            <div class="caption grey--text">Adjusted Bank Balance</div>
            <div class="text-h6 font-weight-bold">PKR {{ money(summary.adjusted_bank_balance) }}</div>
          </v-card>
        </v-col>
        <v-col cols="6" md="3">
          <v-card flat class="summary-card pa-4">
            <div class="caption grey--text">GL Balance</div>
            <div class="text-h6 font-weight-bold">PKR {{ money(summary.gl_balance) }}</div>
          </v-card>
        </v-col>
        <v-col cols="6" md="3">
          <v-card flat class="summary-card pa-4" :class="summary.balanced ? 'balanced-card' : 'difference-card'">
            <div class="caption grey--text">Difference</div>
            <div class="text-h6 font-weight-bold" :class="summary.balanced ? 'success--text' : 'error--text'">
              PKR {{ money(summary.difference) }}
            </div>
          </v-card>
        </v-col>
        <v-col cols="6" md="3">
          <v-card flat class="summary-card pa-4">
            <div class="caption grey--text">Matched Statement Rows</div>
            <div class="text-h6 font-weight-bold">{{ summary.matched_bank_count || 0 }}</div>
          </v-card>
        </v-col>
        <v-col cols="6" md="3">
          <v-card flat class="summary-card pa-4">
            <div class="caption grey--text">Unmatched Statement Rows</div>
            <div class="text-h6 font-weight-bold" :class="Number(summary.unmatched_bank_count || 0) ? 'warning--text' : 'success--text'">
              {{ summary.unmatched_bank_count || 0 }}
            </div>
          </v-card>
        </v-col>
      </v-row>

      <v-card flat class="equation-card pa-4 mb-4">
        <div class="d-flex align-center flex-wrap">
          <div>
            <div class="subtitle-2 font-weight-bold">Reconciliation equation</div>
            <div class="caption grey--text mt-1">
              Statement balance + outstanding deposits − outstanding payments = adjusted bank balance; adjusted bank balance − GL balance = difference.
            </div>
          </div>
          <v-spacer/>
          <div class="caption mt-2 mt-md-0">
            Period: <strong>{{ workspace.period.from }}</strong> to <strong>{{ workspace.period.to }}</strong>
          </div>
        </div>
      </v-card>

      <v-card flat class="table-card mb-4">
        <v-card-title class="d-flex align-center flex-wrap">
          <div>
            <div class="subtitle-1 font-weight-bold">Statement Transactions</div>
            <div class="caption grey--text">{{ statementLabel }}</div>
          </div>
          <v-spacer/>
          <v-btn
            v-if="$can('accounting.edit') && editable"
            color="#165134"
            dark
            depressed
            :loading="autoMatching"
            @click="autoMatch"
          >
            <v-icon left>mdi-auto-fix</v-icon>
            Auto Match
          </v-btn>
        </v-card-title>
        <v-divider/>

        <v-data-table
          :headers="headers"
          :items="transactions"
          :items-per-page="25"
          mobile-breakpoint="1000"
        >
          <template v-slot:item.transaction_date="{item}">
            <div class="font-weight-medium">{{ dateLabel(item.transaction_date) }}</div>
            <div class="caption grey--text">{{ item.reference_number || item.cheque_number || item.external_transaction_id || 'No reference' }}</div>
          </template>

          <template v-slot:item.transaction="{item}">
            <v-chip x-small :color="item.transaction_type === 'deposit' ? 'success' : 'error'" dark class="mb-1">
              {{ item.transaction_type === 'deposit' ? 'Deposit' : 'Withdrawal' }}
            </v-chip>
            <div class="font-weight-bold">
              {{ item.transaction_type === 'deposit' ? '+' : '-' }} PKR {{ money(item.amount) }}
            </div>
            <div class="caption grey--text transaction-description">{{ item.description || '—' }}</div>
          </template>

          <template v-slot:item.match="{item}">
            <div v-if="item.reconciliation_match">
              <div class="font-weight-medium primary--text">
                {{ matchedEntry(item).entry_number || 'Journal' }}
              </div>
              <div class="caption">{{ dateLabel(matchedEntry(item).entry_date) }}</div>
              <div class="caption grey--text transaction-description">
                {{ matchedEntry(item).description || 'Posted GL movement' }}
              </div>
              <v-chip
                v-if="item.reconciliation_adjustment && !item.reconciliation_adjustment.reversed_at"
                x-small
                outlined
                color="deep-purple"
                class="mt-1 mr-1"
              >
                ERP adjustment · {{ adjustmentTypeLabel(item.reconciliation_adjustment.adjustment_type) }}
              </v-chip>
              <v-chip x-small outlined :color="item.reconciliation_match.match_method === 'automatic' ? 'info' : 'primary'" class="mt-1">
                {{ item.reconciliation_match.match_method }}
                <span v-if="item.reconciliation_match.confidence_score !== null"> · {{ item.reconciliation_match.confidence_score }}%</span>
              </v-chip>
            </div>

            <div v-else-if="bestSuggestion(item)">
              <div class="font-weight-medium">{{ bestSuggestion(item).entry_number }}</div>
              <div class="caption">{{ dateLabel(bestSuggestion(item).entry_date) }} · PKR {{ candidateAmount(bestSuggestion(item)) }}</div>
              <div class="caption grey--text transaction-description">
                {{ bestSuggestion(item).entry_description || bestSuggestion(item).line_description || 'Suggested GL movement' }}
              </div>
              <v-chip x-small :color="confidenceColor(bestSuggestion(item).confidence)" dark class="mt-1">
                {{ bestSuggestion(item).score }}% · {{ bestSuggestion(item).confidence }}
              </v-chip>
            </div>

            <span v-else class="caption grey--text">No close candidate</span>
          </template>

          <template v-slot:item.status="{item}">
            <v-chip x-small :color="statusColor(item.reconciliation_status)" dark>
              {{ statusLabel(item.reconciliation_status) }}
            </v-chip>
          </template>

          <template v-slot:item.actions="{item}">
            <div class="d-flex justify-end">
              <template v-if="editable && $can('accounting.edit')">
                <v-btn
                  v-if="!item.reconciliation_match && bestSuggestion(item)"
                  small
                  text
                  color="#165134"
                  @click="matchSuggested(item,bestSuggestion(item))"
                >
                  Match
                </v-btn>
                <v-btn
                  v-if="!item.reconciliation_match"
                  icon
                  small
                  title="Review candidates"
                  @click="openCandidates(item)"
                >
                  <v-icon small>mdi-magnify</v-icon>
                </v-btn>
                <v-btn
                  v-if="!item.reconciliation_match"
                  icon
                  small
                  color="deep-purple"
                  title="Create missing journal adjustment"
                  @click="openAdjustment(item)"
                >
                  <v-icon small>mdi-book-plus-outline</v-icon>
                </v-btn>
                <v-btn
                  v-if="item.reconciliation_match && item.reconciliation_adjustment && !item.reconciliation_adjustment.reversed_at"
                  icon
                  small
                  color="warning"
                  title="Reverse ERP adjustment"
                  @click="openAdjustmentReverse(item)"
                >
                  <v-icon small>mdi-book-arrow-left-outline</v-icon>
                </v-btn>
                <v-btn
                  v-else-if="item.reconciliation_match"
                  icon
                  small
                  color="error"
                  title="Unmatch"
                  @click="unmatch(item)"
                >
                  <v-icon small>mdi-link-variant-off</v-icon>
                </v-btn>
              </template>
              <v-icon v-else-if="item.reconciliation_status === 'reconciled'" small color="success">mdi-lock-check-outline</v-icon>
            </div>
          </template>
        </v-data-table>
      </v-card>

      <v-card v-if="editable && $can('accounting.edit')" flat class="finalize-card pa-4 mb-4">
        <div class="d-flex align-start flex-wrap">
          <div class="flex-grow-1 mr-md-4">
            <div class="subtitle-1 font-weight-bold">Complete Reconciliation</div>
            <div class="caption grey--text mb-3">
              Completion is allowed only when every statement transaction is matched and the difference is PKR 0.00.
            </div>
            <v-row dense>
              <v-col cols="12" md="4">
                <v-text-field
                  v-model="openingBalance"
                  type="number"
                  step="0.01"
                  outlined dense
                  prefix="PKR"
                  label="Statement Opening Balance"
                />
              </v-col>
              <v-col cols="12" md="8">
                <v-text-field v-model="notes" outlined dense label="Reconciliation Notes"/>
              </v-col>
            </v-row>
          </div>
          <div class="align-self-center">
            <v-btn
              color="#165134"
              dark
              depressed
              large
              :loading="finalizing"
              :disabled="!canFinalize"
              @click="finalizeReconciliation"
            >
              <v-icon left>mdi-check-decagram-outline</v-icon>
              Complete
            </v-btn>
          </div>
        </div>
      </v-card>
    </template>

    <v-card flat class="history-card">
      <v-card-title>
        <div>
          <div class="subtitle-1 font-weight-bold">Reconciliation History</div>
          <div class="caption grey--text">Completed and reopened bank reconciliations.</div>
        </div>
        <v-spacer/>
        <v-btn icon @click="loadHistory"><v-icon>mdi-refresh</v-icon></v-btn>
      </v-card-title>
      <v-divider/>
      <v-data-table :headers="historyHeaders" :items="history" :loading="historyLoading" :items-per-page="10">
        <template v-slot:item.account="{item}">
          <div v-if="item.bank_account">
            <div class="font-weight-medium">{{ item.bank_account.name }}</div>
            <div class="caption grey--text">{{ item.bank_account.bank_name || 'Cash account' }}</div>
          </div>
        </template>
        <template v-slot:item.period="{item}">
          {{ item.from_date }} → {{ item.to_date }}
        </template>
        <template v-slot:item.status="{item}">
          <v-chip x-small :color="item.status === 'completed' ? 'success' : (item.status === 'reopened' ? 'warning' : 'grey')" dark>
            {{ item.status }}
          </v-chip>
        </template>
        <template v-slot:item.difference="{item}">
          <span :class="Math.abs(Number(item.difference || 0)) <= 0.01 ? 'success--text' : 'error--text'">
            PKR {{ money(item.difference) }}
          </span>
        </template>
        <template v-slot:item.completed_at="{item}">
          {{ dateTime(item.completed_at || item.updated_at) }}
        </template>
      </v-data-table>
    </v-card>

    <v-dialog v-model="candidateDialog" max-width="900" persistent>
      <v-card>
        <v-card-title>
          <div>
            <div class="text-h6 font-weight-bold">Choose GL Movement</div>
            <div v-if="selectedTransaction" class="caption grey--text">
              {{ dateLabel(selectedTransaction.transaction_date) }} ·
              {{ selectedTransaction.transaction_type === 'deposit' ? 'Deposit' : 'Withdrawal' }} ·
              PKR {{ money(selectedTransaction.amount) }}
            </div>
          </div>
          <v-spacer/>
          <v-btn icon @click="candidateDialog=false"><v-icon>mdi-close</v-icon></v-btn>
        </v-card-title>
        <v-divider/>
        <v-card-text class="pt-4">
          <v-row dense align="center">
            <v-col cols="12" md="4">
              <v-select
                v-model="candidateDays"
                :items="[5,10,30,60,90]"
                outlined dense hide-details
                label="Search ± days"
                @change="loadCandidates"
              />
            </v-col>
            <v-col cols="12" md="8">
              <div class="caption grey--text">
                Candidates must have the exact same amount and deposit/withdrawal direction. The date range only broadens the search.
              </div>
            </v-col>
          </v-row>

          <v-data-table
            class="mt-4"
            :headers="candidateHeaders"
            :items="candidates"
            :loading="candidatesLoading"
            :items-per-page="10"
          >
            <template v-slot:item.amount="{item}">
              PKR {{ candidateAmount(item) }}
            </template>
            <template v-slot:item.score="{item}">
              <v-chip x-small :color="confidenceColor(item.confidence)" dark>{{ item.score }}%</v-chip>
            </template>
            <template v-slot:item.actions="{item}">
              <v-btn small color="#165134" dark depressed @click="manualMatch(item)">Match</v-btn>
            </template>
            <template v-slot:no-data>
              <div class="pa-8 text-center grey--text">No exact-amount GL candidates in this date window.</div>
            </template>
          </v-data-table>
        </v-card-text>
      </v-card>
    </v-dialog>


    <v-dialog v-model="adjustmentDialog" max-width="820" persistent>
      <v-card>
        <v-card-title>
          <div>
            <div class="text-h6 font-weight-bold">Create Reconciliation Adjustment</div>
            <div v-if="adjustmentTransaction" class="caption grey--text">
              {{ dateLabel(adjustmentTransaction.transaction_date) }} ·
              {{ adjustmentTransaction.transaction_type === 'deposit' ? 'Deposit / Money In' : 'Withdrawal / Money Out' }} ·
              PKR {{ money(adjustmentTransaction.amount) }}
            </div>
          </div>
          <v-spacer/>
          <v-btn icon @click="adjustmentDialog=false"><v-icon>mdi-close</v-icon></v-btn>
        </v-card-title>
        <v-divider/>
        <v-card-text class="pt-5">
          <v-alert type="info" text dense>
            This creates and immediately posts a balanced journal entry for the exact bank-statement amount, then matches its bank line to this statement row.
          </v-alert>
          <v-row>
            <v-col cols="12" md="6">
              <v-select
                v-model="adjustmentForm.adjustment_type"
                :items="adjustmentTypes"
                item-text="text"
                item-value="value"
                outlined dense
                label="Adjustment Type *"
                :error-messages="adjustmentErrors.adjustment_type"
              />
            </v-col>
            <v-col cols="12" md="6">
              <v-autocomplete
                v-model="adjustmentForm.offset_account_id"
                :items="offsetAccounts"
                item-text="display"
                item-value="id"
                outlined dense
                label="Offset GL Account *"
                :error-messages="adjustmentErrors.offset_account_id"
              >
                <template v-slot:item="{item}">
                  <v-list-item-content>
                    <v-list-item-title>{{ item.display }}</v-list-item-title>
                    <v-list-item-subtitle>{{ accountTypeLabel(item.account_type) }}</v-list-item-subtitle>
                  </v-list-item-content>
                </template>
              </v-autocomplete>
            </v-col>
            <v-col cols="12" md="6">
              <v-autocomplete
                v-model="adjustmentForm.project_id"
                :items="adjustmentProjects"
                item-text="display"
                item-value="id"
                outlined dense clearable
                label="Project"
                :error-messages="adjustmentErrors.project_id"
              />
            </v-col>
            <v-col cols="12" md="6">
              <v-autocomplete
                v-model="adjustmentForm.customer_id"
                :items="adjustmentCustomers"
                item-text="display"
                item-value="id"
                outlined dense clearable
                label="Customer"
                :error-messages="adjustmentErrors.customer_id"
              />
            </v-col>
            <v-col cols="12">
              <v-textarea
                v-model="adjustmentForm.description"
                outlined dense rows="2"
                label="Journal Description *"
                :error-messages="adjustmentErrors.description"
              />
            </v-col>
          </v-row>

          <v-card v-if="adjustmentTransaction && selectedOffsetAccount" flat class="journal-preview pa-4">
            <div class="subtitle-2 font-weight-bold mb-3">Journal Preview</div>
            <div class="d-flex justify-space-between mb-2">
              <span>{{ adjustmentTransaction.transaction_type === 'deposit' ? bankAccountName : selectedOffsetAccount.display }}</span>
              <strong>{{ adjustmentTransaction.transaction_type === 'deposit' ? 'Dr' : 'Dr' }} PKR {{ money(adjustmentTransaction.amount) }}</strong>
            </div>
            <div class="d-flex justify-space-between">
              <span>{{ adjustmentTransaction.transaction_type === 'deposit' ? selectedOffsetAccount.display : bankAccountName }}</span>
              <strong>Cr PKR {{ money(adjustmentTransaction.amount) }}</strong>
            </div>
          </v-card>
        </v-card-text>
        <v-card-actions>
          <v-spacer/>
          <v-btn text @click="adjustmentDialog=false">Cancel</v-btn>
          <v-btn color="deep-purple" dark depressed :loading="adjustmentSaving" @click="createAdjustment">
            Post & Match
          </v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>

    <v-dialog v-model="adjustmentReverseDialog" max-width="580" persistent>
      <v-card>
        <v-card-title>Reverse Reconciliation Adjustment</v-card-title>
        <v-card-text>
          <v-alert type="warning" text dense>
            This posts a formal reversing journal entry and returns the statement row to Unmatched. The original adjustment remains in the audit trail.
          </v-alert>
          <v-text-field
            v-model="adjustmentReverseForm.entry_date"
            type="date"
            outlined dense
            label="Reversal Date *"
            :error-messages="adjustmentReverseErrors.entry_date"
          />
          <v-textarea
            v-model="adjustmentReverseForm.reason"
            outlined rows="3"
            label="Reason *"
            :error-messages="adjustmentReverseErrors.reason"
          />
        </v-card-text>
        <v-card-actions>
          <v-spacer/>
          <v-btn text @click="adjustmentReverseDialog=false">Cancel</v-btn>
          <v-btn color="warning" dark depressed :loading="adjustmentReversing" @click="reverseAdjustment">
            Reverse Adjustment
          </v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>

    <v-dialog v-model="reopenDialog" max-width="560" persistent>
      <v-card>
        <v-card-title>Reopen Reconciliation</v-card-title>
        <v-card-text>
          <v-alert type="warning" text dense>
            Reopening unlocks the matched statement rows for review. The existing match audit remains attached to this reconciliation.
          </v-alert>
          <v-textarea v-model="reopenReason" outlined rows="3" label="Reason for reopening *"/>
        </v-card-text>
        <v-card-actions>
          <v-spacer/>
          <v-btn text @click="reopenDialog=false">Cancel</v-btn>
          <v-btn color="warning" dark depressed :loading="reopening" :disabled="reopenReason.trim().length < 5" @click="reopen">
            Reopen
          </v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>
  </div>
</template>

<script>
import api from '../../../services/api'

export default {
  name:'BankReconciliation',

  data(){
    return{
      loading:false,
      autoMatching:false,
      finalizing:false,
      historyLoading:false,
      selectedImportId:null,
      statementImports:[],
      workspace:null,
      closingBalance:'',
      openingBalance:'',
      notes:'',
      candidateDialog:false,
      selectedTransaction:null,
      candidates:[],
      candidatesLoading:false,
      candidateDays:30,
      reopenDialog:false,
      reopenReason:'',
      reopening:false,
      adjustmentDialog:false,
      adjustmentTransaction:null,
      adjustmentSaving:false,
      adjustmentErrors:{},
      adjustmentTypes:[],
      offsetAccounts:[],
      adjustmentProjects:[],
      adjustmentCustomers:[],
      adjustmentForm:{
        adjustment_type:'custom',
        offset_account_id:null,
        project_id:null,
        customer_id:null,
        description:''
      },
      adjustmentReverseDialog:false,
      adjustmentReverseItem:null,
      adjustmentReversing:false,
      adjustmentReverseErrors:{},
      adjustmentReverseForm:{entry_date:'',reason:''},
      history:[],
      headers:[
        {text:'Statement Date / Ref',value:'transaction_date'},
        {text:'Bank Transaction',value:'transaction'},
        {text:'GL Match / Suggestion',value:'match'},
        {text:'Status',value:'status'},
        {text:'Actions',value:'actions',sortable:false,align:'right'}
      ],
      candidateHeaders:[
        {text:'Journal',value:'entry_number'},
        {text:'Date',value:'entry_date'},
        {text:'Description',value:'entry_description'},
        {text:'Amount',value:'amount',align:'right',sortable:false},
        {text:'Confidence',value:'score'},
        {text:'',value:'actions',sortable:false,align:'right'}
      ],
      historyHeaders:[
        {text:'Account',value:'account'},
        {text:'Period',value:'period',sortable:false},
        {text:'Status',value:'status'},
        {text:'Adjusted Bank',value:'adjusted_bank_balance'},
        {text:'GL Balance',value:'gl_balance'},
        {text:'Difference',value:'difference'},
        {text:'Completed / Updated',value:'completed_at'}
      ]
    }
  },

  computed:{
    transactions(){
      return (this.workspace && this.workspace.transactions) || []
    },

    summary(){
      return (this.workspace && this.workspace.summary) || {}
    },

    isCompleted(){
      return !!(this.workspace && this.workspace.reconciliation && this.workspace.reconciliation.status === 'completed')
    },

    editable(){
      return !!this.workspace && !this.isCompleted
    },

    canFinalize(){
      if(!this.workspace || this.isCompleted || this.closingBalance === '' || this.closingBalance === null)return false
      return Number(this.summary.unmatched_bank_count || 0) === 0 && this.summary.balanced === true
    },

    statementLabel(){
      if(!this.workspace || !this.workspace.statement_import)return ''
      const imp=this.workspace.statement_import
      const account=imp.bank_account || {}
      return (account.bank_name ? account.bank_name+' · ' : '')+account.name+' · '+imp.original_filename
    },

    selectedOffsetAccount(){
      const id=Number(this.adjustmentForm.offset_account_id || 0)
      return this.offsetAccounts.find(function(account){return Number(account.id)===id}) || null
    },

    bankAccountName(){
      if(!this.workspace || !this.workspace.statement_import || !this.workspace.statement_import.bank_account)return 'Bank Account'
      const account=this.workspace.statement_import.bank_account
      return account.name || 'Bank Account'
    }
  },

  async mounted(){
    await Promise.all([this.loadOptions(),this.loadHistory()])
    if(this.statementImports.length){
      const open=this.statementImports.find(function(item){
        return !item.reconciliation || item.reconciliation.status !== 'completed'
      })
      this.selectedImportId=(open || this.statementImports[0]).id
      await this.loadWorkspace(false)
    }
  },

  methods:{
    async loadOptions(){
      const response=await api.get('/accounting/bank-reconciliations/options',{skipGlobalLoader:true})
      this.statementImports=(response.data.statement_imports || []).map(function(item){
        const account=item.bank_account || {}
        const status=item.reconciliation ? ' · '+item.reconciliation.status : ''
        const when=item.imported_at ? ' · '+String(item.imported_at).slice(0,10) : ''
        return Object.assign({},item,{
          display:(account.bank_name ? account.bank_name+' · ' : '')+
            (account.name || 'Bank account')+' · '+item.original_filename+when+status
        })
      })
    },

    async loadHistory(){
      this.historyLoading=true
      try{
        const response=await api.get('/accounting/bank-reconciliations',{
          params:{per_page:50},
          skipGlobalLoader:true
        })
        this.history=(response.data && response.data.data) || []
      }finally{
        this.historyLoading=false
      }
    },

    async loadWorkspace(useBalance){
      if(!this.selectedImportId){
        this.workspace=null
        return
      }

      this.loading=true
      try{
        const params={bank_statement_import_id:this.selectedImportId}
        if(useBalance && this.closingBalance !== '' && this.closingBalance !== null){
          params.statement_closing_balance=this.closingBalance
        }

        const response=await api.get('/accounting/bank-reconciliations/workspace',{
          params:params,
          skipGlobalLoader:true
        })

        this.workspace=response.data

        if(!useBalance){
          this.closingBalance=response.data.statement_closing_balance === null ? '' : response.data.statement_closing_balance
          const reconciliation=response.data.reconciliation
          const statement=response.data.statement_import
          this.openingBalance=reconciliation && reconciliation.statement_opening_balance !== null
            ? reconciliation.statement_opening_balance
            : (statement.statement_opening_balance === null ? '' : statement.statement_opening_balance)
          this.notes=(reconciliation && reconciliation.notes) || ''
        }
      }catch(error){
        this.workspace=null
        this.$root.$emit('show-error',this.errorMessage(error,'Unable to load reconciliation workspace.'))
      }finally{
        this.loading=false
      }
    },

    recalculate(){
      this.loadWorkspace(true)
    },

    async autoMatch(){
      if(!this.selectedImportId)return
      this.autoMatching=true
      try{
        const response=await api.post(
          '/accounting/bank-statement-imports/'+this.selectedImportId+'/auto-match',
          {},
          {skipGlobalError:true}
        )
        const s=response.data.summary || {}
        this.$root.$emit(
          'show-success',
          (s.matched || 0)+' auto-matched · '+(s.ambiguous || 0)+' need review · '+(s.no_candidate || 0)+' without candidates'
        )
        await this.loadWorkspace(true)
      }catch(error){
        this.$root.$emit('show-error',this.errorMessage(error,'Unable to auto-match statement transactions.'))
      }finally{
        this.autoMatching=false
      }
    },

    bestSuggestion(item){
      return item.suggestions && item.suggestions.length ? item.suggestions[0] : null
    },

    matchedEntry(item){
      if(!item.reconciliation_match || !item.reconciliation_match.journal_line)return {}
      return item.reconciliation_match.journal_line.entry || {}
    },

    async matchSuggested(item,suggestion){
      await this.performMatch(item,suggestion.journal_line_id)
    },

    async openCandidates(item){
      this.selectedTransaction=item
      this.candidates=[]
      this.candidateDays=30
      this.candidateDialog=true
      await this.loadCandidates()
    },

    async loadCandidates(){
      if(!this.selectedTransaction)return
      this.candidatesLoading=true
      try{
        const response=await api.get(
          '/accounting/bank-transactions/'+this.selectedTransaction.id+'/reconciliation-candidates',
          {
            params:{days:this.candidateDays},
            skipGlobalLoader:true
          }
        )
        this.candidates=response.data.candidates || []
      }catch(error){
        this.$root.$emit('show-error',this.errorMessage(error,'Unable to load GL candidates.'))
      }finally{
        this.candidatesLoading=false
      }
    },

    async manualMatch(candidate){
      if(!this.selectedTransaction)return
      const ok=await this.performMatch(this.selectedTransaction,candidate.journal_line_id)
      if(ok)this.candidateDialog=false
    },

    async performMatch(transaction,journalLineId){
      try{
        await api.post(
          '/accounting/bank-transactions/'+transaction.id+'/reconciliation-match',
          {journal_line_id:journalLineId},
          {skipGlobalError:true}
        )
        await this.loadWorkspace(true)
        return true
      }catch(error){
        this.$root.$emit('show-error',this.errorMessage(error,'Unable to match this transaction.'))
        return false
      }
    },

    async unmatch(item){
      try{
        await api.delete(
          '/accounting/bank-transactions/'+item.id+'/reconciliation-match',
          {skipGlobalError:true}
        )
        await this.loadWorkspace(true)
      }catch(error){
        this.$root.$emit('show-error',this.errorMessage(error,'Unable to unmatch this transaction.'))
      }
    },

    async finalizeReconciliation(){
      this.finalizing=true
      try{
        await api.post(
          '/accounting/bank-reconciliations/finalize',
          {
            bank_statement_import_id:this.selectedImportId,
            statement_opening_balance:this.openingBalance === '' ? null : this.openingBalance,
            statement_closing_balance:this.closingBalance,
            notes:this.notes || null
          },
          {skipGlobalError:true}
        )
        await Promise.all([this.loadOptions(),this.loadHistory()])
        await this.loadWorkspace(true)
      }catch(error){
        this.$root.$emit('show-error',this.errorMessage(error,'Unable to complete reconciliation.'))
      }finally{
        this.finalizing=false
      }
    },

    async reopen(){
      if(!this.workspace || !this.workspace.reconciliation)return
      this.reopening=true
      try{
        await api.post(
          '/accounting/bank-reconciliations/'+this.workspace.reconciliation.id+'/reopen',
          {reason:this.reopenReason},
          {skipGlobalError:true}
        )
        this.reopenDialog=false
        this.reopenReason=''
        await Promise.all([this.loadOptions(),this.loadHistory()])
        await this.loadWorkspace(true)
      }catch(error){
        this.$root.$emit('show-error',this.errorMessage(error,'Unable to reopen reconciliation.'))
      }finally{
        this.reopening=false
      }
    },


    async openAdjustment(item){
      this.adjustmentTransaction=item
      this.adjustmentErrors={}
      this.adjustmentDialog=true
      this.adjustmentForm={
        adjustment_type:'custom',
        offset_account_id:null,
        project_id:item.project_id || null,
        customer_id:item.customer_id || null,
        description:item.description || ('Bank reconciliation adjustment '+(item.reference_number || item.cheque_number || item.external_transaction_id || '#'+item.id))
      }

      try{
        const response=await api.get(
          '/accounting/bank-transactions/'+item.id+'/reconciliation-adjustment-options',
          {skipGlobalLoader:true,skipGlobalError:true}
        )
        this.adjustmentTypes=response.data.adjustment_types || []
        this.offsetAccounts=response.data.offset_accounts || []
        this.adjustmentProjects=(response.data.projects || []).map(function(project){
          return Object.assign({},project,{display:(project.code ? project.code+' · ' : '')+project.name})
        })
        this.adjustmentCustomers=(response.data.customers || []).map(function(customer){
          return Object.assign({},customer,{display:(customer.customer_number ? customer.customer_number+' · ' : '')+customer.name})
        })
      }catch(error){
        this.adjustmentDialog=false
        this.$root.$emit('show-error',this.errorMessage(error,'Unable to load adjustment options.'))
      }
    },

    async createAdjustment(){
      if(!this.adjustmentTransaction)return
      this.adjustmentErrors={}
      this.adjustmentSaving=true

      try{
        await api.post(
          '/accounting/bank-transactions/'+this.adjustmentTransaction.id+'/reconciliation-adjustment',
          this.adjustmentForm,
          {skipGlobalError:true}
        )
        this.adjustmentDialog=false
        this.adjustmentTransaction=null
        await this.loadWorkspace(true)
      }catch(error){
        if(error.response && error.response.status === 422){
          this.adjustmentErrors=error.response.data.errors || {}
          if(!Object.keys(this.adjustmentErrors).length){
            this.$root.$emit('show-error',this.errorMessage(error,'Unable to post reconciliation adjustment.'))
          }
        }else{
          this.$root.$emit('show-error',this.errorMessage(error,'Unable to post reconciliation adjustment.'))
        }
      }finally{
        this.adjustmentSaving=false
      }
    },

    openAdjustmentReverse(item){
      this.adjustmentReverseItem=item
      this.adjustmentReverseErrors={}
      const original=item.reconciliation_adjustment && item.reconciliation_adjustment.journal_entry
        ? this.rawDate(item.reconciliation_adjustment.journal_entry.entry_date)
        : this.rawDate(item.transaction_date)
      const today=this.today()
      this.adjustmentReverseForm={
        entry_date:today > original ? today : original,
        reason:''
      }
      this.adjustmentReverseDialog=true
    },

    async reverseAdjustment(){
      const adjustment=this.adjustmentReverseItem && this.adjustmentReverseItem.reconciliation_adjustment
      if(!adjustment)return

      this.adjustmentReversing=true
      this.adjustmentReverseErrors={}

      try{
        await api.post(
          '/accounting/bank-reconciliation-adjustments/'+adjustment.id+'/reverse',
          this.adjustmentReverseForm,
          {skipGlobalError:true}
        )
        this.adjustmentReverseDialog=false
        this.adjustmentReverseItem=null
        await this.loadWorkspace(true)
      }catch(error){
        if(error.response && error.response.status === 422){
          this.adjustmentReverseErrors=error.response.data.errors || {}
          if(!Object.keys(this.adjustmentReverseErrors).length){
            this.$root.$emit('show-error',this.errorMessage(error,'Unable to reverse reconciliation adjustment.'))
          }
        }else{
          this.$root.$emit('show-error',this.errorMessage(error,'Unable to reverse reconciliation adjustment.'))
        }
      }finally{
        this.adjustmentReversing=false
      }
    },

    adjustmentTypeLabel(value){
      const item=this.adjustmentTypes.find(function(type){return type.value===value})
      if(item)return item.text
      return String(value || '').replace(/_/g,' ')
    },

    accountTypeLabel(value){
      return String(value || '').replace(/_/g,' ').replace(/\b\w/g,function(letter){return letter.toUpperCase()})
    },

    today(){
      const d=new Date()
      const offset=d.getTimezoneOffset()
      return new Date(d.getTime()-offset*60000).toISOString().slice(0,10)
    },

    candidateAmount(candidate){
      const debit=Number(candidate.debit || 0)
      const credit=Number(candidate.credit || 0)
      return this.money(debit > 0 ? debit : credit)
    },

    confidenceColor(confidence){
      if(confidence === 'high')return 'success'
      if(confidence === 'good')return 'info'
      if(confidence === 'review')return 'warning'
      return 'grey'
    },

    statusColor(status){
      if(status === 'reconciled')return 'success'
      if(status === 'matched')return 'info'
      return 'warning'
    },

    statusLabel(status){
      if(!status)return 'Unmatched'
      return status.charAt(0).toUpperCase()+status.slice(1)
    },

    money(value){
      if(value === null || value === undefined || value === '')return '—'
      return new Intl.NumberFormat('en-PK',{
        minimumFractionDigits:2,
        maximumFractionDigits:2
      }).format(Number(value || 0))
    },

    rawDate(value){
      if(!value)return ''
      return String(value).slice(0,10)
    },

    dateLabel(value){
      const raw=this.rawDate(value)
      if(!raw)return '—'
      const parts=raw.split('-')
      if(parts.length !== 3)return raw
      return new Intl.DateTimeFormat('en-PK',{day:'2-digit',month:'short',year:'numeric'})
        .format(new Date(Number(parts[0]),Number(parts[1])-1,Number(parts[2])))
    },

    dateTime(value){
      if(!value)return '—'
      const date=new Date(value)
      if(Number.isNaN(date.getTime()))return value
      return date.toLocaleString('en-PK',{dateStyle:'medium',timeStyle:'short'})
    },

    errorMessage(error,fallback){
      if(error.response && error.response.data){
        const errors=error.response.data.errors || {}
        const first=Object.keys(errors)[0]
        if(first && errors[first] && errors[first][0])return errors[first][0]
        if(error.response.data.message)return error.response.data.message
      }
      return fallback
    }
  }
}
</script>

<style scoped>
.bank-reconciliation-page{width:100%}
.hero{border-left:4px solid #165134}
.filter-card,.summary-card,.equation-card,.table-card,.finalize-card,.history-card{border:1px solid rgba(22,81,52,.08);border-radius:15px!important}
.balanced-card{border-color:rgba(76,175,80,.35)!important}
.difference-card{border-color:rgba(244,67,54,.3)!important}
.transaction-description{max-width:360px;white-space:normal;line-height:1.3}
.journal-preview{border:1px dashed rgba(103,58,183,.35);border-radius:12px!important}
</style>
