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
            error.response.status === 401
        ) {
            localStorage.removeItem(
                'zeerak_token'
            );

            localStorage.removeItem(
                'zeerak_user'
            );

            window.location.href = '/login';
        }

        return Promise.reject(error);
    }
);

export default api;