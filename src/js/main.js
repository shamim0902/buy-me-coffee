import routes from './routes';
import { createWebHashHistory, createRouter } from 'vue-router';
import BuyMeCoffee from './plugin_main_js_file.js';
import AppLayout from './Components/UI/AppLayout.vue';

const renderBootstrapError = (message) => {
    const root = document.getElementById('buy-me-coffee_app');
    if (!root) {
        return;
    }

    root.innerHTML = `
        <div style="padding:20px;font-family:Inter,Arial,sans-serif;border:1px solid #f1d8d8;background:#fff7f7;color:#7a1f1f;border-radius:8px;">
            <strong>Buy Me Coffee admin failed to load.</strong>
            <div style="margin-top:8px;">${message}</div>
            <div style="margin-top:8px;">Please hard refresh this page and clear cache if needed.</div>
        </div>
    `;
};

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
const app = framework?.app;

if (!app?.config?.globalProperties) {
    console.error('Buy Me Coffee bootstrap error: Vue app instance is missing or incomplete.', {
        framework,
        appVars: window.BuyMeCoffeeAdmin
    });
    renderBootstrapError('The app bundle seems outdated or partially cached.');
} else {
    app.config.globalProperties.appVars = window.BuyMeCoffeeAdmin || {};

    const hasRouteProperty = Object.prototype.hasOwnProperty.call(app.config.globalProperties, '$route');
    if (!hasRouteProperty) {
        app.use(router);
    }

    window.BuyMeCoffeeApp = app.mount('#buy-me-coffee_app');
}
}
