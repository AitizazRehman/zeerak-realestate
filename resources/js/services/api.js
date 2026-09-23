import axios from 'axios';
import uiBus from './uiBus';

const api = axios.create({
    baseURL: '/api',

    headers: {
        'Accept': 'application/json',
        'Content-Type': 'application/json'
    }
});

const finishLoading = config => {
    if (!config || !config.skipGlobalLoader) {
        uiBus.$emit('api:finish');
    }
};

const responseMessage = response => {
    if (!response || !response.data || typeof response.data !== 'object') return null;
    return response.data.message || null;
};

const errorMessage = error => {
    if (!error || !error.response || !error.response.data) {
        return 'Unable to complete the request. Please check your connection and try again.';
    }

    const data = error.response.data;

    if (data.message) return data.message;

    if (data.errors && typeof data.errors === 'object') {
        const first = Object.keys(data.errors)[0];
        if (first && Array.isArray(data.errors[first]) && data.errors[first].length) {
            return data.errors[first][0];
        }
    }

    return 'Unable to complete the request.';
};

api.interceptors.request.use(
    config => {
        const token = localStorage.getItem('zeerak_token');

        if (token) {
            config.headers.Authorization = `Bearer ${token}`;
        }

        if (typeof FormData !== 'undefined' && config.data instanceof FormData) {
            delete config.headers['Content-Type'];
        }

        if (!config.skipGlobalLoader) {
            uiBus.$emit('api:start');
        }

        return config;
    },

    error => {
        uiBus.$emit('api:finish');
        return Promise.reject(error);
    }
);

api.interceptors.response.use(
    response => {
        finishLoading(response.config);

        const method = String((response.config && response.config.method) || 'get').toLowerCase();
        const message = responseMessage(response);

        if (
            message &&
            ['post', 'put', 'patch', 'delete'].indexOf(method) !== -1 &&
            !(response.config && response.config.skipGlobalMessage)
        ) {
            uiBus.$emit('message', {
                type: 'success',
                text: message
            });
        }

        return response;
    },

    error => {
        finishLoading(error.config);

        if (
            error.response &&
            (error.response.status === 401 ||
             (error.response.status === 403 &&
              error.response.data &&
              /deactivat|inactive/i.test(error.response.data.message || '')))
        ) {
            localStorage.removeItem('zeerak_token');
            localStorage.removeItem('zeerak_user');

            if (window.location.pathname !== '/login') {
                window.location.replace('/login');
            }
        } else if (!(error.config && error.config.skipGlobalError)) {
            uiBus.$emit('message', {
                type: 'error',
                text: errorMessage(error)
            });
        }

        return Promise.reject(error);
    }
);

export default api;
