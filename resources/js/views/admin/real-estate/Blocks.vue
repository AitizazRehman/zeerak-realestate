<template>
  <div>
    <v-card flat class="pa-4">
      <div class="d-flex align-center mb-5">
        <div><h2 class="text-h5 font-weight-bold">Project Blocks</h2><div class="text-caption grey--text">Manage blocks and units within projects</div></div>
        <v-spacer />
        <v-btn v-if="$can('projects.create')" color="primary" depressed @click="openCreate"><v-icon left>mdi-plus</v-icon>Add Block</v-btn>
      </div>
      <v-row dense>
        <v-col cols="12" md="5"><v-select v-model="filters.project_id" :items="projects" item-text="name" item-value="id" label="Project" outlined dense clearable @change="loadBlocks" /></v-col>
        <v-col cols="12" md="5"><v-text-field v-model="filters.search" label="Search block" prepend-inner-icon="mdi-magnify" outlined dense clearable @keyup.enter="loadBlocks" /></v-col>
        <v-col cols="12" md="2"><v-btn block outlined height="40" @click="loadBlocks">Search</v-btn></v-col>
      </v-row>
      <v-data-table :headers="headers" :items="blocks" :loading="loading" :server-items-length="total" :options.sync="options" @update:options="loadBlocks">
        <template v-slot:item.name="{ item }"><div class="font-weight-medium">{{ item.name }}</div><div class="text-caption grey--text">{{ item.code }}</div></template>
        <template v-slot:item.project="{ item }">{{ item.project ? item.project.name : '-' }}</template>
        <template v-slot:item.total_area="{ item }">{{ item.total_area || 0 }} {{ item.area_unit || 'Marla' }}</template>
        <template v-slot:item.is_active="{ item }"><v-chip x-small :color="item.is_active ? 'success' : 'grey'" text-color="white">{{ item.is_active ? 'Active' : 'Inactive' }}</v-chip></template>
        <template v-slot:item.actions="{ item }">
          <v-btn v-if="$can('properties.view')" icon small @click="viewProperties(item)"><v-icon>mdi-home-group</v-icon></v-btn>
          <v-btn v-if="$can('projects.edit')" icon small @click="editBlock(item)"><v-icon>mdi-pencil</v-icon></v-btn>
          <v-btn v-if="$can('projects.delete')" icon small color="error" @click="deleteBlock(item)"><v-icon>mdi-delete</v-icon></v-btn>
        </template>
      </v-data-table>
    </v-card>

    <v-dialog v-model="dialog" max-width="650" persistent>
      <v-card>
        <v-card-title>{{ editing ? 'Edit Block' : 'Add Block' }}<v-spacer /><v-btn icon @click="dialog = false"><v-icon>mdi-close</v-icon></v-btn></v-card-title>
        <v-card-text>
          <v-form ref="form">
            <v-select v-model="form.project_id" :items="projects" item-text="name" item-value="id" label="Project *" outlined dense :rules="[required]" :disabled="!canSave" />
            <v-text-field v-model="form.name" label="Block Name *" outlined dense :rules="[required]" :disabled="!canSave" />
            <v-text-field v-model="form.code" label="Block Code *" outlined dense :rules="[required]" :disabled="!canSave" />
            <v-row><v-col cols="8"><v-text-field v-model="form.total_area" label="Total Area" type="number" outlined dense :disabled="!canSave" /></v-col><v-col cols="4"><v-select v-model="form.area_unit" :items="areaUnits" label="Unit" outlined dense :disabled="!canSave" /></v-col></v-row>
            <v-text-field v-model="form.total_units" label="Total Units" type="number" outlined dense :disabled="!canSave" />
            <v-textarea v-model="form.description" label="Description" outlined dense rows="3" :disabled="!canSave" />
            <v-switch v-model="form.is_active" label="Active" :disabled="!canSave" />
          </v-form>
        </v-card-text>
        <v-card-actions><v-spacer /><v-btn text @click="dialog = false">Cancel</v-btn><v-btn v-if="canSave" color="primary" depressed :loading="saving" @click="saveBlock">Save</v-btn></v-card-actions>
      </v-card>
    </v-dialog>
  </div>
</template>

<script>
import api from '../../../services/api'

export default {
  name: 'Blocks',
  data() {
    return {
      loading: false, saving: false, blocks: [], projects: [], total: 0, dialog: false, editing: false, selectedId: null,
      options: { page: 1, itemsPerPage: 20 }, filters: { project_id: null, search: '' },
      areaUnits: ['Marla', 'Kanal', 'Sq Ft', 'Sq Yard', 'Acre'],
      form: { project_id: null, name: '', code: '', description: '', total_area: null, area_unit: 'Marla', total_units: 0, is_active: true },
      headers: [
        { text: 'Block', value: 'name' }, { text: 'Project', value: 'project' }, { text: 'Area', value: 'total_area' },
        { text: 'Units', value: 'total_units' }, { text: 'Status', value: 'is_active' }, { text: 'Actions', value: 'actions', sortable: false, align: 'right' }
      ]
    }
  },
  computed: {
    canSave() { return this.editing ? this.$can('projects.edit') : this.$can('projects.create') }
  },
  mounted() { this.loadProjects(); this.loadBlocks() },
  methods: {
    required(value) { return !!value || 'This field is required' },
    async loadProjects() {
      try { const response = await api.get('/projects', { params: { per_page: 100, is_active: 1 } }); this.projects = response.data.data || [] } catch (error) { console.error(error) }
    },
    async loadBlocks() {
      this.loading = true
      try {
        const response = await api.get('/project-blocks', { params: { page: this.options.page, per_page: this.options.itemsPerPage, project_id: this.filters.project_id, search: this.filters.search } })
        this.blocks = response.data.data || []; this.total = response.data.total || 0
      } catch (error) { console.error(error) } finally { this.loading = false }
    },
    openCreate() {
      if (!this.$can('projects.create')) return
      this.editing = false; this.selectedId = null
      this.form = { project_id: this.filters.project_id, name: '', code: '', description: '', total_area: null, area_unit: 'Marla', total_units: 0, is_active: true }
      this.dialog = true
    },
    editBlock(block) {
      if (!this.$can('projects.edit')) return
      this.editing = true; this.selectedId = block.id
      this.form = { project_id: block.project_id, name: block.name, code: block.code, description: block.description, total_area: block.total_area, area_unit: block.area_unit || 'Marla', total_units: block.total_units || 0, is_active: !!block.is_active }
      this.dialog = true
    },
    async saveBlock() {
      if (!this.canSave || !this.$refs.form.validate()) return
      this.saving = true
      try {
        if (this.editing) await api.put(`/project-blocks/${this.selectedId}`, this.form)
        else await api.post('/project-blocks', this.form)
        this.dialog = false; this.loadBlocks()
      } catch (error) { console.error(error) } finally { this.saving = false }
    },
    async deleteBlock(block) {
      if (!this.$can('projects.delete')) return
      if (!confirm(`Delete block "${block.name}"?`)) return
      try { await api.delete(`/project-blocks/${block.id}`); this.loadBlocks() } catch (error) { console.error(error) }
    },
    viewProperties(block) {
      if (!this.$can('properties.view')) return
      this.$router.push({ path: '/admin/properties', query: { project_id: block.project_id, block_id: block.id } })
    }
  }
}
</script>
