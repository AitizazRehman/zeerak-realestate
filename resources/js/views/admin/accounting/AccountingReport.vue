<template>
  <div>
    <v-card flat class="pa-5 mb-4">
      <div class="text-overline">ACCOUNTING</div>
      <h1 class="text-h5 font-weight-bold">{{ isLedger ? 'General Ledger' : 'Trial Balance' }}</h1>
      <p class="grey--text mb-0">Posted journal entries · PKR · Opening balances include all earlier postings.</p>
    </v-card>
    <v-card flat class="pa-4 mb-4">
      <v-form @submit.prevent="load(1)">
        <v-row dense>
          <v-col cols="12" sm="6" md="3"><v-text-field v-model="filters.from" type="date" label="From" outlined dense required /></v-col>
          <v-col cols="12" sm="6" md="3"><v-text-field v-model="filters.to" type="date" label="To" outlined dense required /></v-col>
          <v-col v-if="isLedger" cols="12" md="6"><v-autocomplete v-model="filters.account_id" :items="options.accounts" item-text="label" item-value="id" label="Account" outlined dense /></v-col>
          <v-col cols="12" md="5"><v-autocomplete v-model="filters.project_id" :items="options.projects" item-text="name" item-value="id" label="All projects" outlined dense clearable /></v-col>
          <v-col cols="12" md="5"><v-autocomplete v-model="filters.customer_id" :items="options.customers" item-text="label" item-value="id" label="All customers" outlined dense clearable /></v-col>
          <v-col cols="12" md="2"><v-btn type="submit" color="primary" :loading="loading" :disabled="optionsLoading" block>Apply</v-btn></v-col>
        </v-row>
      </v-form>
    </v-card>
    <v-alert v-if="error" type="error" text>{{ error }}</v-alert>
    <v-alert v-if="!result && !loading && !error" type="info" text>{{ isLedger ? 'Choose an account and apply the filters.' : 'Apply the filters to view the trial balance.' }}</v-alert>
    <template v-if="result">
      <v-card flat class="pa-4 mb-4">
        <div v-if="isLedger" class="d-flex flex-wrap summary">
          <span>Opening: <strong>{{ balance(result.summary.opening) }}</strong></span>
          <span>Debits: <strong>{{ money(result.summary.debit) }}</strong></span>
          <span>Credits: <strong>{{ money(result.summary.credit) }}</strong></span>
          <span>Closing: <strong>{{ balance(result.summary.closing) }}</strong></span>
        </div>
        <div v-else class="d-flex flex-wrap summary">
          <span>Closing debits: <strong>{{ money(result.summary.debit) }}</strong></span>
          <span>Closing credits: <strong>{{ money(result.summary.credit) }}</strong></span>
          <span>Difference: <strong>{{ balance(result.summary.difference) }}</strong></span>
        </div>
      </v-card>
      <v-alert v-if="!isLedger && !result.summary.balanced" type="warning" text>
        This selection is not balanced. Project or customer filters can include only one side of a journal entry.
      </v-alert>
      <v-card flat>
        <v-data-table :headers="headers" :items="rows" :loading="loading" :items-per-page="isLedger ? 50 : -1" hide-default-footer disable-sort no-data-text="No posted entries match these filters.">
          <template v-slot:item.description="{ item }">{{ item.description || item.entry_description }}</template>
          <template v-slot:item.opening="{ item }">{{ balance(item.opening) }}</template>
          <template v-slot:item.debit="{ item }">{{ money(item.debit) }}</template>
          <template v-slot:item.credit="{ item }">{{ money(item.credit) }}</template>
          <template v-slot:item.balance="{ item }">{{ balance(item.balance) }}</template>
          <template v-slot:item.closing_debit="{ item }">{{ money(item.closing_debit) }}</template>
          <template v-slot:item.closing_credit="{ item }">{{ money(item.closing_credit) }}</template>
        </v-data-table>
        <v-pagination v-if="isLedger && result.entries.last_page > 1" :value="result.entries.current_page" :length="result.entries.last_page" :disabled="loading" :total-visible="7" class="pa-4" @input="load" />
      </v-card>
      <div class="caption grey--text mt-3">Dr = debit balance; Cr = credit balance. Reports include historical accounts, including inactive accounts.</div>
    </template>
  </div>
</template>

<script>
import api from '../../../services/api'

export default {
  name: 'AccountingReport',
  props: { report: { type: String, required: true } },
  data() {
    const now = new Date()
    const year = now.getFullYear()
    const month = String(now.getMonth() + 1).padStart(2, '0')
    const day = String(now.getDate()).padStart(2, '0')
    return {
      filters: { from: `${year}-${month}-01`, to: `${year}-${month}-${day}`, account_id: null, project_id: null, customer_id: null },
      applied: null, options: { accounts: [], projects: [], customers: [] },
      result: null, loading: false, optionsLoading: false, error: '', requestId: 0
    }
  },
  computed: {
    isLedger() { return this.report === 'ledger' },
    rows() { return this.result ? (this.isLedger ? this.result.entries.data : this.result.data) : [] },
    headers() {
      const number = (text, value) => ({ text, value, align: 'end' })
      return this.isLedger
        ? [{ text: 'Date', value: 'entry_date' }, { text: 'Journal', value: 'entry_number' }, { text: 'Description', value: 'description' }, number('Debit', 'debit'), number('Credit', 'credit'), number('Balance', 'balance')]
        : [{ text: 'Code', value: 'code' }, { text: 'Account', value: 'name' }, number('Opening', 'opening'), number('Period debit', 'debit'), number('Period credit', 'credit'), number('Closing debit', 'closing_debit'), number('Closing credit', 'closing_credit')]
    }
  },
  watch: {
    report() { this.requestId++; this.result = null; this.applied = null; this.error = ''; this.loading = false }
  },
  async mounted() {
    this.optionsLoading = true
    try {
      const response = await api.get('/accounting/report-options')
      this.options = response.data
      this.options.accounts = this.options.accounts.map(a => ({ ...a, label: `${a.code} — ${a.name}` }))
      this.options.customers = this.options.customers.map(c => ({ ...c, label: `${c.customer_number} — ${c.name}` }))
    } catch (e) { this.error = 'Unable to load report filters. Please reload this page.' }
    finally { this.optionsLoading = false }
  },
  methods: {
    money(value) {
      const parts = String(value || '0.00').replace(/^-/, '').split('.')
      return parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, ',') + '.' + (parts[1] || '').padEnd(2, '0')
    },
    balance(value) { return this.money(value) + (String(value).startsWith('-') ? ' Cr' : ' Dr') },
    async load(page = 1) {
      const requestId = ++this.requestId
      this.error = ''
      const params = page === 1 || !this.applied ? { ...this.filters } : { ...this.applied }
      if (!this.isLedger) delete params.account_id
      if (!params.from || !params.to || params.from > params.to || (this.isLedger && !params.account_id)) {
        this.error = 'Choose valid dates and, for the ledger, an account.'
        this.result = null
        this.loading = false
        return
      }
      this.loading = true
      this.result = null
      try {
        const response = await api.get('/accounting/' + (this.isLedger ? 'general-ledger' : 'trial-balance'), { params: { ...params, page } })
        if (requestId === this.requestId) { this.result = response.data; this.applied = params }
      } catch (e) {
        if (requestId === this.requestId) this.error = (e.response && e.response.data && e.response.data.message) || 'Unable to load the report.'
      } finally { if (requestId === this.requestId) this.loading = false }
    }
  }
}
</script>

<style scoped>
.summary { gap: 16px 32px; }
</style>
