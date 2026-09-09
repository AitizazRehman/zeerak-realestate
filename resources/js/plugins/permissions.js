import Vue from 'vue';

const getPermissions = () => {
    const user = JSON.parse(localStorage.getItem('zeerak_user')) || {};
    return Array.isArray(user.permissions) ? user.permissions : [];
};

const hasPermission = permission => {
    if (!permission) return true;

    const permissions = getPermissions();

    return permissions.indexOf('*') !== -1 ||
        permissions.indexOf(permission) !== -1;
};

Vue.prototype.$can = hasPermission;

Vue.directive('can', {
    inserted(el, binding) {
        if (!hasPermission(binding.value)) {
            el.parentNode && el.parentNode.removeChild(el);
        }
    },
    update(el, binding) {
        if (!hasPermission(binding.value)) {
            el.parentNode && el.parentNode.removeChild(el);
        }
    }
});

export default hasPermission;
