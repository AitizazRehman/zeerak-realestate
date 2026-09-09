<template>
    <v-container class="fill-height" fluid>
        <v-row align="center" justify="center">
            <v-col cols="12" md="6" class="text-center">
                <v-icon size="100" color="warning">
                    mdi-lock-alert
                </v-icon>

                <h1 class="text-h2 mt-4">
                    403
                </h1>

                <p class="grey--text mt-3">
                    You do not have permission to access this page.
                </p>

                <v-btn color="primary" to="/admin/dashboard" class="mt-4">
                    Go to Dashboard
                </v-btn>
            </v-col>
        </v-row>
    </v-container>
</template>
