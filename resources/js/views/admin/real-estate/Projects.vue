<template>
  <div>
    <v-card flat class="pa-4">
      <div class="d-flex align-center mb-4">
        <div>
          <h2 class="text-h5 font-weight-bold">Projects</h2>
          <div class="text-caption grey--text">Manage real estate projects</div>
        </div>
        <v-spacer />
        <v-btn
          v-if="$can('projects.create')"
          color="primary"
          depressed
          @click="$router.push('/admin/projects/create')"
        >
          <v-icon left>mdi-plus</v-icon>
          Add Project
        </v-btn>
      </div>

      <v-row dense>
        <v-col cols="12" md="5">
          <v-text-field
            v-model="filters.search"
            label="Search projects"
            prepend-inner-icon="mdi-magnify"
            outlined dense clearable
            @keyup.enter="loadProjects"
          />
        </v-col>
        <v-col cols="12" md="3">
          <v-select
            v-model="filters.status"
            :items="statuses"
            label="Status"
            outlined dense clearable
            @change="loadProjects"
          />
        </v-col>
        <v-col cols="12" md="2">
          <v-btn block height="40" outlined @click="loadProjects">
            <v-icon left>mdi-filter</v-icon>
            Filter
          </v-btn>
        </v-col>
      </v-row>

      <v-data-table
        :headers="headers"
        :items="projects"
        :loading="loading"
        :server-items-length="total"
        :options.sync="options"
        class="mt-4"
        @update:options="loadProjects"
      >
        <template v-slot:item.name="{ item }">
          <div
            class="font-weight-medium primary--text"
            style="cursor:pointer"
            @click="viewProject(item)"
          >
            {{ item.name }}
          </div>
          <div class="text-caption grey--text">{{ item.code }}</div>
        </template>

        <template v-slot:item.branch="{ item }">
          {{ item.branch ? item.branch.name : '-' }}
        </template>

        <template v-slot:item.status="{ item }">
          <v-chip small :color="statusColor(item.status)" text-color="white">
            {{ formatStatus(item.status) }}
          </v-chip>
        </template>

        <template v-slot:item.construction_progress="{ item }">
          <div style="min-width:120px">
            <v-progress-linear :value="item.construction_progress" rounded height="7" />
            <div class="text-caption mt-1">{{ item.construction_progress }}%</div>
          </div>
        </template>

        <template v-slot:item.is_active="{ item }">
          <v-icon :color="item.is_active ? 'success' : 'grey'">
            {{ item.is_active ? 'mdi-check-circle' : 'mdi-close-circle' }}
          </v-icon>
        </template>

        <template v-slot:item.actions="{ item }">
          <v-btn v-if="$can('projects.view')" icon small @click="viewProject(item)">
            <v-icon>mdi-eye</v-icon>
          </v-btn>
          <v-btn v-if="$can('projects.edit')" icon small @click="editProject(item)">
            <v-icon>mdi-pencil</v-icon>
          </v-btn>
          <v-btn v-if="$can('projects.delete')" icon small color="error" @click="deleteProject(item)">
            <v-icon>mdi-delete</v-icon>
          </v-btn>
        </template>
      </v-data-table>
    </v-card>

    <v-dialog v-model="deleteDialog" max-width="450">
      <v-card>
        <v-card-title>Delete Project</v-card-title>
        <v-card-text>
          Are you sure you want to delete
          <strong>{{ selectedProject ? selectedProject.name : '' }}</strong>?
        </v-card-text>
        <v-card-actions>
          <v-spacer />
          <v-btn text @click="deleteDialog = false">Cancel</v-btn>
          <v-btn
            v-if="$can('projects.delete')"
            color="error"
            depressed
            :loading="deleting"
            @click="confirmDelete"
          >
            Delete
          </v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>
  </div>
</template>

<script>
import api from '../../../services/api'

export default {
  name: 'Projects',

  data() {
    return {
      loading: false,
      deleting: false,
      projects: [],
      total: 0,
      options: { page: 1, itemsPerPage: 15, sortBy: [], sortDesc: [] },
      filters: { search: '', status: null },
      statuses: ['planning', 'approved', 'active', 'under_construction', 'completed', 'on_hold', 'cancelled'],
      headers: [
        { text: 'Project', value: 'name' },
        { text: 'Branch', value: 'branch' },
        { text: 'City', value: 'city' },
        { text: 'Status', value: 'status' },
        { text: 'Progress', value: 'construction_progress' },
        { text: 'Active', value: 'is_active', sortable: false },
        { text: 'Actions', value: 'actions', sortable: false, align: 'right' }
      ],
      deleteDialog: false,
      selectedProject: null
    }
  },

  mounted() {
    this.loadProjects()
  },

  methods: {
    async loadProjects() {
      this.loading = true
      try {
        const response = await api.get('/projects', {
          params: {
            page: this.options.page,
            per_page: this.options.itemsPerPage,
            search: this.filters.search,
            status: this.filters.status
          }
        })
        this.projects = response.data.data
        this.total = response.data.total
      } catch (error) {
        console.error(error)
      } finally {
        this.loading = false
      }
    },

    viewProject(project) {
      this.$router.push(`/admin/projects/${project.id}`)
    },

    editProject(project) {
      this.$router.push(`/admin/projects/${project.id}/edit`)
    },

    deleteProject(project) {
      if (!this.$can('projects.delete')) return
      this.selectedProject = project
      this.deleteDialog = true
    },

    async confirmDelete() {
      if (!this.selectedProject || !this.$can('projects.delete')) return
      this.deleting = true
      try {
        await api.delete(`/projects/${this.selectedProject.id}`)
        this.deleteDialog = false
        this.selectedProject = null
        this.loadProjects()
      } catch (error) {
        console.error(error)
      } finally {
        this.deleting = false
      }
    },

    formatStatus(status) {
      return status.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase())
    },

    statusColor(status) {
      const colors = {
        planning: 'grey', approved: 'info', active: 'success',
        under_construction: 'warning', completed: 'primary',
        on_hold: 'orange', cancelled: 'error'
      }
      return colors[status] || 'grey'
    }
  }
}
</script>
