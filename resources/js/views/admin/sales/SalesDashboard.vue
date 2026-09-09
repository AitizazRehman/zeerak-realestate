<template>
  <v-container fluid class="pa-6">
    <div class="d-flex flex-wrap align-center justify-space-between mb-4">
      <div>
        <div class="text-overline green--text text--darken-3">Sales & CRM</div>
        <h1 class="text-h4 font-weight-bold">Sales Dashboard</h1>
        <div class="text-body-2 grey--text mt-1">Live overview of leads, inventory, sales and collections.</div>
      </div>
      <v-btn text color="primary" :loading="loading" @click="load"><v-icon left>mdi-refresh</v-icon>Refresh</v-btn>
    </div>

    <v-card outlined class="mb-5">
      <v-card-text>
        <v-row align="center">
          <v-col cols="12" sm="5" md="4">
            <v-text-field v-model="filters.from" type="date" label="From date" outlined dense hide-details />
          </v-col>
          <v-col cols="12" sm="5" md="4">
            <v-text-field v-model="filters.to" type="date" label="To date" outlined dense hide-details />
          </v-col>
          <v-col cols="12" sm="2" md="4" class="d-flex">
            <v-btn color="primary" :loading="loading" @click="load"><v-icon left>mdi-filter</v-icon>Apply</v-btn>
            <v-btn text class="ml-2" :disabled="loading || (!filters.from && !filters.to)" @click="clearFilters">Clear</v-btn>
          </v-col>
        </v-row>
      </v-card-text>
    </v-card>

    <v-alert v-if="error" type="error" dense text class="mb-5">{{ error }}</v-alert>

    <v-row>
      <v-col v-for="card in metricCards" :key="card.key" cols="12" sm="6" md="3">
        <v-card outlined class="metric-card fill-height">
          <v-card-text class="d-flex align-center">
            <v-avatar size="46" color="grey lighten-4" class="mr-4"><v-icon :color="card.color">{{ card.icon }}</v-icon></v-avatar>
            <div>
              <div class="caption grey--text text-uppercase">{{ card.label }}</div>
              <div class="text-h5 font-weight-bold">{{ formatMetric(metrics[card.key]) }}</div>
            </div>
          </v-card-text>
        </v-card>
      </v-col>
    </v-row>

    <v-row class="mt-2">
      <v-col cols="12" md="7">
        <v-card outlined>
          <v-card-title>Collections by Month</v-card-title>
          <v-card-text>
            <div v-if="!monthlyCollections.length" class="empty-state">No verified collections in this period.</div>
            <div v-else>
              <div v-for="item in monthlyCollections" :key="item.month" class="mb-4">
                <div class="d-flex justify-space-between caption mb-1"><span>{{ formatMonth(item.month) }}</span><strong>{{ money(item.amount) }}</strong></div>
                <v-progress-linear rounded height="9" :value="collectionPercent(item.amount)" color="primary" />
              </div>
            </div>
          </v-card-text>
        </v-card>
      </v-col>
      <v-col cols="12" md="5">
        <v-card outlined class="fill-height">
          <v-card-title>Property Pipeline</v-card-title>
          <v-card-text>
            <div v-for="item in pipeline" :key="item.label" class="d-flex align-center mb-4">
              <div class="pipeline-label">{{ item.label }}</div>
              <v-progress-linear class="mx-3" rounded height="9" :value="item.percent" :color="item.color" />
              <strong>{{ item.value }}</strong>
            </div>
          </v-card-text>
        </v-card>
      </v-col>
    </v-row>

    <v-row>
      <v-col cols="12" md="6">
        <v-card outlined>
          <v-card-title>Top Sales Agents</v-card-title>
          <v-data-table :headers="agentHeaders" :items="agentPerformance" :loading="loading" dense hide-default-footer :items-per-page="10">
            <template v-slot:item.sales_value="{ item }">{{ money(item.sales_value) }}</template>
            <template v-slot:item.collected="{ item }">{{ money(item.collected) }}</template>
            <template v-slot:no-data><div class="pa-6 grey--text">No sales data for this period.</div></template>
          </v-data-table>
        </v-card>
      </v-col>
      <v-col cols="12" md="6">
        <v-card outlined>
          <v-card-title>Project Performance</v-card-title>
          <v-data-table :headers="projectHeaders" :items="projectPerformance" :loading="loading" dense hide-default-footer :items-per-page="10">
            <template v-slot:item.sales_value="{ item }">{{ money(item.sales_value) }}</template>
            <template v-slot:item.collected="{ item }">{{ money(item.collected) }}</template>
            <template v-slot:no-data><div class="pa-6 grey--text">No project sales data for this period.</div></template>
          </v-data-table>
        </v-card>
      </v-col>
    </v-row>
  </v-container>
</template>

<script>
import api from '../../../services/api'

export default {
  name: 'SalesDashboard',
  data: () => ({
    loading: false,
    error: '',
    filters: { from: '', to: '' },
    metrics: {},
    monthlyCollections: [],
    agentPerformance: [],
    projectPerformance: [],
    metricCards: [
      { key: 'customers', label: 'Active Customers', icon: 'mdi-account-group-outline', color: 'primary' },
      { key: 'active_leads', label: 'Active Leads', icon: 'mdi-account-star-outline', color: 'orange darken-2' },
      { key: 'scheduled_visits', label: 'Upcoming Visits', icon: 'mdi-calendar-clock', color: 'blue darken-2' },
      { key: 'sales_value', label: 'Sales Value', icon: 'mdi-cash-multiple', color: 'green darken-2' },
      { key: 'collections', label: 'Collections', icon: 'mdi-bank-check', color: 'teal darken-2' },
      { key: 'receivables', label: 'Receivables', icon: 'mdi-cash-clock', color: 'deep-orange darken-2' },
      { key: 'overdue_count', label: 'Overdue Installments', icon: 'mdi-alert-circle-outline', color: 'red darken-2' },
      { key: 'overdue_amount', label: 'Overdue Amount', icon: 'mdi-alert-decagram-outline', color: 'red darken-3' }
    ],
    agentHeaders: [
      { text: 'Agent', value: 'sales_agent.name' }, { text: 'Bookings', value: 'bookings', align: 'right' },
      { text: 'Sales', value: 'sales_value', align: 'right' }, { text: 'Collected', value: 'collected', align: 'right' }
    ],
    projectHeaders: [
      { text: 'Project', value: 'project_name' }, { text: 'Bookings', value: 'bookings', align: 'right' },
      { text: 'Sales', value: 'sales_value', align: 'right' }, { text: 'Collected', value: 'collected', align: 'right' }
    ]
  }),
  computed: {
    pipeline () {
      const items = [
        { label: 'Available', value: this.metrics.available_properties || 0, color: 'green' },
        { label: 'Reserved', value: this.metrics.reserved_properties || 0, color: 'orange' },
        { label: 'Booked', value: this.metrics.booked_properties || 0, color: 'blue' },
        { label: 'Sold', value: this.metrics.sold_properties || 0, color: 'red' }
      ]
      const max = Math.max(...items.map(i => Number(i.value)), 1)
      return items.map(i => ({ ...i, percent: (Number(i.value) / max) * 100 }))
    },
    maxCollection () { return Math.max(...this.monthlyCollections.map(i => Number(i.amount)), 1) }
  },
  mounted () { this.load() },
  methods: {
    async load () {
      if (this.filters.from && this.filters.to && this.filters.from > this.filters.to) {
        this.error = 'The From date cannot be later than the To date.'
        return
      }
      this.loading = true
      this.error = ''
      try {
        const params = {}
        if (this.filters.from) params.from = this.filters.from
        if (this.filters.to) params.to = this.filters.to
        const response = await api.get('/sales/dashboard', { params })
        const data = response.data || {}
        this.metrics = data.metrics || {}
        this.monthlyCollections = data.monthly_collections || []
        this.agentPerformance = data.agent_performance || []
        this.projectPerformance = data.project_performance || []
      } catch (e) {
        this.error = e.response && e.response.data && e.response.data.message ? e.response.data.message : 'Unable to load the sales dashboard.'
      } finally { this.loading = false }
    },
    clearFilters () { this.filters = { from: '', to: '' }; this.load() },
    money (value) { return new Intl.NumberFormat('en-PK', { maximumFractionDigits: 0 }).format(Number(value || 0)) },
    formatMetric (value) { return this.money(value) },
    formatMonth (value) { const parts = String(value).split('-'); return parts.length === 2 ? `${parts[1]}/${parts[0]}` : value },
    collectionPercent (value) { return (Number(value || 0) / this.maxCollection) * 100 }
  }
}
</script>

<style scoped>
.metric-card { border-radius: 14px; }
.empty-state { min-height: 120px; display: flex; align-items: center; justify-content: center; color: #888; }
.pipeline-label { width: 76px; font-size: 13px; }
</style>
