<template>

    <div>

        <div class="d-flex align-center mb-6">

            <div>

                <h1 class="text-h4 font-weight-bold">
                    Dashboard
                </h1>

                <div class="grey--text">
                    Welcome to Zeerak Real Estate & Builders
                </div>

            </div>

            <v-spacer />

            <v-btn
                color="primary"
                depressed
            >
                <v-icon left>
                    mdi-plus
                </v-icon>

                Add Property
            </v-btn>

        </div>

        <v-row>

            <v-col
                v-for="stat in statistics"
                :key="stat.title"
                cols="12"
                sm="6"
                lg="3"
            >

                <v-card
                    elevation="2"
                    class="pa-5 rounded-lg"
                >

                    <div class="d-flex">

                        <v-avatar
                            size="48"
                            color="primary"
                        >
                            <v-icon
                                dark
                            >
                                {{ stat.icon }}
                            </v-icon>
                        </v-avatar>

                        <div class="ml-4">

                            <div
                                class="text-caption grey--text"
                            >
                                {{ stat.title }}
                            </div>

                            <div
                                class="text-h5 font-weight-bold"
                            >
                                {{ stat.value }}
                            </div>

                        </div>

                    </div>

                </v-card>

            </v-col>

        </v-row>

        <v-row class="mt-2">

            <v-col
                cols="12"
                lg="8"
            >

                <v-card
                    elevation="2"
                    class="rounded-lg"
                >

                    <v-card-title>
                        Sales Overview
                    </v-card-title>

                    <v-card-text>

                        <div
                            class="chart-placeholder"
                        >
                            Sales Chart
                        </div>

                    </v-card-text>

                </v-card>

            </v-col>

            <v-col
                cols="12"
                lg="4"
            >

                <v-card
                    elevation="2"
                    class="rounded-lg"
                >

                    <v-card-title>
                        Property Status
                    </v-card-title>

                    <v-card-text>

                        <v-list>

                            <v-list-item
                                v-for="item in propertyStatus"
                                :key="item.title"
                            >

                                <v-list-item-icon>
                                    <v-icon
                                        :color="item.color"
                                    >
                                        mdi-circle
                                    </v-icon>
                                </v-list-item-icon>

                                <v-list-item-title>
                                    {{ item.title }}
                                </v-list-item-title>

                                <v-list-item-action>
                                    {{ item.value }}
                                </v-list-item-action>

                            </v-list-item>

                        </v-list>

                    </v-card-text>

                </v-card>

            </v-col>

        </v-row>

    </div>

</template>

<script>
export default {

    name: 'Dashboard',

    data() {

        return {

            statistics: [

                {
                    title: 'Total Properties',
                    value: '0',
                    icon: 'mdi-home-city'
                },

                {
                    title: 'Customers',
                    value: '0',
                    icon: 'mdi-account-group'
                },

                {
                    title: 'Total Sales',
                    value: 'PKR 0',
                    icon: 'mdi-cash-multiple'
                },

                {
                    title: 'Receivables',
                    value: 'PKR 0',
                    icon: 'mdi-cash-clock'
                }

            ],

            propertyStatus: [

                {
                    title: 'Available',
                    value: 0,
                    color: 'success'
                },

                {
                    title: 'Reserved',
                    value: 0,
                    color: 'warning'
                },

                {
                    title: 'Sold',
                    value: 0,
                    color: 'error'
                }

            ]

        };

    }

};
</script>

<style scoped>

.chart-placeholder {
    height: 300px;

    display: flex;
    align-items: center;
    justify-content: center;

    border: 2px dashed #ddd;

    border-radius: 12px;

    color: #888;
}

</style>