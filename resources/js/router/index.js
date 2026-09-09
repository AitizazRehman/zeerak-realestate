import Vue from 'vue'
import VueRouter from 'vue-router'

import store from '../store'

import Login from '../views/auth/Login.vue'

import AdminLayout from '../layouts/AdminLayout.vue'

import Dashboard from '../views/dashboard/Dashboard.vue'
import Users from '../views/users/Users.vue'

import NotFound from '../views/errors/NotFound.vue'

import Projects from '../views/admin/real-estate/Projects.vue'
import ProjectForm from '../views/admin/real-estate/ProjectForm.vue'

import Blocks from '../views/admin/real-estate/Blocks.vue'

import Properties from '../views/admin/real-estate/Properties.vue'
import PropertyForm from '../views/admin/real-estate/PropertyForm.vue'
import PropertyDetails from '../views/admin/real-estate/PropertyDetails.vue'


Vue.use(VueRouter)


const routes = [

    // ==================================================
    // LOGIN
    // ==================================================

    {
        path: '/login',

        name: 'login',

        component: Login,

        meta: {
            guest: true
        }
    },


    // ==================================================
    // ADMIN LAYOUT
    // ==================================================

    {
        path: '/admin',

        component: AdminLayout,

        meta: {
            requiresAuth: true
        },

        children: [

            // ------------------------------------------
            // ADMIN ROOT
            // ------------------------------------------

            {
                path: '',

                redirect: {
                    name: 'dashboard'
                }
            },


            // ------------------------------------------
            // DASHBOARD
            // ------------------------------------------

            {
                path: 'dashboard',

                name: 'dashboard',

                component: Dashboard
            },


            // ------------------------------------------
            // USERS
            // ------------------------------------------

            {
                path: 'users',

                name: 'users',

                component: Users
            },


            // ==================================================
            // REAL ESTATE
            // ==================================================


            // ------------------------------------------
            // PROJECTS
            // ------------------------------------------

            {
                path: 'projects',

                name: 'projects',

                component: Projects
            },


            // ------------------------------------------
            // CREATE PROJECT
            // ------------------------------------------

            {
                path: 'projects/create',

                name: 'project-create',

                component: ProjectForm
            },


            // ------------------------------------------
            // EDIT PROJECT
            // ------------------------------------------

            {
                path: 'projects/:id/edit',

                name: 'project-edit',

                component: ProjectForm
            },


            // ------------------------------------------
            // BLOCKS
            // ------------------------------------------

            {
                path: 'blocks',

                name: 'blocks',

                component: Blocks
            },


            // ------------------------------------------
            // PROPERTIES
            // ------------------------------------------

            {
                path: 'properties',

                name: 'properties',

                component: Properties
            },


            // ------------------------------------------
            // CREATE PROPERTY
            // ------------------------------------------

            {
                path: 'properties/create',

                name: 'property-create',

                component: PropertyForm
            },


            // ------------------------------------------
            // PROPERTY DETAILS
            // ------------------------------------------

            {
                path: 'properties/:id',

                name: 'property-details',

                component: PropertyDetails
            },


            // ------------------------------------------
            // EDIT PROPERTY
            // ------------------------------------------

            {
                path: 'properties/:id/edit',

                name: 'property-edit',

                component: PropertyForm
            },


            // ==================================================
            // CRM - PLACEHOLDERS FOR NOW
            // ==================================================

            {
                path: 'customers',

                name: 'customers',

                component: NotFound
            },


            {
                path: 'leads',

                name: 'leads',

                component: NotFound
            },


            {
                path: 'site-visits',

                name: 'site-visits',

                component: NotFound
            },


            // ==================================================
            // SALES & FINANCE - PLACEHOLDERS
            // ==================================================

            {
                path: 'bookings',

                name: 'bookings',

                component: NotFound
            },


            {
                path: 'payments',

                name: 'payments',

                component: NotFound
            },


            {
                path: 'expenses',

                name: 'expenses',

                component: NotFound
            },


            // ==================================================
            // OTHER
            // ==================================================

            {
                path: 'construction',

                name: 'construction',

                component: NotFound
            },


            {
                path: 'reports',

                name: 'reports',

                component: NotFound
            },


            {
                path: 'settings',

                name: 'settings',

                component: NotFound
            }

        ]
    },


    // ==================================================
    // 404
    // ==================================================

    {
        path: '*',

        component: NotFound
    }

]


const router = new VueRouter({

    mode: 'history',

    routes,

    scrollBehavior() {

        return {
            x: 0,
            y: 0
        }

    }

})


// ==================================================
// AUTHENTICATION GUARD
// ==================================================

router.beforeEach(
    (to, from, next) => {

        const authenticated =
            store.getters[
                'auth/isAuthenticated'
            ]


        // Protected route

        if (
            to.matched.some(
                record =>
                    record.meta.requiresAuth
            ) &&
            !authenticated
        ) {

            return next({
                name: 'login'
            })

        }


        // Already authenticated

        if (
            to.matched.some(
                record =>
                    record.meta.guest
            ) &&
            authenticated
        ) {

            return next({
                name: 'dashboard'
            })

        }


        next()

    }
)


export default router