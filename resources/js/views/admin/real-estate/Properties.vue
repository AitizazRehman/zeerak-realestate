```vue
<template>
  <div class="properties-page">

    <!-- Header -->
    <v-card flat class="pa-4 mb-4">
      <div class="d-flex align-center flex-wrap">

        <div>
          <h2 class="text-h5 font-weight-bold">
            Property Inventory
          </h2>

          <div class="text-caption grey--text">
            Search and manage property inventory
          </div>
        </div>

        <v-spacer />

        <v-btn
          color="primary"
          depressed
          class="mt-2 mt-md-0"
          @click="$router.push('/admin/properties/create')"
        >
          <v-icon left>
            mdi-plus
          </v-icon>

          Add Property
        </v-btn>

      </div>
    </v-card>


    <!-- Summary -->
    <v-row class="mb-2">

      <v-col
        v-for="card in summaryCards"
        :key="card.key"
        cols="6"
        sm="4"
        md="3"
        lg="2"
      >
        <v-card outlined class="pa-3 summary-card">

          <div class="d-flex align-center">

            <v-avatar
              size="38"
              :color="card.color"
              class="mr-3"
            >
              <v-icon color="white">
                {{ card.icon }}
              </v-icon>
            </v-avatar>

            <div>

              <div class="text-caption grey--text">
                {{ card.label }}
              </div>

              <div class="text-h6 font-weight-bold">
                {{ summary[card.key] || 0 }}
              </div>

            </div>

          </div>

        </v-card>
      </v-col>

    </v-row>


    <!-- Filters -->
    <v-card flat class="pa-4 mb-4">

      <v-row>

        <!-- Project -->
        <v-col cols="12" md="3">

          <v-select
            v-model="filters.project_id"
            :items="projects"
            item-text="name"
            item-value="id"
            label="Project"
            outlined
            dense
            clearable
            prepend-inner-icon="mdi-office-building"
            @change="projectChanged"
          />

        </v-col>


        <!-- Block -->
        <v-col cols="12" md="2">

          <v-select
            v-model="filters.block_id"
            :items="filteredBlocks"
            item-text="name"
            item-value="id"
            label="Block"
            outlined
            dense
            clearable
            prepend-inner-icon="mdi-city"
            :disabled="!filters.project_id"
            @change="loadData"
          />

        </v-col>


        <!-- Search -->
        <v-col cols="12" md="3">

          <v-text-field
            v-model="filters.search"
            label="Search Property"
            placeholder="A-101"
            outlined
            dense
            clearable
            prepend-inner-icon="mdi-magnify"
            @keyup.enter="loadData"
          />

        </v-col>


        <!-- Status -->
        <v-col cols="12" md="2">

          <v-select
            v-model="filters.status"
            :items="statuses"
            item-text="text"
            item-value="value"
            label="Status"
            outlined
            dense
            clearable
          />

        </v-col>


        <!-- Property Type -->
        <v-col cols="12" md="2">

          <v-select
            v-model="filters.property_type"
            :items="propertyTypes"
            item-text="text"
            item-value="value"
            label="Property Type"
            outlined
            dense
            clearable
          />

        </v-col>


        <!-- Min Price -->
        <v-col cols="12" sm="6" md="2">

          <v-text-field
            v-model="filters.min_price"
            label="Min Price"
            type="number"
            outlined
            dense
            min="0"
          />

        </v-col>


        <!-- Max Price -->
        <v-col cols="12" sm="6" md="2">

          <v-text-field
            v-model="filters.max_price"
            label="Max Price"
            type="number"
            outlined
            dense
            min="0"
          />

        </v-col>


        <!-- Apply -->
        <v-col
          cols="12"
          sm="6"
          md="2"
          class="d-flex align-center"
        >

          <v-btn
            block
            height="40"
            color="#165134"
            dark
            :loading="loading"
            @click="loadData"
          >
            <v-icon left>
              mdi-filter
            </v-icon>

            Apply
          </v-btn>

        </v-col>


        <!-- Reset -->
        <v-col
          cols="12"
          sm="6"
          md="2"
          class="d-flex align-center"
        >

          <v-btn
            block
            height="40"
            outlined
            color="grey darken-1"
            @click="resetFilters"
          >
            <v-icon left>
              mdi-refresh
            </v-icon>

            Reset
          </v-btn>

        </v-col>

      </v-row>

    </v-card>


    <!-- Inventory Header -->
    <div class="d-flex align-center mb-3">

      <div class="text-h6 font-weight-bold">
        Properties
      </div>

      <v-spacer />

      <v-btn-toggle
        v-model="viewMode"
        dense
        mandatory
      >

        <v-btn value="grid">
          <v-icon>
            mdi-view-grid
          </v-icon>
        </v-btn>

        <v-btn value="list">
          <v-icon>
            mdi-view-list
          </v-icon>
        </v-btn>

      </v-btn-toggle>

    </div>


    <!-- Loading -->
    <v-card
      v-if="loading"
      flat
      class="pa-10 text-center"
    >

      <v-progress-circular
        indeterminate
        color="primary"
        size="45"
      />

      <div class="text-caption grey--text mt-3">
        Loading properties...
      </div>

    </v-card>


    <!-- Empty -->
    <v-card
      v-else-if="properties.length === 0"
      flat
      class="pa-10 text-center"
    >

      <v-icon
        size="60"
        color="grey lighten-1"
      >
        mdi-home-search
      </v-icon>

      <div class="text-h6 mt-3">
        No properties found
      </div>

      <div class="text-caption grey--text">
        Try changing your search filters
      </div>

    </v-card>


    <!-- Grid View -->
    <v-card
      v-else-if="viewMode === 'grid'"
      flat
      class="pa-4"
    >

      <v-row>

        <v-col
          v-for="property in properties"
          :key="property.id"
          cols="12"
          sm="6"
          md="4"
          lg="3"
          xl="2"
        >

          <v-card
            outlined
            class="property-card"
            @click="viewProperty(property)"
          >

            <!-- Status -->
            <div
              class="property-status"
              :class="`status-${property.status}`"
            >
              {{ formatStatus(property.status) }}
            </div>


            <v-card-text class="pa-4">

              <!-- Property Number -->
              <div class="d-flex align-center mb-3">

                <v-icon
                  size="30"
                  class="mr-2"
                  :color="statusColor(property.status)"
                >
                  mdi-home
                </v-icon>

                <div>

                  <div class="font-weight-bold">
                    {{ property.property_number || '-' }}
                  </div>

                  <div class="text-caption grey--text">
                    {{ formatPropertyType(property.property_type) }}
                  </div>

                </div>

              </div>


              <!-- Project -->
              <div class="property-info">

                <v-icon small class="mr-1">
                  mdi-office-building
                </v-icon>

                <span>
                  {{ property.project
                    ? property.project.name
                    : '-' }}
                </span>

              </div>


              <!-- Block -->
              <div class="property-info">

                <v-icon small class="mr-1">
                  mdi-city
                </v-icon>

                <span>
                  {{ property.block
                    ? property.block.name
                    : '-' }}
                </span>

              </div>


              <!-- Size -->
              <div class="property-info">

                <v-icon small class="mr-1">
                  mdi-ruler-square
                </v-icon>

                <span>
                  {{ property.size || '-' }}
                  {{ property.size_unit || 'Marla' }}
                </span>

              </div>


              <!-- Price -->
              <div class="property-info">

                <v-icon small class="mr-1">
                  mdi-cash
                </v-icon>

                <span>
                  Rs. {{ formatPrice(property.price) }}
                </span>

              </div>

            </v-card-text>


            <v-divider />


            <v-card-actions>

              <v-btn
                text
                small
                color="primary"
                @click.stop="viewProperty(property)"
              >
                Details
              </v-btn>

              <v-spacer />

              <v-btn
                icon
                small
                @click.stop="editProperty(property)"
              >
                <v-icon small>
                  mdi-pencil
                </v-icon>
              </v-btn>

            </v-card-actions>

          </v-card>

        </v-col>

      </v-row>

    </v-card>


    <!-- List View -->
    <v-card
      v-else
      flat
    >

      <v-data-table
        :headers="tableHeaders"
        :items="properties"
        :items-per-page="itemsPerPage"
        :loading="loading"
        item-key="id"
        class="property-table"
        hide-default-footer
      >

        <!-- Property Number -->
        <template v-slot:item.property_number="{ item }">

          <div
            class="font-weight-bold primary--text property-link"
            @click="viewProperty(item)"
          >
            {{ item.property_number }}
          </div>

        </template>


        <!-- Project -->
        <template v-slot:item.project="{ item }">

          {{ item.project ? item.project.name : '-' }}

        </template>


        <!-- Block -->
        <template v-slot:item.block="{ item }">

          {{ item.block ? item.block.name : '-' }}

        </template>


        <!-- Type -->
        <template v-slot:item.property_type="{ item }">

          {{ formatPropertyType(item.property_type) }}

        </template>


        <!-- Size -->
        <template v-slot:item.size="{ item }">

          {{ item.size || '-' }}
          {{ item.size_unit || 'Marla' }}

        </template>


        <!-- Price -->
        <template v-slot:item.price="{ item }">

          Rs. {{ formatPrice(item.price) }}

        </template>


        <!-- Status -->
        <template v-slot:item.status="{ item }">

          <v-chip
            small
            :color="statusColor(item.status)"
            dark
          >
            {{ formatStatus(item.status) }}
          </v-chip>

        </template>


        <!-- Actions -->
        <template v-slot:item.actions="{ item }">

          <v-btn
            icon
            small
            @click="viewProperty(item)"
          >
            <v-icon small>
              mdi-eye
            </v-icon>
          </v-btn>

          <v-btn
            icon
            small
            @click="editProperty(item)"
          >
            <v-icon small>
              mdi-pencil
            </v-icon>
          </v-btn>

        </template>

      </v-data-table>

    </v-card>


  </div>
</template>


<script>

import api from '../../../services/api'

export default {

  name: 'Properties',


  data() {

    return {

      loading: false,

      viewMode: 'grid',

      projects: [],

      blocks: [],

      properties: [],

      itemsPerPage: 100,


      /*
       * Property Types
       */
      propertyTypes: [

        {
          text: 'Residential',
          value: 'residential'
        },

        {
          text: 'Commercial',
          value: 'commercial'
        },

        {
          text: 'Industrial',
          value: 'industrial'
        }

      ],


      /*
       * Summary
       */
      summary: {

        total: 0,

        available: 0,

        reserved: 0,

        booked: 0,

        sold: 0,

        under_construction: 0,

        rented: 0,

        unavailable: 0,

        cancelled: 0

      },


      /*
       * Filters
       *
       * IMPORTANT:
       * project_id and block_id were missing
       * from the original component.
       */
      filters: {

        project_id: null,

        block_id: null,

        search: '',

        status: null,

        property_type: null,

        min_price: null,

        max_price: null

      },


      /*
       * Statuses
       */
      statuses: [

        {
          text: 'Available',
          value: 'available'
        },

        {
          text: 'Reserved',
          value: 'reserved'
        },

        {
          text: 'Booked',
          value: 'booked'
        },

        {
          text: 'Sold',
          value: 'sold'
        },

        {
          text: 'Under Construction',
          value: 'under_construction'
        },

        {
          text: 'Rented',
          value: 'rented'
        },

        {
          text: 'Unavailable',
          value: 'unavailable'
        },

        {
          text: 'Cancelled',
          value: 'cancelled'
        }

      ],


      /*
       * Table
       */
      tableHeaders: [

        {
          text: 'Property',
          value: 'property_number'
        },

        {
          text: 'Project',
          value: 'project'
        },

        {
          text: 'Block',
          value: 'block'
        },

        {
          text: 'Type',
          value: 'property_type'
        },

        {
          text: 'Size',
          value: 'size'
        },

        {
          text: 'Price',
          value: 'price'
        },

        {
          text: 'Status',
          value: 'status'
        },

        {
          text: 'Actions',
          value: 'actions',
          sortable: false,
          align: 'right'
        }

      ]

    }

  },


  computed: {

    /*
     * Only show blocks belonging
     * to selected project.
     */
    filteredBlocks() {

      if (!this.filters.project_id) {

        return this.blocks

      }

      return this.blocks.filter(

        block =>
          Number(block.project_id) ===
          Number(this.filters.project_id)

      )

    },


    /*
     * Summary Cards
     */
    summaryCards() {

      return [

        {
          key: 'total',
          label: 'Total',
          icon: 'mdi-home-group',
          color: 'primary'
        },

        {
          key: 'available',
          label: 'Available',
          icon: 'mdi-home-plus',
          color: 'success'
        },

        {
          key: 'reserved',
          label: 'Reserved',
          icon: 'mdi-clock-outline',
          color: 'warning'
        },

        {
          key: 'booked',
          label: 'Booked',
          icon: 'mdi-calendar-check',
          color: 'info'
        },

        {
          key: 'sold',
          label: 'Sold',
          icon: 'mdi-home-lock',
          color: 'error'
        }

      ]

    }

  },


  mounted() {

    /*
     * Restore filters from URL
     */
    this.filters.project_id =
      this.$route.query.project_id
        ? Number(this.$route.query.project_id)
        : null


    this.filters.block_id =
      this.$route.query.block_id
        ? Number(this.$route.query.block_id)
        : null


    this.initialize()

  },


  methods: {


    /*
     * Initial loading
     */
    async initialize() {

      await this.loadProjects()

      await this.loadBlocks()

      await this.loadData()

    },


    /*
     * Load Projects
     */
    async loadProjects() {

      try {

        const response = await api.get(
          '/projects',
          {
            params: {
              per_page: 100,
              is_active: 1
            }
          }
        )


        this.projects =
          response.data.data || []

      }

      catch (error) {

        console.error(
          'Failed to load projects:',
          error
        )

      }

    },


    /*
     * Load Blocks
     */
    async loadBlocks() {

      try {

        const response = await api.get(
          '/project-blocks',
          {
            params: {
              per_page: 500,
              project_id:
                this.filters.project_id
            }
          }
        )


        this.blocks =
          response.data.data || []


        /*
         * If selected block no longer
         * belongs to selected project,
         * clear it.
         */
        if (
          this.filters.block_id &&
          !this.filteredBlocks.some(
            block =>
              Number(block.id) ===
              Number(this.filters.block_id)
          )
        ) {

          this.filters.block_id = null

        }

      }

      catch (error) {

        console.error(
          'Failed to load blocks:',
          error
        )

        this.blocks = []

      }

    },


    /*
     * Project changed
     */
    async projectChanged() {

      this.filters.block_id = null

      await this.loadBlocks()

      await this.loadData()

    },


    /*
     * Load inventory + properties
     */
    async loadData() {

      await Promise.all([

        this.loadInventory(),

        this.loadProperties()

      ])

    },


    /*
     * Inventory summary
     */
    async loadInventory() {

      try {

        const response =
          await api.get(
            '/properties/inventory',
            {
              params: {

                project_id:
                  this.filters.project_id,

                block_id:
                  this.filters.block_id

              }
            }
          )


        if (response.data.summary) {

          this.summary =
            response.data.summary

        }

      }

      catch (error) {

        console.error(
          'Failed to load inventory:',
          error
        )

      }

    },


    /*
     * Properties
     */
    async loadProperties() {

      this.loading = true

      try {

        const params = {

          project_id:
            this.filters.project_id,

          block_id:
            this.filters.block_id,

          search:
            this.filters.search || undefined,

          status:
            this.filters.status || undefined,

          property_type:
            this.filters.property_type || undefined,

          min_price:
            this.filters.min_price || undefined,

          max_price:
            this.filters.max_price || undefined,

          per_page:
            this.itemsPerPage

        }


        const response =
          await api.get(
            '/properties',
            {
              params
            }
          )


        this.properties =
          response.data.data || []

      }

      catch (error) {

        console.error(
          'Failed to load properties:',
          error
        )

        this.properties = []

      }

      finally {

        this.loading = false

      }

    },


    /*
     * Reset filters
     */
    async resetFilters() {

      this.filters = {

        project_id: null,

        block_id: null,

        search: '',

        status: null,

        property_type: null,

        min_price: null,

        max_price: null

      }


      await this.loadBlocks()

      await this.loadData()

    },


    /*
     * View Property
     */
    viewProperty(property) {

      this.$router.push(
        `/admin/properties/${property.id}`
      )

    },


    /*
     * Edit Property
     */
    editProperty(property) {

      this.$router.push(
        `/admin/properties/${property.id}/edit`
      )

    },


    /*
     * Format Status
     */
    formatStatus(status) {

      if (!status) {

        return '-'

      }


      return String(status)

        .replace(/_/g, ' ')

        .replace(/\b\w/g, letter =>
          letter.toUpperCase()
        )

    },


    /*
     * Format Property Type
     */
    formatPropertyType(type) {

      if (!type) {

        return '-'

      }


      return String(type)

        .replace(/_/g, ' ')

        .replace(/\b\w/g, letter =>
          letter.toUpperCase()
        )

    },


    /*
     * Status color
     */
    statusColor(status) {

      const colors = {

        available: 'success',

        reserved: 'warning',

        booked: 'info',

        sold: 'error',

        under_construction: 'orange',

        rented: 'purple',

        unavailable: 'grey',

        cancelled: 'red'

      }


      return colors[status] || 'grey'

    },


    /*
     * Price formatting
     */
    formatPrice(value) {

      const number =
        Number(value || 0)


      return number.toLocaleString(
        'en-PK'
      )

    }

  },


  watch: {

    /*
     * Search/status/type/price filters
     * are intentionally not auto-loaded.
     *
     * User clicks Apply.
     */

    'filters.project_id'() {

      /*
       * Project changes are handled by
       * projectChanged().
       */

    }

  }

}

</script>


<style scoped>

.properties-page {
  width: 100%;
}


/* Summary */

.summary-card {
  transition:
    transform 0.15s ease,
    box-shadow 0.15s ease;
}

.summary-card:hover {
  transform: translateY(-2px);

  box-shadow:
    0 5px 15px
    rgba(0, 0, 0, 0.08);
}


/* Property Card */

.property-card {

  cursor: pointer;

  transition:
    transform 0.15s ease,
    box-shadow 0.15s ease;

  position: relative;

  overflow: hidden;

}

.property-card:hover {

  transform:
    translateY(-3px);

  box-shadow:
    0 6px 20px
    rgba(0, 0, 0, 0.12);

}


/* Property Status */

.property-status {

  position: absolute;

  top: 10px;

  right: 10px;

  padding: 4px 8px;

  border-radius: 12px;

  font-size: 10px;

  font-weight: 600;

  text-transform: uppercase;

}


/* Information */

.property-info {

  display: flex;

  align-items: center;

  font-size: 13px;

  margin-top: 7px;

  color: #555;

}


/* Status */

.status-available {

  background: #e8f5e9;

  color: #2e7d32;

}

.status-reserved {

  background: #fff8e1;

  color: #f57c00;

}

.status-booked {

  background: #e3f2fd;

  color: #1976d2;

}

.status-sold {

  background: #ffebee;

  color: #c62828;

}

.status-under_construction {

  background: #fff3e0;

  color: #ef6c00;

}

.status-rented {

  background: #f3e5f5;

  color: #7b1fa2;

}

.status-unavailable,
.status-cancelled {

  background: #eeeeee;

  color: #616161;

}


/* Table */

.property-table {

  border-radius: 8px;

}


.property-link {

  cursor: pointer;

}


.property-link:hover {

  text-decoration: underline;

}

</style>
