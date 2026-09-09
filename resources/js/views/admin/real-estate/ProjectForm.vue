<template>
  <v-card flat class="pa-5">

    <div class="d-flex align-center mb-5">

      <div>
        <h2 class="text-h5 font-weight-bold">
          {{ isEdit ? 'Edit Project' : 'Add Project' }}
        </h2>

        <div class="text-caption grey--text">
          {{ isEdit
            ? 'Update project information'
            : 'Create a new real estate project'
          }}
        </div>
      </div>

      <v-spacer />

      <v-btn
        text
        @click="$router.push('/admin/projects')"
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

        <v-col cols="12" md="8">

          <v-card outlined class="pa-4 mb-4">

            <div class="text-subtitle-1 font-weight-bold mb-4">
              Basic Information
            </div>

            <v-row>

              <v-col cols="12" md="8">
                <v-text-field
                  v-model="form.name"
                  label="Project Name *"
                  outlined
                  dense
                  :rules="[rules.required]"
                />
              </v-col>

              <v-col cols="12" md="4">
                <v-text-field
                  v-model="form.code"
                  label="Project Code *"
                  outlined
                  dense
                  :rules="[rules.required]"
                />
              </v-col>

              <v-col cols="12" md="6">
                <v-select
                  v-model="form.project_type"
                  :items="projectTypes"
                  label="Project Type"
                  outlined
                  dense
                />
              </v-col>

              <v-col cols="12" md="6">
                <v-select
                  v-model="form.status"
                  :items="statuses"
                  label="Status"
                  outlined
                  dense
                />
              </v-col>

              <v-col cols="12">
                <v-textarea
                  v-model="form.description"
                  label="Description"
                  outlined
                  rows="4"
                />
              </v-col>

            </v-row>

          </v-card>

          <v-card outlined class="pa-4 mb-4">

            <div class="text-subtitle-1 font-weight-bold mb-4">
              Location
            </div>

            <v-row>

              <v-col cols="12" md="6">
                <v-text-field
                  v-model="form.location"
                  label="Location"
                  outlined
                  dense
                />
              </v-col>

              <v-col cols="12" md="6">
                <v-text-field
                  v-model="form.city"
                  label="City"
                  outlined
                  dense
                />
              </v-col>

              <v-col cols="12">
                <v-textarea
                  v-model="form.address"
                  label="Address"
                  outlined
                  dense
                  rows="2"
                />
              </v-col>

              <v-col cols="12" md="6">
                <v-text-field
                  v-model="form.latitude"
                  label="Latitude"
                  outlined
                  dense
                />
              </v-col>

              <v-col cols="12" md="6">
                <v-text-field
                  v-model="form.longitude"
                  label="Longitude"
                  outlined
                  dense
                />
              </v-col>

            </v-row>

          </v-card>

          <v-card outlined class="pa-4">

            <div class="text-subtitle-1 font-weight-bold mb-4">
              Project Area & Budget
            </div>

            <v-row>

              <v-col cols="12" md="4">
                <v-text-field
                  v-model="form.total_area"
                  label="Total Area"
                  type="number"
                  outlined
                  dense
                />
              </v-col>

              <v-col cols="12" md="2">
                <v-select
                  v-model="form.area_unit"
                  :items="areaUnits"
                  label="Unit"
                  outlined
                  dense
                />
              </v-col>

              <v-col cols="12" md="3">
                <v-text-field
                  v-model="form.budget"
                  label="Budget"
                  type="number"
                  outlined
                  dense
                  prefix="Rs."
                />
              </v-col>

              <v-col cols="12" md="3">
                <v-text-field
                  v-model="form.actual_cost"
                  label="Actual Cost"
                  type="number"
                  outlined
                  dense
                  prefix="Rs."
                />
              </v-col>

            </v-row>

          </v-card>

        </v-col>

        <v-col cols="12" md="4">

          <v-card outlined class="pa-4 mb-4">

            <div class="text-subtitle-1 font-weight-bold mb-4">
              Branch
            </div>

            <v-select
              v-model="form.branch_id"
              :items="branches"
              item-text="name"
              item-value="id"
              label="Select Branch *"
              outlined
              dense
              :rules="[rules.required]"
            />

          </v-card>

          <v-card outlined class="pa-4 mb-4">

            <div class="text-subtitle-1 font-weight-bold mb-4">
              Dates
            </div>

            <v-text-field
              v-model="form.start_date"
              label="Start Date"
              type="date"
              outlined
              dense
            />

            <v-text-field
              v-model="form.expected_completion_date"
              label="Expected Completion"
              type="date"
              outlined
              dense
            />

            <v-text-field
              v-model="form.actual_completion_date"
              label="Actual Completion"
              type="date"
              outlined
              dense
            />

          </v-card>

          <v-card outlined class="pa-4 mb-4">

            <div class="text-subtitle-1 font-weight-bold mb-4">
              Construction Progress
            </div>

            <v-slider
              v-model="form.construction_progress"
              min="0"
              max="100"
              thumb-label
            />

            <div class="text-center text-h6">
              {{ form.construction_progress }}%
            </div>

          </v-card>

          <v-card outlined class="pa-4">

            <v-switch
              v-model="form.is_featured"
              label="Featured Project"
            />

            <v-switch
              v-model="form.is_active"
              label="Active Project"
            />

          </v-card>

        </v-col>

      </v-row>

      <div class="d-flex justify-end mt-5">

        <v-btn
          text
          class="mr-2"
          @click="$router.push('/admin/projects')"
        >
          Cancel
        </v-btn>

        <v-btn
          color="primary"
          depressed
          :loading="saving"
          :disabled="!valid"
          type="submit"
        >
          <v-icon left>
            mdi-content-save
          </v-icon>

          {{ isEdit ? 'Update Project' : 'Save Project' }}
        </v-btn>

      </div>

    </v-form>

  </v-card>
</template>

<script>
import api from '../../../services/api'

export default {
  name: 'ProjectForm',

  data() {
    return {
      valid: false,
      saving: false,

      branches: [],

      projectTypes: [
        'Residential',
        'Commercial',
        'Mixed Use',
        'Housing Scheme',
        'Apartment',
        'Villa',
        'Farm House'
      ],

      statuses: [
        'planning',
        'approved',
        'active',
        'under_construction',
        'completed',
        'on_hold',
        'cancelled'
      ],

      areaUnits: [
        'Marla',
        'Kanal',
        'Sq Ft',
        'Sq Yard',
        'Acre'
      ],

      form: {
        branch_id: null,
        name: '',
        code: '',
        project_type: null,
        description: '',
        location: '',
        address: '',
        city: '',
        latitude: null,
        longitude: null,
        total_area: null,
        area_unit: 'Marla',
        start_date: null,
        expected_completion_date: null,
        actual_completion_date: null,
        status: 'planning',
        construction_progress: 0,
        budget: 0,
        actual_cost: 0,
        is_featured: false,
        is_active: true
      },

      rules: {
        required: value =>
          !!value || 'This field is required'
      }
    }
  },

  computed: {
    isEdit() {
      return !!this.$route.params.id
    }
  },

  mounted() {
    this.loadBranches()

    if (this.isEdit) {
      this.loadProject()
    }
  },

  methods: {

    async loadBranches() {
      try {
        const response = await api.get('/branches', {
          params: {
            per_page: 100,
            is_active: 1
          }
        })

        this.branches = response.data.data || response.data

      } catch (error) {
        console.error(error)
      }
    },

    async loadProject() {
      try {
        const response = await api.get(
          `/projects/${this.$route.params.id}`
        )

        this.form = {
          ...this.form,
          ...response.data.data
        }

      } catch (error) {
        console.error(error)
      }
    },

    async save() {
      if (!this.$refs.form.validate()) {
        return
      }

      this.saving = true

      try {

        if (this.isEdit) {

          await api.put(
            `/projects/${this.$route.params.id}`,
            this.form
          )

        } else {

          await api.post(
            '/projects',
            this.form
          )

        }

        this.$router.push('/admin/projects')

      } catch (error) {

        console.error(error)

        if (error.response) {
          console.log(
            error.response.data
          )
        }

      } finally {
        this.saving = false
      }
    }
  }
}
</script>