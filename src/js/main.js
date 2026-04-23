import routes from './routes';
import { createWebHashHistory, createRouter } from 'vue-router';
import BuyMeCoffee from './plugin_main_js_file.js';
import AppLayout from './Components/UI/AppLayout.vue';

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

window.BuyMeCoffeeApp = framework.app.use(router).mount('#buy-me-coffee_app');
