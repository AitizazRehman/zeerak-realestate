<template>
    <v-app>

        <!-- ================= SIDEBAR ================= -->

        <v-navigation-drawer
            v-model="drawer"
            app
            :dark="darkMode"
        >

            <!-- Logo -->

            <div class="pa-5">

                <div class="d-flex align-center">

                    <v-icon
                        color="primary"
                        size="36"
                    >
                        mdi-home-city
                    </v-icon>

                    <div class="ml-3">

                        <div class="font-weight-bold">
                            ZEErAK
                        </div>

                        <small>
                            Real Estate & Builders
                        </small>

                    </div>

                </div>

            </div>

            <v-divider />

            <!-- Navigation -->

            <v-list
                nav
                dense
            >

                <!-- Dashboard -->

                <v-list-item
                    to="/admin/dashboard"
                    link
                    exact
                >

                    <v-list-item-icon>
                        <v-icon>
                            mdi-view-dashboard
                        </v-icon>
                    </v-list-item-icon>

                    <v-list-item-content>
                        <v-list-item-title>
                            Dashboard
                        </v-list-item-title>
                    </v-list-item-content>

                </v-list-item>

                <!-- Real Estate -->

                <v-list-group
                    prepend-icon="mdi-account-group"
                    no-action
                >

                    <template v-slot:activator>

                        <v-list-item-title>
                            User Management
                        </v-list-item-title>

                    </template>

                    <v-list-item
                        to="/admin/users"
                        link
                    >

                        <v-list-item-content>
                            <v-list-item-title>
                                Users
                            </v-list-item-title>
                        </v-list-item-content>

                    </v-list-item>

                    <v-list-item
                        to="/admin/blocks"
                        link
                    >

                        <v-list-item-content>
                            <v-list-item-title>
                                Permissions
                            </v-list-item-title>
                        </v-list-item-content>

                    </v-list-item>

                    <v-list-item
                        to="/admin/properties"
                        link
                    >

                        <v-list-item-content>
                            <v-list-item-title>
                                Roles
                            </v-list-item-title>
                        </v-list-item-content>

                    </v-list-item>

                </v-list-group>
                <!-- Real Estate -->

                <v-list-group
                    prepend-icon="mdi-home-city"
                    no-action
                >

                    <template v-slot:activator>

                        <v-list-item-title>
                            Real Estate
                        </v-list-item-title>

                    </template>

                    <v-list-item
                        to="/admin/projects"
                        link
                    >

                        <v-list-item-content>
                            <v-list-item-title>
                                Projects
                            </v-list-item-title>
                        </v-list-item-content>

                    </v-list-item>

                    <v-list-item
                        to="/admin/blocks"
                        link
                    >

                        <v-list-item-content>
                            <v-list-item-title>
                                Blocks
                            </v-list-item-title>
                        </v-list-item-content>

                    </v-list-item>

                    <v-list-item
                        to="/admin/properties"
                        link
                    >

                        <v-list-item-content>
                            <v-list-item-title>
                                Properties
                            </v-list-item-title>
                        </v-list-item-content>

                    </v-list-item>

                </v-list-group>


                <!-- CRM -->

                <v-list-group
                    prepend-icon="mdi-account-group"
                    no-action
                >

                    <template v-slot:activator>

                        <v-list-item-title>
                            CRM
                        </v-list-item-title>

                    </template>

                    <v-list-item
                        to="/admin/customers"
                        link
                    >

                        <v-list-item-content>
                            <v-list-item-title>
                                Customers
                            </v-list-item-title>
                        </v-list-item-content>

                    </v-list-item>

                    <v-list-item
                        to="/admin/leads"
                        link
                    >

                        <v-list-item-content>
                            <v-list-item-title>
                                Leads
                            </v-list-item-title>
                        </v-list-item-content>

                    </v-list-item>

                    <v-list-item
                        to="/admin/site-visits"
                        link
                    >

                        <v-list-item-content>
                            <v-list-item-title>
                                Site Visits
                            </v-list-item-title>
                        </v-list-item-content>

                    </v-list-item>

                </v-list-group>


                <!-- Sales -->

                <v-list-group
                    prepend-icon="mdi-cash-multiple"
                    no-action
                >

                    <template v-slot:activator>

                        <v-list-item-title>
                            Sales & Finance
                        </v-list-item-title>

                    </template>

                    <v-list-item
                        to="/admin/bookings"
                        link
                    >

                        <v-list-item-content>
                            <v-list-item-title>
                                Bookings
                            </v-list-item-title>
                        </v-list-item-content>

                    </v-list-item>

                    <v-list-item
                        to="/admin/payments"
                        link
                    >

                        <v-list-item-content>
                            <v-list-item-title>
                                Payments
                            </v-list-item-title>
                        </v-list-item-content>

                    </v-list-item>

                    <v-list-item
                        to="/admin/expenses"
                        link
                    >

                        <v-list-item-content>
                            <v-list-item-title>
                                Expenses
                            </v-list-item-title>
                        </v-list-item-content>

                    </v-list-item>

                </v-list-group>


                <!-- Construction -->

                <v-list-item
                    to="/admin/construction"
                    link
                >

                    <v-list-item-icon>
                        <v-icon>
                            mdi-hard-hat
                        </v-icon>
                    </v-list-item-icon>

                    <v-list-item-content>
                        <v-list-item-title>
                            Construction
                        </v-list-item-title>
                    </v-list-item-content>

                </v-list-item>


                <!-- Reports -->

                <v-list-item
                    to="/admin/reports"
                    link
                >

                    <v-list-item-icon>
                        <v-icon>
                            mdi-file-chart
                        </v-icon>
                    </v-list-item-icon>

                    <v-list-item-content>
                        <v-list-item-title>
                            Reports
                        </v-list-item-title>
                    </v-list-item-content>

                </v-list-item>


                <v-divider class="my-3" />


                <!-- Settings -->

                <v-list-item
                    to="/admin/settings"
                    link
                >

                    <v-list-item-icon>
                        <v-icon>
                            mdi-cog
                        </v-icon>
                    </v-list-item-icon>

                    <v-list-item-content>
                        <v-list-item-title>
                            Settings
                        </v-list-item-title>
                    </v-list-item-content>

                </v-list-item>

            </v-list>

        </v-navigation-drawer>


        <!-- ================= TOP BAR ================= -->

        <v-app-bar
            app
            flat
            outlined
        >

            <v-app-bar-nav-icon
                @click="drawer = !drawer"
            />

            <v-toolbar-title
                class="font-weight-medium"
            >
                {{ pageTitle }}
            </v-toolbar-title>

            <v-spacer />


            <!-- Dark mode -->

            <v-btn
                icon
                @click="toggleDarkMode"
            >

                <v-icon>

                    {{
                        darkMode
                            ? 'mdi-weather-sunny'
                            : 'mdi-weather-night'
                    }}

                </v-icon>

            </v-btn>


            <!-- User -->

            <v-menu offset-y>

                <template
                    v-slot:activator="{ on, attrs }"
                >

                    <v-btn
                        text
                        v-bind="attrs"
                        v-on="on"
                    >

                        <v-avatar
                            size="34"
                            color="primary"
                        >

                            <span class="white--text">

                                {{ initials }}

                            </span>

                        </v-avatar>

                        <span class="ml-2">

                            {{ userName }}

                        </span>

                        <v-icon right>
                            mdi-chevron-down
                        </v-icon>

                    </v-btn>

                </template>


                <v-list>

                    <v-list-item
                        @click="logout"
                    >

                        <v-list-item-icon>

                            <v-icon>
                                mdi-logout
                            </v-icon>

                        </v-list-item-icon>

                        <v-list-item-content>

                            <v-list-item-title>
                                Logout
                            </v-list-item-title>

                        </v-list-item-content>

                    </v-list-item>

                </v-list>

            </v-menu>

        </v-app-bar>


        <!-- ================= CONTENT ================= -->

        <v-main>

            <v-container
                fluid
                class="pa-6"
            >

                <!-- ALL ADMIN COMPONENTS APPEAR HERE -->

                <router-view />

            </v-container>

        </v-main>

    </v-app>
</template>


<script>

export default {

    name: 'AdminLayout',

    data() {

        return {

            drawer: true,

            darkMode:
                localStorage.getItem(
                    'zeerak_dark_mode'
                ) === '1'

        }

    },

    computed: {

        user() {

            return this.$store.getters[
                'auth/user'
            ]

        },

        userName() {

            return this.user
                ? this.user.name
                : 'User'

        },

        initials() {

            if (!this.user) {
                return 'U'
            }

            return this.user.name
                .split(' ')
                .map(name =>
                    name.charAt(0)
                )
                .join('')
                .substring(0, 2)
                .toUpperCase()

        },

        pageTitle() {

            const titles = {

                dashboard:
                    'Dashboard',

                users:
                    'Users',

                projects:
                    'Projects',

                'project-create':
                    'Create Project',

                'project-edit':
                    'Edit Project',

                blocks:
                    'Project Blocks',

                properties:
                    'Property Inventory',

                'property-create':
                    'Create Property',

                'property-details':
                    'Property Details',

                'property-edit':
                    'Edit Property',

                customers:
                    'Customers',

                leads:
                    'Leads',

                'site-visits':
                    'Site Visits',

                bookings:
                    'Bookings',

                payments:
                    'Payments',

                expenses:
                    'Expenses',

                construction:
                    'Construction',

                reports:
                    'Reports',

                settings:
                    'Settings'

            }

            return titles[
                this.$route.name
            ] || 'Zeerak ERP'

        }

    },

    mounted() {

        this.$vuetify.theme.dark =
            this.darkMode

    },

    methods: {

        toggleDarkMode() {

            this.darkMode =
                !this.darkMode

            this.$vuetify.theme.dark =
                this.darkMode

            localStorage.setItem(
                'zeerak_dark_mode',
                this.darkMode
                    ? '1'
                    : '0'
            )

        },

        async logout() {

            await this.$store.dispatch(
                'auth/logout'
            )

            this.$router.push({
                name: 'login'
            })

        }

    }

}

</script>