<template>
<v-container fluid class="login-page pa-0">
  <v-row no-gutters class="fill-height">
    <v-col cols="12" md="7" class="brand-panel d-none d-md-flex">
      <div class="brand-content">
        <v-img src="/images/zeerak-logo.jpeg" contain max-width="190" class="white-logo mb-8"/>
        <div class="text-overline gold--text">REAL ESTATE MANAGEMENT PLATFORM</div>
        <h1 class="display-1 font-weight-bold white--text mb-4">Manage property, customers<br>and finance in one place.</h1>
        <p class="subtitle-1 white--text muted">A secure workspace for Zeerak Real Estate &amp; Builders — from lead to booking, installment, payment and reporting.</p>
        <div class="feature-row mt-10"><span><v-icon dark small>mdi-check-circle</v-icon> CRM</span><span><v-icon dark small>mdi-check-circle</v-icon> Property Inventory</span><span><v-icon dark small>mdi-check-circle</v-icon> Sales &amp; Finance</span></div>
      </div>
    </v-col>
    <v-col cols="12" md="5" class="form-panel d-flex align-center justify-center">
      <v-card flat class="login-card pa-7 pa-sm-10">
        <div class="d-md-none text-center mb-6"><v-img src="/images/zeerak-logo.jpeg" contain max-height="105" class="mx-auto"/></div>
        <div class="text-overline primary--text font-weight-bold">WELCOME BACK</div>
        <h2 class="text-h4 font-weight-bold mb-2">Sign in</h2>
        <p class="grey--text mb-7">Enter your account details to continue.</p>
        <v-alert v-if="message" type="error" text dense dismissible @input="message=''">{{message}}</v-alert>
        <v-form ref="form" @submit.prevent="login">
          <v-text-field v-model.trim="form.email" label="Email address" prepend-inner-icon="mdi-email-outline" outlined dense :error-messages="errors.email" autocomplete="username"/>
          <v-text-field v-model="form.password" label="Password" prepend-inner-icon="mdi-lock-outline" :append-icon="showPassword?'mdi-eye-off-outline':'mdi-eye-outline'" @click:append="showPassword=!showPassword" :type="showPassword?'text':'password'" outlined dense :error-messages="errors.password" autocomplete="current-password"/>
          <div class="d-flex align-center mb-5"><v-checkbox v-model="rememberEmail" dense hide-details label="Remember email"/><v-spacer/><span class="caption grey--text">Secure account access</span></div>
          <v-btn type="submit" block x-large color="#165134" dark depressed class="rounded-lg" :loading="loading" :disabled="!form.email||!form.password">Sign in <v-icon right>mdi-arrow-right</v-icon></v-btn>
        </v-form>
        <div class="text-center caption grey--text mt-7">Zeerak Real Estate &amp; Builders</div>
      </v-card>
    </v-col>
  </v-row>
</v-container>
</template>
<script>
export default{name:'Login',data(){return{loading:false,showPassword:false,rememberEmail:localStorage.getItem('zeerak_remember_email')==='1',message:'',form:{email:localStorage.getItem('zeerak_login_email')||'',password:''},errors:{}}},methods:{async login(){this.loading=true;this.errors={};this.message='';try{await this.$store.dispatch('auth/login',this.form);if(this.rememberEmail){localStorage.setItem('zeerak_remember_email','1');localStorage.setItem('zeerak_login_email',this.form.email)}else{localStorage.removeItem('zeerak_remember_email');localStorage.removeItem('zeerak_login_email')}this.$router.push({name:'dashboard'})}catch(error){if(error.response&&error.response.status===422){this.errors=error.response.data.errors||{};this.message=error.response.data.message||''}else this.message='Unable to sign in. Please check your connection and try again.'}finally{this.loading=false}}}}
</script>
<style scoped>
.login-page{min-height:100vh;background:#f6f8f7}.fill-height{min-height:100vh}.brand-panel{position:relative;align-items:center;padding:8vw;background:linear-gradient(145deg,rgba(9,54,33,.97),rgba(22,81,52,.92)),url('/images/zeerak-logo.jpeg') center/cover}.brand-content{max-width:700px}.white-logo{background:#fff;border-radius:20px;padding:10px}.gold--text{color:#d6b45d!important}.muted{opacity:.82;max-width:650px;line-height:1.8}.feature-row{display:flex;gap:28px;flex-wrap:wrap;color:#fff}.feature-row span{display:flex;gap:7px;align-items:center}.form-panel{background:#f6f8f7}.login-card{width:100%;max-width:500px;background:transparent!important}.rounded-lg{border-radius:12px!important}@media(max-width:600px){.form-panel{padding:20px}.login-card{padding:24px!important}}
</style>