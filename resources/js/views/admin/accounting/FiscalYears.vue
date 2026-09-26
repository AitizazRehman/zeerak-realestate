<template>
  <div>
    <v-card flat class="pa-5 mb-4">
      <div class="d-flex flex-wrap align-center">
        <div><div class="text-overline">ACCOUNTING SETUP</div><h1 class="text-h5 font-weight-bold">Fiscal Years & Periods</h1><p class="grey--text mb-0">Open the periods used for payments and journal entries.</p></div>
        <v-spacer /><v-btn v-if="$can('accounting.create')" color="primary" :disabled="busy" @click="dialog=true">New fiscal year</v-btn>
      </div>
    </v-card>
    <v-alert v-if="error" type="error" text>{{ error }}</v-alert>
    <v-progress-linear v-if="loading" indeterminate color="primary" />
    <v-alert v-if="!loading && !years.length" type="info" text>Create a fiscal year to generate its 12 monthly periods. Payment dates must belong to an open period.</v-alert>
    <v-card v-for="year in years" :key="year.id" flat class="mb-4">
      <v-card-title>{{ year.name }}<v-chip small outlined class="ml-3">{{ year.status }}</v-chip><v-spacer />
        <v-btn v-if="$can('accounting.edit')" small outlined :disabled="busy || (year.status === 'open' && year.periods.some(p => p.status === 'open'))" @click="toggleYear(year)">{{ year.status === 'open' ? 'Close year' : 'Reopen year' }}</v-btn>
      </v-card-title>
      <v-card-subtitle>{{ year.starts_on }} — {{ year.ends_on }}. Close every period before closing the year. Reopening a year leaves its periods closed.</v-card-subtitle>
      <v-simple-table><thead><tr><th>Period</th><th>From</th><th>To</th><th>Status</th><th v-if="$can('accounting.edit')">Action</th></tr></thead>
        <tbody><tr v-for="period in year.periods" :key="period.id"><td>{{ period.name }}</td><td>{{ period.starts_on }}</td><td>{{ period.ends_on }}</td><td><v-chip x-small :color="period.status === 'open' ? 'success' : 'grey'" outlined>{{ period.status }}</v-chip></td><td v-if="$can('accounting.edit')"><v-btn small text :disabled="busy || year.status === 'closed'" @click="togglePeriod(period)">{{ period.status === 'open' ? 'Close' : 'Reopen' }}</v-btn></td></tr></tbody>
      </v-simple-table>
    </v-card>
    <v-dialog v-model="dialog" max-width="520" persistent><v-card>
      <v-card-title>Create fiscal year</v-card-title>
      <v-card-text><v-form @submit.prevent="create">
        <v-alert v-if="error" type="error" text dense>{{ error }}</v-alert>
        <v-text-field v-model="form.name" label="Name" outlined dense />
        <v-text-field v-model="form.starts_on" label="Starts on" type="date" outlined dense />
        <v-text-field v-model="form.ends_on" label="Ends on" type="date" outlined dense />
        <div class="caption grey--text">Use 12 complete months, starting on the first day of a month. The periods are created open.</div>
        <div class="text-right mt-4"><v-btn text :disabled="busy" @click="dialog=false">Cancel</v-btn><v-btn type="submit" color="primary" :loading="busy" :disabled="busy">Create</v-btn></div>
      </v-form></v-card-text>
    </v-card></v-dialog>
  </div>
</template>

<script>
import api from '../../../services/api'

export default {
  name: 'FiscalYears',
  data() {
    const now = new Date()
    const year = now.getMonth() >= 6 ? now.getFullYear() : now.getFullYear() - 1
    return { years: [], loading: false, busy: false, error: '', dialog: false,
      form: { name: `FY ${year}-${year + 1}`, starts_on: `${year}-07-01`, ends_on: `${year + 1}-06-30` } }
  },
  mounted() { this.load() },
  methods: {
    errorText(e) { const data = e.response && e.response.data; if (data && data.errors) return data.errors[Object.keys(data.errors)[0]][0]; return data && data.message || 'Unable to update fiscal periods.' },
    async load() {
      this.loading = true
      try { this.years = (await api.get('/accounting/fiscal-years')).data.data }
      catch (e) { this.error = this.errorText(e) }
      finally { this.loading = false }
    },
    async create() {
      if (this.busy) return
      this.busy = true; this.error = ''
      try { await api.post('/accounting/fiscal-years', this.form); this.dialog = false; await this.load() }
      catch (e) { this.error = this.errorText(e) }
      finally { this.busy = false }
    },
    toggleYear(year) { this.change('/accounting/fiscal-years/' + year.id + '/status', year.status) },
    togglePeriod(period) { this.change('/accounting/periods/' + period.id + '/status', period.status) },
    async change(url, status) {
      if (this.busy) return
      this.busy = true; this.error = ''
      try { await api.patch(url, { status: status === 'open' ? 'closed' : 'open' }); await this.load() }
      catch (e) { this.error = this.errorText(e) }
      finally { this.busy = false }
    }
  }
}
</script>
