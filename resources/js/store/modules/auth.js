import api from '../../services/api';

const state = {
    user: JSON.parse(localStorage.getItem('zeerak_user')) || null,
    token: localStorage.getItem('zeerak_token') || null
};

const getters = {
    isAuthenticated: state => !!state.token,
    user: state => state.user,
    roles: state => (state.user && state.user.roles) || [],
    permissions: state => (state.user && state.user.permissions) || [],
    hasPermission: state => permission => {
        if (!permission) return true;
        const permissions = (state.user && state.user.permissions) || [];
        return permissions.indexOf('*') !== -1 || permissions.indexOf(permission) !== -1;
    }
};

const mutations = {
    SET_AUTH(state, payload) {
        state.token = payload.token;
        state.user = payload.user;
        localStorage.setItem('zeerak_token', payload.token);
        localStorage.setItem('zeerak_user', JSON.stringify(payload.user));
    },
    CLEAR_AUTH(state) {
        state.token = null;
        state.user = null;
        localStorage.removeItem('zeerak_token');
        localStorage.removeItem('zeerak_user');
    }
};

const actions = {
    async login({ commit }, credentials) {
        const response = await api.post('/auth/login', credentials);
        commit('SET_AUTH', response.data.data);
        return response;
    },
    async logout({ commit }) {
        try {
            await api.post('/auth/logout');
        } finally {
            commit('CLEAR_AUTH');
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
