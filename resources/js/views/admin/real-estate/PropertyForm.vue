<template>
  <v-container fluid>

    <!-- Header -->
    <div class="d-flex align-center mb-6">

      <div>
        <div class="text-h5 font-weight-bold">
          {{ isEdit ? 'Edit Property' : 'Add Property' }}
        </div>

        <div class="text-caption grey--text">
          {{ isEdit
            ? 'Update property information'
            : 'Create a new property'
          }}
        </div>
      </div>

      <v-spacer />

      <v-btn
        text
        @click="$router.push('/admin/properties')"
      >
        <v-icon left>
          mdi-arrow-left
        </v-icon>

        Back
      </v-btn>

    </div>

    <v-form
      ref="form"
      v-model="valid"
      @submit.prevent="save"
    >

      <v-row>

        <!-- Main Information -->
        <v-col cols="12" md="8">

          <v-card outlined>

            <v-card-title>
              <v-icon left>
                mdi-home-city
              </v-icon>

              Property Information
            </v-card-title>

            <v-divider />

            <v-card-text>

              <v-row>

                <!-- Project -->
                <v-col cols="12" md="6">

                  <v-select
                    v-model="form.project_id"
                    :items="projects"
                    item-text="name"
                    item-value="id"
                    label="Project"
                    outlined
                    dense
                    :rules="[required]"
                    @change="loadBlocks"
                  />

                </v-col>

                <!-- Block -->
                <v-col cols="12" md="6">

                  <v-select
                    v-model="form.block_id"
                    :items="blocks"
                    item-text="name"
                    item-value="id"
                    label="Block"
                    outlined
                    dense
                    :rules="[required]"
                  />

                </v-col>

                <!-- Property Number -->
                <v-col cols="12" md="4">

                  <v-text-field
                    v-model="form.property_number"
                    label="Property Number"
                    placeholder="A-101"
                    outlined
                    dense
                    :rules="[required]"
                  />

                </v-col>

                <!-- Type -->
                <v-col cols="12" md="4">

                  <v-select
                    v-model="form.property_type"
                    :items="propertyTypes"
                    label="Property Type"
                    outlined
                    dense
                    :rules="[required]"
                  />

                </v-col>

                <!-- Status -->
                <v-col cols="12" md="4">

                  <v-select
                    v-model="form.status"
                    :items="statuses"
                    item-text="text"
                    item-value="value"
                    label="Status"
                    outlined
                    dense
                  />

                </v-col>

                <!-- Size -->
                <v-col cols="12" md="6">

                  <v-row>

                    <v-col cols="7">

                      <v-text-field
                        v-model="form.size"
                        label="Size"
                        type="number"
                        outlined
                        dense
                        :rules="[required]"
                      />

                    </v-col>

                    <v-col cols="5">

                      <v-select
                        v-model="form.size_unit"
                        :items="sizeUnits"
                        label="Unit"
                        outlined
                        dense
                      />

                    </v-col>

                  </v-row>

                </v-col>

                <!-- Covered Area -->
                <v-col cols="12" md="6">

                  <v-row>

                    <v-col cols="7">

                      <v-text-field
                        v-model="form.covered_area"
                        label="Covered Area"
                        type="number"
                        outlined
                        dense
                      />

                    </v-col>

                    <v-col cols="5">

                      <v-select
                        v-model="form.covered_area_unit"
                        :items="coveredAreaUnits"
                        label="Unit"
                        outlined
                        dense
                      />

                    </v-col>

                  </v-row>

                </v-col>

                <!-- Price -->
                <v-col cols="12" md="4">

                  <v-text-field
                    v-model="form.price"
                    label="Price"
                    type="number"
                    outlined
                    dense
                    prefix="Rs."
                    :rules="[required]"
                  />

                </v-col>

                <!-- Discount -->
                <v-col cols="12" md="4">

                  <v-text-field
                    v-model="form.discount"
                    label="Discount"
                    type="number"
                    outlined
                    dense
                    prefix="Rs."
                  />

                </v-col>

                <!-- Net Price -->
                <v-col cols="12" md="4">

                  <v-text-field
                    :value="netPrice"
                    label="Net Price"
                    outlined
                    dense
                    prefix="Rs."
                    readonly
                  />

                </v-col>

                <!-- Bedrooms -->
                <v-col cols="12" md="4">

                  <v-text-field
                    v-model="form.bedrooms"
                    label="Bedrooms"
                    type="number"
                    outlined
                    dense
                  />

                </v-col>

                <!-- Bathrooms -->
                <v-col cols="12" md="4">

                  <v-text-field
                    v-model="form.bathrooms"
                    label="Bathrooms"
                    type="number"
                    outlined
                    dense
                  />

                </v-col>

                <!-- Agent -->
                <v-col cols="12" md="4">

                  <v-select
                    v-model="form.assigned_agent_id"
                    :items="agents"
                    item-text="name"
                    item-value="id"
                    label="Assigned Agent"
                    outlined
                    dense
                    clearable
                  />

                </v-col>

                <!-- Address -->
                <v-col cols="12">

                  <v-text-field
                    v-model="form.address"
                    label="Address"
                    outlined
                    dense
                  />

                </v-col>

                <!-- Description -->
                <v-col cols="12">

                  <v-textarea
                    v-model="form.description"
                    label="Description"
                    outlined
                    rows="4"
                  />

                </v-col>

              </v-row>

            </v-card-text>

          </v-card>

        </v-col>

        <!-- Settings -->
        <v-col cols="12" md="4">

          <v-card outlined>

            <v-card-title>
              Settings
            </v-card-title>

            <v-divider />

            <v-card-text>

              <v-switch
                v-model="form.is_featured"
                label="Featured Property"
                color="#165134"
              />

              <v-switch
                v-model="form.is_published"
                label="Publish on Website"
                color="#165134"
              />

            </v-card-text>

          </v-card>

          <!-- Features -->
          <v-card
            outlined
            class="mt-4"
          >

            <v-card-title>
              Features
            </v-card-title>

            <v-divider />

            <v-card-text>

              <v-checkbox
                v-for="feature in featureOptions"
                :key="feature"
                v-model="selectedFeatures"
                :label="feature"
                :value="feature"
                dense
              />

            </v-card-text>

          </v-card>

        </v-col>

      </v-row>

      <!-- Save -->
      <div class="d-flex justify-end mt-6">

        <v-btn
          text
          class="mr-3"
          @click="$router.push('/admin/properties')"
        >
          Cancel
        </v-btn>

        <v-btn
          color="#165134"
          dark
          large
          type="submit"
          :loading="saving"
        >
          <v-icon left>
            mdi-content-save
          </v-icon>

          {{ isEdit ? 'Update Property' : 'Save Property' }}
        </v-btn>

      </div>

    </v-form>

    <v-snackbar
      v-model="snackbar"
      :color="snackbarColor"
      bottom
      right
    >
      {{ snackbarText }}

      <template v-slot:action="{ attrs }">

        <v-btn
          text
          v-bind="attrs"
          @click="snackbar = false"
        >
          Close
        </v-btn>

      </template>

    </v-snackbar>

  </v-container>
</template>

<script>
import api from '../../../services/api'

export default {

  name: 'PropertyForm',

  data() {
    return {

      valid: false,
      saving: false,

      projects: [],
      blocks: [],
      agents: [],

      selectedFeatures: [],

      propertyTypes: [
        'Residential',
        'Commercial',
        'Plot',
        'House',
        'Apartment',
        'Shop',
        'Office',
        'Farm House'
      ],

      sizeUnits: [
        'Marla',
        'Kanal',
        'Sq Ft',
        'Sq Yard',
        'Sq Meter'
      ],

      coveredAreaUnits: [
        'Sq Ft',
        'Sq Meter',
        'Sq Yard'
      ],

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

      featureOptions: [
        'Parking',
        'Electricity',
        'Gas',
        'Water',
        'Security',
        'Boundary Wall',
        'Sewerage',
        'Main Road',
        'Corner',
        'Park Facing',
        'Masjid Nearby',
        'School Nearby',
        'Commercial Area Nearby'
      ],

      form: {
        project_id: null,
        block_id: null,

        property_number: '',
        property_type: 'Residential',

        size: '',
        size_unit: 'Marla',

        price: 0,
        discount: 0,

        status: 'available',

        bedrooms: null,
        bathrooms: null,

        covered_area: null,
        covered_area_unit: 'Sq Ft',

        address: '',
        latitude: null,
        longitude: null,

        description: '',

        assigned_agent_id: null,

        is_featured: false,
        is_published: false
      },

      snackbar: false,
      snackbarText: '',
      snackbarColor: 'success'
    }
  },

  computed: {

    isEdit() {
      return !!this.$route.params.id
    },

    netPrice() {

      const price = Number(this.form.price) || 0
      const discount = Number(this.form.discount) || 0

      return Math.max(
        0,
        price - discount
      )
    }

  },

  created() {

    this.loadProjects()
    this.loadAgents()

    if (this.isEdit) {
      this.loadProperty()
    }

  },

  methods: {

    required(value) {

      return !!value || 'This field is required.'
    },

    async loadProjects() {

      try {

        const response = await api.get(
          '/projects',
          {
            params: {
              per_page: 100
            }
          }
        )

        this.projects =
          response.data.data || []

      } catch (error) {

        console.error(error)

      }

    },

    async loadAgents() {

      try {

        const response = await api.get(
          '/users',
          {
            params: {
              per_page: 100
            }
          }
        )

        this.agents =
          response.data.data || []

      } catch (error) {

        console.error(error)

      }

    },

    async loadBlocks() {

      if (!this.form.project_id) {

        this.blocks = []
        this.form.block_id = null

        return
      }

      try {

        const response = await api.get(
          '/project-blocks',
          {
            params: {
              project_id: this.form.project_id,
              per_page: 100
            }
          }
        )

        this.blocks =
          response.data.data || []

      } catch (error) {

        console.error(error)

      }

    },

    async loadProperty() {

      try {

        const response = await api.get(
          `/properties/${this.$route.params.id}`
        )

        const property =
          response.data.property

        this.form = {
          project_id: property.project_id,
          block_id: property.block_id,

          property_number:
            property.property_number,

          property_type:
            property.property_type,

          size:
            property.size,

          size_unit:
            property.size_unit,

          price:
            property.price,

          discount:
            property.discount,

          status:
            property.status,

          bedrooms:
            property.bedrooms,

          bathrooms:
            property.bathrooms,

          covered_area:
            property.covered_area,

          covered_area_unit:
            property.covered_area_unit,

          address:
            property.address,

          latitude:
            property.latitude,

          longitude:
            property.longitude,

          description:
            property.description,

          assigned_agent_id:
            property.assigned_agent_id,

          is_featured:
            property.is_featured,

          is_published:
            property.is_published
        }

        await this.loadBlocks()

        if (property.features) {

          this.selectedFeatures =
            property.features
              .map(item => item.feature_name)

        }

      } catch (error) {

        console.error(error)

        this.showMessage(
          'Unable to load property.',
          'error'
        )

      }

    },

    async save() {

      if (!this.$refs.form.validate()) {
        return
      }

      this.saving = true

      try {

        let response

        if (this.isEdit) {

          response = await api.put(
            `/properties/${this.$route.params.id}`,
            this.form
          )

        } else {

          response = await api.post(
            '/properties',
            this.form
          )

        }

        const property =
          response.data.property

        if (this.selectedFeatures.length) {

          await api.post(
            `/properties/${property.id}/features`,
            {
              features:
                this.selectedFeatures.map(
                  feature => ({
                    feature_name: feature,
                    feature_value: 'Yes'
                  })
                )
            }
          )

        }

        this.showMessage(
          this.isEdit
            ? 'Property updated successfully.'
            : 'Property created successfully.',
          'success'
        )

        setTimeout(() => {

          this.$router.push(
            `/admin/properties/${property.id}`
          )

        }, 600)

      } catch (error) {

        console.error(error)

        let message =
          'Unable to save property.'

        if (
          error.response &&
          error.response.data &&
          error.response.data.message
        ) {
          message =
            error.response.data.message
        }

        this.showMessage(
          message,
          'error'
        )

      } finally {

        this.saving = false

      }

    },

    showMessage(
      message,
      color = 'success'
    ) {

      this.snackbarText = message
      this.snackbarColor = color
      this.snackbar = true

    }

  }

}
</script>
