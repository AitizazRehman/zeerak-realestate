<template>
  <div class="login-page">
    <div class="background-grid"></div>
    <div class="orb orb-one"></div>
    <div class="orb orb-two"></div>

    <div class="login-wrap">
      <div class="top-brand">
        <div class="logo-box">
          <img src="/images/zeerak-logo.jpeg" alt="ZeeraK">
        </div>
        <div>
          <div class="brand-title">ZeeraK Management Portal</div>
          <div class="brand-subtitle">Secure business workspace</div>
        </div>
      </div>

      <v-card class="auth-card" elevation="0">
        <div class="auth-accent"></div>

        <div class="auth-body">
          <div class="form-intro">
            <div class="eyebrow">ACCOUNT ACCESS</div>
            <h1>Welcome back</h1>
            <p>Sign in with your authorized account to continue.</p>
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
            <div class="field-group">
              <label>Email address</label>
              <v-text-field
                v-model.trim="form.email"
                outlined
                hide-details="auto"
                placeholder="name@example.com"
                prepend-inner-icon="mdi-email-outline"
                :error-messages="errors.email"
                autocomplete="username"
                class="login-input"
              />
            </div>

            <div class="field-group">
              <div class="field-heading">
                <label>Password</label>
                <span class="security-hint">
                  <v-icon x-small color="#7a8780">mdi-shield-lock-outline</v-icon>
                  Encrypted access
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
                class="login-input"
              />
            </div>

            <div class="form-options">
              <v-checkbox
                v-model="rememberEmail"
                dense
                hide-details
                color="#165134"
                label="Remember email"
              />
              <span class="session-note">
                <v-icon x-small color="#7a8780">mdi-clock-outline</v-icon>
                Session protected
              </span>
            </div>

            <v-btn
              type="submit"
              block
              x-large
              depressed
              color="#165134"
              dark
              class="login-button"
              :disabled="!canSubmit"
            >
              Sign in
              <v-icon right>mdi-arrow-right</v-icon>
            </v-btn>
          </v-form>

          <div class="access-separator">
            <span>Authorized access only</span>
          </div>

          <div class="security-row">
            <div class="security-item">
              <v-icon small color="#165134">mdi-account-key-outline</v-icon>
              <span>Role based</span>
            </div>
            <div class="security-item">
              <v-icon small color="#165134">mdi-office-building-marker-outline</v-icon>
              <span>Branch controlled</span>
            </div>
            <div class="security-item">
              <v-icon small color="#165134">mdi-shield-check-outline</v-icon>
              <span>Secure</span>
            </div>
          </div>
        </div>
      </v-card>

      <div class="page-footer">
        <span>Management Portal</span>
        <span class="footer-dot"></span>
        <span>{{currentYear}}</span>
        <span class="footer-dot"></span>
        <span>Protected access</span>
      </div>
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
.login-page{
  min-height:100vh;
  position:relative;
  overflow:hidden;
  display:flex;
  align-items:center;
  justify-content:center;
  padding:42px 20px;
  background:
    radial-gradient(circle at 15% 15%,rgba(22,81,52,.09),transparent 28%),
    radial-gradient(circle at 85% 85%,rgba(183,147,67,.08),transparent 30%),
    #f5f8f6;
}

.background-grid{
  position:absolute;
  inset:0;
  background-image:
    linear-gradient(rgba(22,81,52,.028) 1px,transparent 1px),
    linear-gradient(90deg,rgba(22,81,52,.028) 1px,transparent 1px);
  background-size:34px 34px;
  mask-image:linear-gradient(to bottom,rgba(0,0,0,.45),transparent 85%);
}

.orb{
  position:absolute;
  border-radius:50%;
  filter:blur(1px);
}

.orb-one{
  width:260px;
  height:260px;
  left:-100px;
  bottom:80px;
  background:rgba(22,81,52,.055);
}

.orb-two{
  width:190px;
  height:190px;
  right:-55px;
  top:65px;
  background:rgba(190,151,61,.06);
}

.login-wrap{
  position:relative;
  z-index:2;
  width:100%;
  max-width:500px;
}

.top-brand{
  display:flex;
  align-items:center;
  justify-content:center;
  gap:13px;
  margin-bottom:24px;
}

.logo-box{
  width:56px;
  height:56px;
  padding:5px;
  border-radius:15px;
  background:#fff;
  border:1px solid rgba(22,81,52,.08);
  box-shadow:0 9px 24px rgba(18,60,39,.08);
}

.logo-box img{
  width:100%;
  height:100%;
  object-fit:contain;
  border-radius:10px;
}

.brand-title{
  color:#183126;
  font-size:15px;
  line-height:1.25;
  font-weight:800;
}

.brand-subtitle{
  margin-top:2px;
  color:#8a948f;
  font-size:11px;
}

.auth-card{
  position:relative;
  overflow:hidden;
  border-radius:22px!important;
  border:1px solid rgba(22,81,52,.09)!important;
  background:#fff!important;
  box-shadow:0 22px 60px rgba(24,58,41,.10)!important;
}

.auth-accent{
  height:5px;
  background:linear-gradient(90deg,#165134 0%,#2d7651 62%,#b6913f 100%);
}

.auth-body{
  padding:40px 42px 34px;
}

.form-intro{
  margin-bottom:30px;
}

.eyebrow{
  margin-bottom:8px;
  color:#165134;
  font-size:10px;
  font-weight:800;
  letter-spacing:.16em;
}

.form-intro h1{
  margin:0 0 8px;
  color:#18231e;
  font-size:31px;
  line-height:1.2;
  font-weight:800;
  letter-spacing:-.025em;
}

.form-intro p{
  margin:0;
  color:#7d8882;
  font-size:14px;
  line-height:1.6;
}

.field-group{
  margin-bottom:20px;
}

.field-group label{
  display:block;
  margin-bottom:8px;
  color:#334139;
  font-size:13px;
  font-weight:700;
}

.field-heading{
  display:flex;
  align-items:center;
  justify-content:space-between;
}

.security-hint,
.session-note{
  display:flex;
  align-items:center;
  gap:4px;
  color:#8b9590;
  font-size:11px;
}

.login-input ::v-deep .v-input__slot{
  min-height:54px!important;
  border-radius:12px!important;
  background:#fbfcfb;
}

.login-input ::v-deep fieldset{
  border-color:#dbe3df!important;
}

.login-input.v-input--is-focused ::v-deep fieldset{
  border-width:1px!important;
  border-color:#165134!important;
}

.form-options{
  min-height:48px;
  display:flex;
  align-items:center;
  justify-content:space-between;
  margin-top:-4px;
  margin-bottom:17px;
}

.form-options ::v-deep .v-label{
  color:#66726b!important;
  font-size:13px;
}

.login-button{
  min-height:54px!important;
  border-radius:12px!important;
  text-transform:none!important;
  font-size:15px!important;
  font-weight:700!important;
  letter-spacing:0!important;
  box-shadow:0 11px 26px rgba(22,81,52,.18)!important;
}

.login-button.v-btn--disabled{
  background:#dce3df!important;
  color:#87918c!important;
  box-shadow:none!important;
}

.login-alert{
  border-radius:11px!important;
}

.access-separator{
  position:relative;
  margin:28px 0 20px;
  text-align:center;
}

.access-separator:before{
  content:'';
  position:absolute;
  top:50%;
  left:0;
  right:0;
  height:1px;
  background:#edf0ee;
}

.access-separator span{
  position:relative;
  z-index:2;
  padding:0 12px;
  background:#fff;
  color:#a0a9a4;
  font-size:10px;
  text-transform:uppercase;
  letter-spacing:.08em;
}

.security-row{
  display:flex;
  align-items:center;
  justify-content:center;
  gap:18px;
  flex-wrap:wrap;
}

.security-item{
  display:flex;
  align-items:center;
  gap:6px;
  color:#657169;
  font-size:11px;
}

.page-footer{
  display:flex;
  align-items:center;
  justify-content:center;
  flex-wrap:wrap;
  margin-top:22px;
  color:#9aa49f;
  font-size:10px;
}

.footer-dot{
  width:3px;
  height:3px;
  margin:0 8px;
  border-radius:50%;
  background:#b1b8b4;
}

@media(max-width:600px){
  .login-page{
    align-items:flex-start;
    padding:30px 15px 24px;
  }

  .top-brand{
    justify-content:flex-start;
    padding:0 5px;
    margin-bottom:20px;
  }

  .auth-card{
    border-radius:18px!important;
  }

  .auth-body{
    padding:30px 24px 27px;
  }

  .form-intro h1{
    font-size:27px;
  }

  .form-options{
    align-items:flex-start;
    flex-direction:column;
    gap:8px;
    margin-bottom:20px;
  }

  .security-row{
    gap:12px;
  }
}
</style>
