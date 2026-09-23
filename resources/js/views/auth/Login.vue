<template>
  <div class="login-shell">
    <div class="login-grid">
      <section class="visual-panel d-none d-md-flex">
        <div class="visual-overlay"></div>
        <div class="shape shape-one"></div>
        <div class="shape shape-two"></div>
        <div class="shape shape-three"></div>

        <div class="visual-content">
          <div class="brand-mark">
            <img src="/images/zeerak-logo.jpeg" alt="ZeeraK">
          </div>

          <div class="eyebrow">REAL ESTATE MANAGEMENT</div>
          <h1 class="visual-title">
            A smarter workspace for property, sales and finance.
          </h1>
          <p class="visual-copy">
            Manage leads, inventory, bookings, installments, payments and reporting from one secure system.
          </p>

          <div class="feature-grid">
            <div class="feature-card">
              <v-icon color="white" size="22">mdi-account-multiple-outline</v-icon>
              <div>
                <div class="feature-title">CRM</div>
                <div class="feature-copy">Lead to customer workflow</div>
              </div>
            </div>

            <div class="feature-card">
              <v-icon color="white" size="22">mdi-home-city-outline</v-icon>
              <div>
                <div class="feature-title">Inventory</div>
                <div class="feature-copy">Live property availability</div>
              </div>
            </div>

            <div class="feature-card">
              <v-icon color="white" size="22">mdi-chart-line</v-icon>
              <div>
                <div class="feature-title">Finance</div>
                <div class="feature-copy">Payments and receivables</div>
              </div>
            </div>

            <div class="feature-card">
              <v-icon color="white" size="22">mdi-shield-check-outline</v-icon>
              <div>
                <div class="feature-title">Secure</div>
                <div class="feature-copy">Role and branch controlled</div>
              </div>
            </div>
          </div>
        </div>

        <div class="visual-footer">
          <v-icon small color="white" class="mr-2">mdi-lock-outline</v-icon>
          Secure access for authorized users
        </div>
      </section>

      <section class="form-panel">
        <div class="form-wrap">
          <div class="mobile-brand d-md-none">
            <img src="/images/zeerak-logo.jpeg" alt="ZeeraK">
          </div>

          <div class="form-heading">
            <div class="welcome-label">WELCOME BACK</div>
            <h2>Sign in to your account</h2>
            <p>Enter your credentials to continue to the management portal.</p>
          </div>

          <v-alert
            v-if="message"
            type="error"
            text
            dense
            dismissible
            class="login-alert mb-5"
            @input="message=''"
          >
            {{message}}
          </v-alert>

          <v-form ref="form" @submit.prevent="login">
            <div class="field-label">Email address</div>
            <v-text-field
              v-model.trim="form.email"
              outlined
              hide-details="auto"
              placeholder="you@example.com"
              prepend-inner-icon="mdi-email-outline"
              :error-messages="errors.email"
              autocomplete="username"
              class="login-field mb-5"
            />

            <div class="d-flex align-center justify-space-between">
              <div class="field-label">Password</div>
              <span class="secure-label">
                <v-icon x-small color="#77827c">mdi-lock-outline</v-icon>
                Secure sign in
              </span>
            </div>

            <v-text-field
              v-model="form.password"
              outlined
              hide-details="auto"
              placeholder="Enter your password"
              prepend-inner-icon="mdi-lock-outline"
              :append-icon="showPassword ? 'mdi-eye-off-outline' : 'mdi-eye-outline'"
              @click:append="showPassword=!showPassword"
              :type="showPassword ? 'text' : 'password'"
              :error-messages="errors.password"
              autocomplete="current-password"
              class="login-field mb-2"
            />

            <div class="remember-row">
              <v-checkbox
                v-model="rememberEmail"
                dense
                hide-details
                color="#165134"
                label="Remember my email"
              />
            </div>

            <v-btn
              type="submit"
              block
              x-large
              depressed
              color="#165134"
              dark
              class="signin-btn"
              :disabled="!canSubmit"
            >
              <span>Sign in</span>
              <v-icon right>mdi-arrow-right</v-icon>
            </v-btn>
          </v-form>

          <div class="support-note">
            <v-icon small color="#165134" class="mr-2">mdi-information-outline</v-icon>
            If you cannot access your account, contact your system administrator.
          </div>

          <div class="form-footer">
            <span>Protected management portal</span>
            <span class="dot"></span>
            <span>{{currentYear}}</span>
          </div>
        </div>
      </section>
    </div>
  </div>
</template>

<script>
export default {
  name:'Login',

  data(){
    return{
      showPassword:false,
      rememberEmail:localStorage.getItem('zeerak_remember_email')==='1',
      message:'',
      form:{
        email:localStorage.getItem('zeerak_login_email')||'',
        password:''
      },
      errors:{}
    }
  },

  computed:{
    canSubmit(){
      return !!(this.form.email && this.form.password)
    },

    currentYear(){
      return new Date().getFullYear()
    }
  },

  methods:{
    async login(){
      if(!this.canSubmit)return

      this.errors={}
      this.message=''

      try{
        await this.$store.dispatch('auth/login',this.form)

        if(this.rememberEmail){
          localStorage.setItem('zeerak_remember_email','1')
          localStorage.setItem('zeerak_login_email',this.form.email)
        }else{
          localStorage.removeItem('zeerak_remember_email')
          localStorage.removeItem('zeerak_login_email')
        }

        this.$router.push({name:'dashboard'})
      }catch(error){
        if(error.response&&error.response.status===422){
          this.errors=error.response.data.errors||{}
          this.message=error.response.data.message||'Please check your login details.'
        }else if(error.response&&error.response.data&&error.response.data.message){
          this.message=error.response.data.message
        }else{
          this.message='Unable to sign in. Please check your connection and try again.'
        }
      }
    }
  }
}
</script>

<style scoped>
.login-shell{
  min-height:100vh;
  background:#f4f7f5;
}

.login-grid{
  min-height:100vh;
  display:grid;
  grid-template-columns:minmax(0,1.15fr) minmax(430px,.85fr);
}

.visual-panel{
  min-height:100vh;
  position:relative;
  overflow:hidden;
  padding:64px 72px;
  flex-direction:column;
  justify-content:center;
  background:
    radial-gradient(circle at 85% 15%,rgba(196,159,73,.22),transparent 26%),
    linear-gradient(145deg,#0b3422 0%,#124a30 48%,#165134 100%);
}

.visual-overlay{
  position:absolute;
  inset:0;
  background-image:
    linear-gradient(rgba(255,255,255,.025) 1px,transparent 1px),
    linear-gradient(90deg,rgba(255,255,255,.025) 1px,transparent 1px);
  background-size:42px 42px;
  mask-image:linear-gradient(to bottom,rgba(0,0,0,.8),transparent);
}

.shape{
  position:absolute;
  border:1px solid rgba(255,255,255,.10);
  border-radius:32px;
  transform:rotate(16deg);
}

.shape-one{
  width:310px;
  height:310px;
  right:-100px;
  top:70px;
}

.shape-two{
  width:220px;
  height:220px;
  right:70px;
  bottom:70px;
  border-color:rgba(207,173,91,.20);
}

.shape-three{
  width:120px;
  height:120px;
  left:70px;
  bottom:-45px;
}

.visual-content{
  position:relative;
  z-index:2;
  max-width:720px;
}

.brand-mark{
  width:78px;
  height:78px;
  padding:7px;
  border-radius:18px;
  background:#fff;
  box-shadow:0 16px 40px rgba(0,0,0,.18);
  margin-bottom:38px;
}

.brand-mark img{
  width:100%;
  height:100%;
  object-fit:contain;
  border-radius:12px;
}

.eyebrow{
  color:#d9bd75;
  font-size:12px;
  font-weight:800;
  letter-spacing:.17em;
  margin-bottom:15px;
}

.visual-title{
  color:#fff;
  font-size:clamp(36px,4vw,58px);
  line-height:1.08;
  font-weight:800;
  letter-spacing:-.035em;
  max-width:700px;
  margin:0 0 22px;
}

.visual-copy{
  max-width:610px;
  margin:0;
  color:rgba(255,255,255,.72);
  font-size:17px;
  line-height:1.75;
}

.feature-grid{
  display:grid;
  grid-template-columns:repeat(2,minmax(0,1fr));
  gap:12px;
  max-width:620px;
  margin-top:42px;
}

.feature-card{
  display:flex;
  align-items:center;
  gap:13px;
  padding:16px;
  border:1px solid rgba(255,255,255,.09);
  background:rgba(255,255,255,.055);
  border-radius:14px;
  backdrop-filter:blur(8px);
}

.feature-title{
  color:#fff;
  font-weight:700;
  line-height:1.3;
}

.feature-copy{
  margin-top:2px;
  color:rgba(255,255,255,.58);
  font-size:12px;
}

.visual-footer{
  position:absolute;
  left:72px;
  bottom:35px;
  z-index:2;
  color:rgba(255,255,255,.55);
  font-size:12px;
  display:flex;
  align-items:center;
}

.form-panel{
  min-height:100vh;
  display:flex;
  align-items:center;
  justify-content:center;
  background:#fff;
  padding:48px;
}

.form-wrap{
  width:100%;
  max-width:440px;
}

.mobile-brand{
  width:72px;
  height:72px;
  margin-bottom:30px;
}

.mobile-brand img{
  width:100%;
  height:100%;
  object-fit:contain;
}

.form-heading{
  margin-bottom:32px;
}

.welcome-label{
  color:#165134;
  font-size:11px;
  font-weight:800;
  letter-spacing:.16em;
  margin-bottom:9px;
}

.form-heading h2{
  margin:0 0 8px;
  color:#18231e;
  font-size:31px;
  line-height:1.2;
  font-weight:800;
  letter-spacing:-.025em;
}

.form-heading p{
  margin:0;
  color:#7b8580;
  font-size:14px;
  line-height:1.65;
}

.field-label{
  margin-bottom:7px;
  color:#36423c;
  font-size:13px;
  font-weight:700;
}

.secure-label{
  display:flex;
  align-items:center;
  gap:4px;
  margin-bottom:7px;
  color:#8a938e;
  font-size:11px;
}

.login-field ::v-deep .v-input__slot{
  min-height:52px!important;
  border-radius:12px!important;
  background:#fbfcfb;
}

.login-field ::v-deep fieldset{
  border-color:#dce3df!important;
}

.login-field.v-input--is-focused ::v-deep fieldset{
  border-width:1px!important;
  border-color:#165134!important;
}

.remember-row{
  display:flex;
  align-items:center;
  min-height:54px;
  margin-bottom:18px;
}

.remember-row ::v-deep .v-label{
  font-size:13px;
  color:#66716b;
}

.signin-btn{
  min-height:54px!important;
  border-radius:12px!important;
  text-transform:none!important;
  font-size:15px!important;
  font-weight:700!important;
  letter-spacing:0!important;
  box-shadow:0 10px 24px rgba(22,81,52,.18)!important;
}

.signin-btn.v-btn--disabled{
  background:#dce2df!important;
  color:#87918c!important;
  box-shadow:none!important;
}

.login-alert{
  border-radius:11px!important;
}

.support-note{
  display:flex;
  align-items:flex-start;
  margin-top:28px;
  padding:13px 14px;
  border-radius:11px;
  background:#f6f8f7;
  color:#738079;
  font-size:12px;
  line-height:1.5;
}

.form-footer{
  display:flex;
  align-items:center;
  justify-content:center;
  margin-top:34px;
  color:#a0a8a4;
  font-size:11px;
}

.dot{
  width:3px;
  height:3px;
  margin:0 8px;
  border-radius:50%;
  background:#aeb6b2;
}

@media(max-width:1260px){
  .visual-panel{
    padding:56px 48px;
  }

  .visual-footer{
    left:48px;
  }
}

@media(max-width:959px){
  .login-grid{
    display:block;
  }

  .form-panel{
    min-height:100vh;
    padding:40px 28px;
    background:
      radial-gradient(circle at top right,rgba(22,81,52,.05),transparent 28%),
      #fff;
  }
}

@media(max-width:600px){
  .form-panel{
    align-items:flex-start;
    padding:38px 22px 28px;
  }

  .form-wrap{
    max-width:100%;
  }

  .form-heading h2{
    font-size:27px;
  }

  .support-note{
    margin-top:24px;
  }
}
</style>
