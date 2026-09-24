import api from '../../services/api';

const defaults = {
    company_name: 'ZeeraK Real Estate & Builders',
    legal_name: '',
    logo_url: '/images/zeerak-logo.jpeg',
    currency: 'PKR'
};

const readStoredSettings = () => {
    try {
        const saved = JSON.parse(localStorage.getItem('zeerak_app_settings') || '{}');
        return Object.assign({}, defaults, saved || {});
    } catch (e) {
        return Object.assign({}, defaults);
    }
};

const state = {
    company: readStoredSettings()
};

const getters = {
    company: state => state.company,
    companyName: state => state.company.company_name || defaults.company_name,
    legalName: state => state.company.legal_name || '',
    logoUrl: state => state.company.logo_url || defaults.logo_url,
    currency: state => state.company.currency || 'PKR'
};

const mutations = {
    SET_COMPANY(state, payload) {
        state.company = Object.assign({}, state.company || {}, payload || {});
        localStorage.setItem('zeerak_app_settings', JSON.stringify(state.company));
    }
};

const actions = {
    async loadCompany({ commit }) {
        try {
            const response = await api.get('/app-settings', {
                skipGlobalLoader: true,
                skipGlobalError: true,
                skipGlobalMessage: true
            });
            commit('SET_COMPANY', response.data || {});
            return response.data;
        } catch (e) {
            return null;
        }
    }
};

export default {
    namespaced: true,
    state,
    getters,
    mutations,
    actions
};
