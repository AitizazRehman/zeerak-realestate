import Vue from 'vue';
import Vuetify from 'vuetify';

import 'vuetify/dist/vuetify.min.css';
import '@mdi/font/css/materialdesignicons.css';

Vue.use(Vuetify);

const prefersDark = localStorage.getItem('zeerak_dark_mode') === '1';

export default new Vuetify({
    theme: {
        dark: prefersDark,

        themes: {
            light: {
                primary: '#165134',
                secondary: '#2E7D32',
                accent: '#43A047',
                error: '#D32F2F',
                warning: '#F9A825',
                info: '#1976D2',
                success: '#388E3C',
                background: '#F4F7F5',
                surface: '#FFFFFF',
                gold: '#B6913F'
            },

            dark: {
                primary: '#5BB77D',
                secondary: '#73C692',
                accent: '#C9A85B',
                error: '#FF6B6B',
                warning: '#F6C85F',
                info: '#67A9FF',
                success: '#66C98A',
                background: '#0F1713',
                surface: '#16211B',
                gold: '#C9A85B'
            }
        },

        options: {
            customProperties: true
        }
    },

    icons: {
        iconfont: 'mdi'
    }
});
