import Vue from 'vue';
import Vuetify from 'vuetify';

import 'vuetify/dist/vuetify.min.css';
import '@mdi/font/css/materialdesignicons.css';

Vue.use(Vuetify);

export default new Vuetify({
    theme: {
        dark: false,

        themes: {
            light: {
                primary: '#165134',
                secondary: '#2E7D32',
                accent: '#43A047',
                error: '#D32F2F',
                warning: '#F9A825',
                info: '#1976D2',
                success: '#388E3C'
            },

            dark: {
                primary: '#43A047',
                secondary: '#66BB6A',
                accent: '#81C784',
                error: '#EF5350',
                warning: '#FFCA28',
                info: '#42A5F5',
                success: '#66BB6A'
            }
        }
    },

    icons: {
        iconfont: 'mdi'
    }
});