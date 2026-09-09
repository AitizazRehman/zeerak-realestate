import Vue from 'vue'
import VueRouter from 'vue-router'
import store from '../store'
import Login from '../views/auth/Login.vue'
import AdminLayout from '../layouts/AdminLayout.vue'
import Dashboard from '../views/dashboard/Dashboard.vue'
import Users from '../views/users/Users.vue'
import NotFound from '../views/errors/NotFound.vue'
import Forbidden from '../views/errors/Forbidden.vue'
import Projects from '../views/admin/real-estate/Projects.vue'
import ProjectForm from '../views/admin/real-estate/ProjectForm.vue'
import Blocks from '../views/admin/real-estate/Blocks.vue'
import Properties from '../views/admin/real-estate/Properties.vue'
import PropertyForm from '../views/admin/real-estate/PropertyForm.vue'
import PropertyDetails from '../views/admin/real-estate/PropertyDetails.vue'
import Customers from '../views/admin/sales/Customers.vue'
import Leads from '../views/admin/sales/Leads.vue'
import SiteVisits from '../views/admin/sales/SiteVisits.vue'
import Bookings from '../views/admin/sales/Bookings.vue'
import Payments from '../views/admin/sales/Payments.vue'
import Installments from '../views/admin/sales/Installments.vue'
import Commissions from '../views/admin/sales/Commissions.vue'
import SalesDashboard from '../views/admin/sales/SalesDashboard.vue'
import Expenses from '../views/admin/finance/Expenses.vue'
import Construction from '../views/admin/construction/Construction.vue'
import Reports from '../views/admin/reports/Reports.vue'
import Settings from '../views/admin/settings/Settings.vue'

Vue.use(VueRouter)

const routes = [
    { path: '/login', name: 'login', component: Login, meta: { guest: true } },
    {
        path: '/admin',
        component: AdminLayout,
        meta: { requiresAuth: true },
        children: [
            { path: '', redirect: { name: 'dashboard' } },
            { path: 'dashboard', name: 'dashboard', component: Dashboard, meta: { permission: 'dashboard.view' } },
            { path: 'sales/dashboard', name: 'sales-dashboard', component: SalesDashboard, meta: { permission: 'dashboard.view' } },
            { path: 'users', name: 'users', component: Users, meta: { permission: 'users.view' } },
            { path: 'projects', name: 'projects', component: Projects, meta: { permission: 'projects.view' } },
            { path: 'projects/create', name: 'project-create', component: ProjectForm, meta: { permission: 'projects.create' } },
            { path: 'projects/:id/edit', name: 'project-edit', component: ProjectForm, meta: { permission: 'projects.edit' } },
            { path: 'blocks', name: 'blocks', component: Blocks, meta: { permission: 'projects.view' } },
            { path: 'properties', name: 'properties', component: Properties, meta: { permission: 'properties.view' } },
            { path: 'properties/create', name: 'property-create', component: PropertyForm, meta: { permission: 'properties.create' } },
            { path: 'properties/:id', name: 'property-details', component: PropertyDetails, meta: { permission: 'properties.view' } },
            { path: 'properties/:id/edit', name: 'property-edit', component: PropertyForm, meta: { permission: 'properties.edit' } },
            { path: 'customers', name: 'customers', component: Customers, meta: { permission: 'customers.view' } },
            { path: 'leads', name: 'leads', component: Leads, meta: { permission: 'leads.view' } },
            { path: 'site-visits', name: 'site-visits', component: SiteVisits, meta: { permission: 'site_visits.view' } },
            { path: 'bookings', name: 'bookings', component: Bookings, meta: { permission: 'sales.view' } },
            { path: 'payments', name: 'payments', component: Payments, meta: { permission: 'payments.view' } },
            { path: 'installments', name: 'installments', component: Installments, meta: { permission: 'installments.view' } },
            { path: 'commissions', name: 'commissions', component: Commissions, meta: { permission: 'commissions.view' } },
            { path: 'expenses', name: 'expenses', component: Expenses, meta: { permission: 'expenses.view' } },
            { path: 'construction', name: 'construction', component: Construction, meta: { permission: 'construction.view' } },
            { path: 'reports', name: 'reports', component: Reports, meta: { permission: 'reports.view' } },
            { path: 'settings', name: 'settings', component: Settings, meta: { permission: 'settings.view' } }
        ]
    },
    { path: '/403', name: 'forbidden', component: Forbidden, meta: { requiresAuth: true } },
    { path: '*', component: NotFound }
]

const router = new VueRouter({
    mode: 'history',
    routes,
    scrollBehavior() {
        return { x: 0, y: 0 }
    }
})

const hasPermission = permission => {
    if (!permission) return true

    const user = store.getters['auth/user'] || {}
    const permissions = Array.isArray(user.permissions) ? user.permissions : []

    return permissions.indexOf('*') !== -1 || permissions.indexOf(permission) !== -1
}

router.beforeEach((to, from, next) => {
    const authenticated = store.getters['auth/isAuthenticated']

    if (to.matched.some(route => route.meta.requiresAuth) && !authenticated) {
        return next({ name: 'login' })
    }

    if (to.matched.some(route => route.meta.guest) && authenticated) {
        return next({ name: 'dashboard' })
    }

    const permissionRoute = to.matched.find(route => route.meta && route.meta.permission)

    if (permissionRoute && !hasPermission(permissionRoute.meta.permission)) {
        return next({ name: 'forbidden' })
    }

    next()
})

export default router
