<template>
  <div class="page">
    <v-card flat class="hero pa-5 mb-4">
      <div class="d-flex flex-wrap align-center">
        <div>
          <div class="text-overline">SALES &amp; COMMISSIONS</div>
          <h1 class="text-h5 font-weight-bold">Commissions</h1>
          <div class="grey--text">Track, approve and settle sales-agent commissions.</div>
        </div>
        <v-spacer></v-spacer>
        <v-btn icon :loading="loading" @click="load">
          <v-icon>mdi-refresh</v-icon>
        </v-btn>
      </div>
    </v-card>

    <v-card flat outlined class="mb-4">
      <v-card-text>
        <v-row dense>
          <v-col cols="12" md="4">
            <v-select
              v-model="filters.status"
              :items="statuses"
              outlined
              dense
              clearable
              label="Status"
              @change="load"
            ></v-select>
          </v-col>
          <v-col cols="12" md="4">
            <v-select
              v-model="filters.agent_id"
              :items="agents"
              item-text="name"
              item-value="id"
              outlined
              dense
              clearable
              label="Sales Agent"
              @change="load"
            ></v-select>
          </v-col>
          <v-col cols="12" md="4" class="d-flex align-center">
            <v-chip outlined>{{ items.length }} shown</v-chip>
          </v-col>
        </v-row>
      </v-card-text>
    </v-card>

    <v-card flat outlined>
      <v-data-table
        :headers="headers"
        :items="items"
        :loading="loading"
        :options.sync="options"
        :server-items-length="total"
      >
        <template v-slot:item.commission_amount="{ item }">
          <strong>{{ money(item.commission_amount) }}</strong>
        </template>

        <template v-slot:item.percentage="{ item }">
          {{ item.percentage }}%
        </template>

        <template v-slot:item.status="{ item }">
          <v-chip x-small :color="statusColor(item.status)" dark>
            {{ item.status }}
          </v-chip>
        </template>

        <template v-slot:item.actions="{ item }">
          <v-menu offset-y>
            <template v-slot:activator="{ on, attrs }">
              <v-btn icon small v-bind="attrs" v-on="on">
                <v-icon small>mdi-dots-vertical</v-icon>
              </v-btn>
            </template>
            <v-list dense>
              <v-list-item
                v-for="status in nextStatuses(item.status)"
                :key="status"
                @click="changeStatus(item, status)"
              >
                <v-list-item-title>Mark {{ status }}</v-list-item-title>
              </v-list-item>
            </v-list>
          </v-menu>
        </template>

        <template v-slot:no-data>
          <div class="pa-8 grey--text">No commissions found.</div>
        </template>
      </v-data-table>
    </v-card>
  </div>
</template>

<script>
import api from '../../../services/api'

export default {
  name: 'Commissions',

  data: () => ({
    loading: false,
    items: [],
    total: 0,
    agents: [],
    options: {
      page: 1,
      itemsPerPage: 15
    },
    filters: {
      status: null,
      agent_id: null
    },
    statuses: ['pending', 'approved', 'paid', 'cancelled'],
    headers: [
      { text: 'Agent', value: 'agent.name' },
      { text: 'Customer', value: 'booking.customer.name' },
      { text: 'Booking', value: 'booking.booking_number' },
      { text: 'Base Amount', value: 'base_amount', align: 'right' },
      { text: 'Rate', value: 'percentage', align: 'right' },
      { text: 'Commission', value: 'commission_amount', align: 'right' },
      { text: 'Status', value: 'status' },
      { text: '', value: 'actions', sortable: false }
    ]
  }),

  watch: {
    options: {
      deep: true,
      handler () {
        this.load()
      }
    }
  },

  mounted () {
    this.load()
    this.loadAgents()
  },

  methods: {
    async load () {
      this.loading = true
      try {
        const response = await api.get('/commissions', {
          params: Object.assign({}, this.filters, {
            page: this.options.page,
            per_page: this.options.itemsPerPage
          })
        })
        this.items = response.data.data || []
        this.total = response.data.total || 0
      } catch (error) {
        this.$root.$emit(
          'show-error',
          (error.response && error.response.data && error.response.data.message) ||
            'Unable to load commissions.'
        )
      } finally {
        this.loading = false
      }
    },

    async loadAgents () {
      try {
        const response = await api.get('/sales-agents')
        this.agents = response.data.data || []
      } catch (error) {
        this.agents = []
      }
    },

    money (value) {
      return new Intl.NumberFormat('en-PK', {
        maximumFractionDigits: 0
      }).format(Number(value || 0))
    },

    statusColor (status) {
      return {
        pending: 'orange',
        approved: 'blue',
        paid: 'success',
        cancelled: 'grey'
      }[status] || 'grey'
    },

    nextStatuses (status) {
      if (status === 'pending') return ['approved', 'cancelled']
      if (status === 'approved') return ['paid', 'cancelled']
      return []
    },

    async changeStatus (item, status) {
      try {
        await api.put('/commissions/' + item.id, { status })
        await this.load()
      } catch (error) {
        this.$root.$emit(
          'show-error',
          (error.response && error.response.data && error.response.data.message) ||
            'Unable to update commission.'
        )
      }
    }
  }
}
</script>

<style scoped>
.page {
  width: 100%;
}

.hero {
  border-left: 4px solid #165134;
}

.page ::v-deep .v-data-table__wrapper {
  overflow-x: auto;
}
</style>
