import routes from './routes';
import { createWebHashHistory, createRouter } from 'vue-router';
import BuyMeCoffee from './plugin_main_js_file.js';
import AppLayout from './Components/UI/AppLayout.vue';

if (window.__BUYMECOFFEE_ADMIN_BOOTSTRAPPED__) {
    // Prevent duplicate router/app bootstrap when the same bundle is enqueued twice.
    console.warn('Buy Me Coffee admin app already bootstrapped, skipping duplicate init.');
} else {
    window.__BUYMECOFFEE_ADMIN_BOOTSTRAPPED__ = true;

// Adjust the Dashboard route: child of '/' parent needs path '' not '/'
const childRoutes = routes.map(r => {
    if (r.path === '/') {
        return { ...r, path: '' };
    }
    return r;
});

const router = createRouter({
    history: createWebHashHistory(),
    routes: [
        {
            path: '/',
            component: AppLayout,
            children: childRoutes,
        },
    ],
});

const framework = new BuyMeCoffee();

framework.app.config.globalProperties.appVars = window.BuyMeCoffeeAdmin;

const hasRouteProperty = Object.prototype.hasOwnProperty.call(framework.app.config.globalProperties, '$route');
if (!hasRouteProperty) {
    framework.app.use(router);
}

window.BuyMeCoffeeApp = framework.app.mount('#buy-me-coffee_app');
}
