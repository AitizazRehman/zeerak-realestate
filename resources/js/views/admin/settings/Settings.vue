<template>
  <div class="page">
    <v-card flat class="hero pa-5 mb-4"><div><div class="text-overline">SYSTEM</div><h1 class="text-h5 font-weight-bold">Settings</h1><div class="grey--text">Application preferences for the Zeerak ERP workspace.</div></div></v-card>
    <v-card outlined><v-card-title>Interface Preferences</v-card-title><v-card-text><v-switch v-model="dark" label="Dark mode" color="primary" @change="save"/><v-switch v-model="compact" label="Compact navigation" color="primary" @change="save"/><v-alert type="info" dense text>System and account settings can be extended here as backend configuration is introduced.</v-alert></v-card-text></v-card>
  </div>
</template>
<script>
export default {name:'Settings',data(){return{dark:localStorage.getItem('zeerak_dark_mode')==='1',compact:localStorage.getItem('zeerak_compact_nav')==='1'}},methods:{save(){localStorage.setItem('zeerak_dark_mode',this.dark?'1':'0');localStorage.setItem('zeerak_compact_nav',this.compact?'1':'0');this.$vuetify.theme.dark=this.dark}}}
</script>
<style scoped>.page{width:100%}.hero{border-left:4px solid #165134}</style>
