<template>

    <div>

        <div class="d-flex align-center mb-6">

            <div>

                <h1 class="text-h4 font-weight-bold">
                    Users
                </h1>

                <div class="grey--text">
                    Manage system users
                </div>

            </div>

            <v-spacer />

            <v-btn
                color="primary"
                depressed
            >
                <v-icon left>
                    mdi-account-plus
                </v-icon>

                Add User
            </v-btn>

        </div>

        <v-card>

            <v-card-title>

                Users

                <v-spacer />

                <v-text-field
                    label="Search"
                    prepend-inner-icon="mdi-magnify"
                    outlined
                    dense
                    hide-details
                />

            </v-card-title>

            <v-data-table
                :headers="headers"
                :items="users"
            />

        </v-card>

    </div>

</template>

<script>
export default {

    name: 'Users',

    data() {

        return {

            headers: [
                {
                    text: 'Name',
                    value: 'name'
                },
                {
                    text: 'Email',
                    value: 'email'
                },
                {
                    text: 'Role',
                    value: 'role'
                },
                {
                    text: 'Status',
                    value: 'status'
                }
            ],

            users: []

        };

    }

};
</script>