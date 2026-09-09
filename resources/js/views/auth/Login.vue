<template>
    <v-container
        fluid
        class="fill-height login-background"
    >
        <v-row
            align="center"
            justify="center"
        >
            <v-col
                cols="12"
                sm="8"
                md="5"
                lg="4"
            >

                <v-card
                    elevation="10"
                    class="pa-8 rounded-xl"
                >

                    <div class="text-center mb-8">

                        <v-icon
                            size="60"
                            color="primary"
                        >
                            mdi-home-city
                        </v-icon>

                        <h2 class="mt-3">
                            Zeerak
                        </h2>

                        <div class="grey--text">
                            Real Estate & Builders
                        </div>

                    </div>

                    <v-form
                        ref="form"
                        @submit.prevent="login"
                    >

                        <v-text-field
                            v-model="form.email"
                            label="Email"
                            prepend-inner-icon="mdi-email"
                            outlined
                            :error-messages="errors.email"
                        />

                        <v-text-field
                            v-model="form.password"
                            label="Password"
                            prepend-inner-icon="mdi-lock"
                            type="password"
                            outlined
                            :error-messages="errors.password"
                        />

                        <v-btn
                            type="submit"
                            block
                            x-large
                            color="primary"
                            :loading="loading"
                        >
                            Login
                        </v-btn>

                    </v-form>

                </v-card>

            </v-col>
        </v-row>
    </v-container>
</template>

<script>
export default {

    name: 'Login',

    data() {

        return {

            loading: false,

            form: {
                email: '',
                password: ''
            },

            errors: {}

        };

    },

    methods: {

        async login() {

            this.loading = true;
            this.errors = {};

            try {

                await this.$store.dispatch(
                    'auth/login',
                    this.form
                );

                this.$router.push({
                    name: 'dashboard'
                });

            } catch (error) {

                if (
                    error.response &&
                    error.response.status === 422
                ) {
                    this.errors =
                        error.response.data.errors || {};
                }

            } finally {

                this.loading = false;

            }

        }

    }

};
</script>

<style scoped>

.login-background {
    min-height: 100vh;
    background:
        linear-gradient(
            135deg,
            #165134,
            #0d3320
        );
}

</style>