<template>
  <div class="page">
    <v-card flat class="hero pa-5 mb-4">
      <div class="d-flex flex-wrap align-center">
        <div>
          <div class="text-overline">USER MANAGEMENT</div>
          <h1 class="text-h5 font-weight-bold">Roles & Permissions</h1>
          <div class="grey--text">Create roles and control exactly what each role can access.</div>
        </div>
        <v-spacer />
        <v-btn v-if="$can('roles.create')" color="#165134" dark depressed @click="openCreate">
          <v-icon left>mdi-shield-plus</v-icon> Add Role
        </v-btn>
      </div>
    </v-card>

    <v-alert v-if="error" type="error" dense text class="mb-4">{{ error }}</v-alert>

    <v-row>
      <v-col cols="12" md="5">
        <v-card flat outlined>
          <v-card-title>Roles <v-spacer /><v-chip small outlined>{{ roles.length }}</v-chip></v-card-title>
          <v-divider />
          <v-list two-line>
            <v-list-item v-for="role in roles" :key="role.id" :class="{ 'selected-role': selected && selected.id === role.id }" @click="selectRole(role)">
              <v-list-item-avatar><v-icon color="#165134">mdi-shield-account</v-icon></v-list-item-avatar>
              <v-list-item-content>
                <v-list-item-title class="font-weight-medium">{{ role.name }}</v-list-item-title>
                <v-list-item-subtitle>{{ role.users_count || 0 }} user(s) · {{ role.permissions ? role.permissions.length : 0 }} permissions</v-list-item-subtitle>
              </v-list-item-content>
              <v-list-item-action>
                <div>
                  <v-btn v-if="$can('roles.edit')" icon small @click.stop="openEdit(role)"><v-icon small>mdi-pencil</v-icon></v-btn>
                  <v-btn v-if="$can('roles.delete')" icon small color="error" :disabled="role.name === 'Super Admin' || role.users_count > 0" @click.stop="remove(role)"><v-icon small>mdi-delete-outline</v-icon></v-btn>
                </div>
              </v-list-item-action>
            </v-list-item>
            <v-list-item v-if="!roles.length"><v-list-item-content><v-list-item-title class="grey--text">No roles found.</v-list-item-title></v-list-item-content></v-list-item>
          </v-list>
        </v-card>
      </v-col>

      <v-col cols="12" md="7">
        <v-card flat outlined v-if="selected">
          <v-card-title><v-icon left color="#165134">mdi-key-chain</v-icon>{{ selected.name }} Permissions</v-card-title>
          <v-divider />
          <v-card-text>
            <div class="d-flex flex-wrap align-center mb-4">
              <v-chip small class="mr-2" outlined>{{ selectedPermissionCount }} selected</v-chip>
              <v-btn v-if="canEditSelected" text small @click="selectAll">Select all</v-btn>
              <v-btn v-if="canEditSelected" text small @click="clearAll">Clear all</v-btn>
            </div>
            <v-expansion-panels multiple>
              <v-expansion-panel v-for="module in permissionModules" :key="module.key">
                <v-expansion-panel-header>
                  <div class="d-flex align-center"><v-icon small class="mr-3">{{ module.icon }}</v-icon><strong>{{ module.label }}</strong><v-chip x-small class="ml-3" outlined>{{ selectedCount(module.key) }}/{{ module.permissions.length }}</v-chip></div>
                </v-expansion-panel-header>
                <v-expansion-panel-content>
                  <v-row dense>
                    <v-col v-for="permission in module.permissions" :key="permission.id" cols="12" sm="6">
                      <v-checkbox v-model="selectedPermissionIds" :value="permission.id" :label="actionLabel(permission.name)" dense hide-details :disabled="!canEditSelected" />
                    </v-col>
                  </v-row>
                </v-expansion-panel-content>
              </v-expansion-panel>
            </v-expansion-panels>
          </v-card-text>
          <v-card-actions v-if="canEditSelected">
            <v-spacer /><v-btn color="#165134" dark :loading="saving" @click="savePermissions"><v-icon left>mdi-content-save</v-icon>Save Permissions</v-btn>
          </v-card-actions>
        </v-card>
        <v-card v-else flat outlined class="empty-card"><v-card-text class="text-center py-12 grey--text"><v-icon size="54">mdi-shield-key-outline</v-icon><div class="mt-3">Select a role to manage its permissions.</div></v-card-text></v-card>
      </v-col>
    </v-row>

    <v-dialog v-model="dialog" max-width="520" persistent>
      <v-card>
        <v-card-title>{{ editing ? 'Edit Role' : 'Create Role' }}<v-spacer /><v-btn icon @click="dialog=false"><v-icon>mdi-close</v-icon></v-btn></v-card-title>
        <v-card-text>
          <v-alert v-if="formError" type="error" dense text>{{ formError }}</v-alert>
          <v-text-field v-model="form.name" label="Role name *" outlined dense autofocus :disabled="!canSaveRole" :rules="[required]" />
          <div class="text-caption grey--text">After saving the role, you can fine-tune its permissions on the right.</div>
        </v-card-text>
        <v-card-actions><v-spacer /><v-btn text @click="dialog=false">Cancel</v-btn><v-btn v-if="canSaveRole" color="#165134" dark :loading="saving" @click="saveRole">{{ editing ? 'Update' : 'Create' }}</v-btn></v-card-actions>
      </v-card>
    </v-dialog>
  </div>
</template>

<script>
import api from '../../services/api'

const MODULES = [
  ['dashboard', 'Dashboard', 'mdi-view-dashboard'], ['users', 'Users', 'mdi-account-group'], ['roles', 'Roles', 'mdi-shield-account'],
  ['projects', 'Projects', 'mdi-city'], ['properties', 'Properties', 'mdi-home-city'], ['customers', 'Customers', 'mdi-account-multiple'],
  ['leads', 'Leads', 'mdi-account-arrow-right'], ['site_visits', 'Site Visits', 'mdi-calendar-account'], ['sales', 'Sales', 'mdi-cart'],
  ['installments', 'Installments', 'mdi-calendar-clock'], ['payments', 'Payments', 'mdi-cash-multiple'], ['expenses', 'Expenses', 'mdi-cash-minus'],
  ['commissions', 'Commissions', 'mdi-percent'], ['construction', 'Construction', 'mdi-hard-hat'], ['materials', 'Materials', 'mdi-package-variant'],
  ['vendors', 'Vendors', 'mdi-store'], ['contractors', 'Contractors', 'mdi-account-hard-hat'], ['documents', 'Documents', 'mdi-file-document'],
  ['complaints', 'Complaints', 'mdi-message-alert'], ['reports', 'Reports', 'mdi-chart-box'], ['settings', 'Settings', 'mdi-cog']
]

export default {
  name: 'Roles',
  data: () => ({
    loading: false, saving: false, error: '', formError: '', roles: [], permissions: [], selected: null, selectedPermissionIds: [], dialog: false, editing: null,
    form: { name: '' }
  }),
  computed: {
    canSaveRole () { return this.editing ? this.$can('roles.edit') : this.$can('roles.create') },
    canEditSelected () { return !!this.selected && this.$can('roles.edit') },
    selectedPermissionCount () { return this.selectedPermissionIds.length },
    permissionModules () {
      return MODULES.map(item => ({ key: item[0], label: item[1], icon: item[2], permissions: this.permissions.filter(p => p.name.indexOf(item[0] + '.') === 0) })).filter(m => m.permissions.length)
    }
  },
  mounted () { this.load() },
  methods: {
    required (v) { return !!String(v || '').trim() || 'Role name is required.' },
    async load () {
      this.loading = true; this.error = ''
      try {
        const results = await Promise.all([api.get('/roles'), api.get('/permissions')])
        this.roles = results[0].data.data || []
        this.permissions = results[1].data.data || []
        if (this.selected) {
          const fresh = this.roles.find(r => r.id === this.selected.id)
          if (fresh) this.selectRole(fresh)
        } else if (this.roles.length) this.selectRole(this.roles[0])
      } catch (e) { this.error = (e.response && e.response.data && e.response.data.message) || 'Unable to load roles and permissions.' }
      finally { this.loading = false }
    },
    selectRole (role) { this.selected = role; this.selectedPermissionIds = (role.permissions || []).map(p => p.id) },
    selectedCount (module) { return this.permissions.filter(p => p.name.indexOf(module + '.') === 0 && this.selectedPermissionIds.indexOf(p.id) !== -1).length },
    actionLabel (name) { const action = name.split('.').pop(); return action.charAt(0).toUpperCase() + action.slice(1) },
    selectAll () { this.selectedPermissionIds = this.permissions.map(p => p.id) },
    clearAll () { this.selectedPermissionIds = [] },
    openCreate () { this.editing = null; this.form = { name: '' }; this.formError = ''; this.dialog = true },
    openEdit (role) { if (!this.$can('roles.edit')) return; this.editing = role; this.form = { name: role.name }; this.formError = ''; this.dialog = true },
    async saveRole () {
      if (!this.canSaveRole || !this.form.name.trim()) return
      this.saving = true; this.formError = ''
      try {
        const payload = { name: this.form.name.trim(), permissions: this.editing ? (this.editing.permissions || []).map(p => p.id) : [] }
        if (this.editing) await api.put('/roles/' + this.editing.id, payload); else await api.post('/roles', payload)
        this.dialog = false; await this.load()
        if (!this.editing && this.roles.length) this.selectRole(this.roles[this.roles.length - 1])
      } catch (e) { this.formError = (e.response && e.response.data && e.response.data.message) || 'Unable to save role.' }
      finally { this.saving = false }
    },
    async savePermissions () {
      if (!this.canEditSelected || !this.selected) return
      this.saving = true; this.error = ''
      try {
        await api.put('/roles/' + this.selected.id, { name: this.selected.name, permissions: this.selectedPermissionIds })
        await this.load()
        this.$root.$emit('show-success', 'Role permissions updated successfully.')
      } catch (e) { this.error = (e.response && e.response.data && e.response.data.message) || 'Unable to update permissions.' }
      finally { this.saving = false }
    },
    async remove (role) {
      if (!this.$can('roles.delete') || role.name === 'Super Admin' || role.users_count > 0) return
      if (!window.confirm('Delete role "' + role.name + '"?')) return
      try { await api.delete('/roles/' + role.id); if (this.selected && this.selected.id === role.id) this.selected = null; await this.load() } catch (e) { this.error = (e.response && e.response.data && e.response.data.message) || 'Unable to delete role.' }
    }
  }
}
</script>
<style scoped>
.page { width: 100%; }
.hero { border-left: 4px solid #165134; }
.selected-role { background: rgba(22, 81, 52, 0.08); border-left: 3px solid #165134; }
.empty-card { min-height: 300px; display: flex; align-items: center; justify-content: center; }
</style>
