import axios from 'axios';

const api = axios.create({
    baseURL: '/api',

    headers: {
        'Accept': 'application/json',
        'Content-Type': 'application/json'
    }
});

api.interceptors.request.use(
    config => {

        const token = localStorage.getItem(
            'zeerak_token'
        );

        if (token) {
            config.headers.Authorization =
                `Bearer ${token}`;
        }

        return config;
    },

    error => Promise.reject(error)
);

api.interceptors.response.use(
    response => response,

    error => {

        if (
            error.response &&
            (error.response.status === 401 ||
             (error.response.status === 403 &&
              error.response.data &&
              /deactivat|inactive/i.test(error.response.data.message || '')))
        ) {
            localStorage.removeItem(
                'zeerak_token'
            );

            localStorage.removeItem(
                'zeerak_user'
            );

            if (window.location.pathname !== '/login') {
                window.location.replace('/login');
            }
        }

        return Promise.reject(error);
    }
);

export default api;