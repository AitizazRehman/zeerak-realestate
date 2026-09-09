<template>

    <v-container
        class="fill-height"
        fluid
    >

        <v-row
            align="center"
            justify="center"
        >

            <v-col
                cols="12"
                md="6"
                class="text-center"
            >

                <v-icon
                    size="100"
                    color="primary"
                >
                    mdi-home-alert
                </v-icon>

                <h1 class="text-h2">
                    404
                </h1>

                <p class="grey--text">
                    Page not found.
                </p>

                <v-btn
                    color="primary"
                    to="/admin/dashboard"
                >
                    Go to Dashboard
                </v-btn>

            </v-col>

        </v-row>

    </v-container>

</template>