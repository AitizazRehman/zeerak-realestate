import Vue from 'vue'
import VueRouter from 'vue-router'
import store from '../store'
import Login from '../views/auth/Login.vue'
import AdminLayout from '../layouts/AdminLayout.vue'
import Dashboard from '../views/dashboard/Dashboard.vue'
import Users from '../views/users/Users.vue'
import NotFound from '../views/errors/NotFound.vue'
import Projects from '../views/admin/real-estate/Projects.vue'
import ProjectForm from '../views/admin/real-estate/ProjectForm.vue'
import Blocks from '../views/admin/real-estate/Blocks.vue'
import Properties from '../views/admin/real-estate/Properties.vue'
import PropertyForm from '../views/admin/real-estate/PropertyForm.vue'
import PropertyDetails from '../views/admin/real-estate/PropertyDetails.vue'
import Customers from '../views/admin/sales/Customers.vue'
import Leads from '../views/admin/sales/Leads.vue'
import SiteVisits from '../views/admin/sales/SiteVisits.vue'
import Bookings from '../views/admin/sales/Bookings.vue'
import Payments from '../views/admin/sales/Payments.vue'
import Installments from '../views/admin/sales/Installments.vue'
import SalesDashboard from '../views/admin/sales/SalesDashboard.vue'

Vue.use(VueRouter)

const routes = [
 { path:'/login', name:'login', component:Login, meta:{guest:true} },
 { path:'/admin', component:AdminLayout, meta:{requiresAuth:true}, children:[
   {path:'',redirect:{name:'dashboard'}},
   {path:'dashboard',name:'dashboard',component:Dashboard},
   {path:'sales/dashboard',name:'sales-dashboard',component:SalesDashboard},
   {path:'users',name:'users',component:Users},
   {path:'projects',name:'projects',component:Projects},
   {path:'projects/create',name:'project-create',component:ProjectForm},
   {path:'projects/:id/edit',name:'project-edit',component:ProjectForm},
   {path:'blocks',name:'blocks',component:Blocks},
   {path:'properties',name:'properties',component:Properties},
   {path:'properties/create',name:'property-create',component:PropertyForm},
   {path:'properties/:id',name:'property-details',component:PropertyDetails},
   {path:'properties/:id/edit',name:'property-edit',component:PropertyForm},
   {path:'customers',name:'customers',component:Customers},
   {path:'leads',name:'leads',component:Leads},
   {path:'site-visits',name:'site-visits',component:SiteVisits},
   {path:'bookings',name:'bookings',component:Bookings},
   {path:'payments',name:'payments',component:Payments},
   {path:'installments',name:'installments',component:Installments},
   {path:'expenses',name:'expenses',component:NotFound},
   {path:'construction',name:'construction',component:NotFound},
   {path:'reports',name:'reports',component:NotFound},
   {path:'settings',name:'settings',component:NotFound}
 ]},
 {path:'*',component:NotFound}
]

const router = new VueRouter({mode:'history',routes,scrollBehavior(){return {x:0,y:0}}})
router.beforeEach((to,from,next)=>{
 const authenticated=store.getters['auth/isAuthenticated']
 if(to.matched.some(r=>r.meta.requiresAuth)&&!authenticated)return next({name:'login'})
 if(to.matched.some(r=>r.meta.guest)&&authenticated)return next({name:'dashboard'})
 next()
})
export default router
