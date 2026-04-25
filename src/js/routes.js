import Dashboard from './Components/Dashboard.vue';
import Settings from './Components/Settings.vue';
import PayPal from './Components/PayPal.vue';
import Stripe from './Components/Stripe.vue';
import Gateway from './Components/Gateway.vue';
import Supporter from "./Components/Supporter.vue";
import Onboarding from './Components/Onboarding.vue';
import Supporters from './Components/Supporters.vue';
import Notifications from './Components/Notifications.vue'
import Emails from "./Components/Email/Emails.vue";
import Webhook from "./Components/Webhook.vue";
import Subscriptions from './Components/Subscriptions/Subscriptions.vue';
import SubscriptionDetail from './Components/Subscriptions/SubscriptionDetail.vue';

export default [
    {
        path: '/',
        name: 'Dashboard',
        component: Dashboard,
        meta: {
            active: 'dashboard',
            breadcrumb: 'Dashboard'
        }
    },
    {
        path: '/supporters',
        name: 'Supporters',
        component: Supporters,
        meta: {
            active: 'supporters',
            breadcrumb: 'Supporters'
        }
    },
    {
        path: '/settings',
        name: 'Settings',
        component: Settings,
        meta: {
            breadcrumb: 'Settings'
        }
    },
    {
        path: '/supporter/:id',
        name: 'Supporter',
        component: Supporter,
        meta: {
            breadcrumb: 'Supporter Detail'
        }
    },
    {
        path: '/notifications',
        name: 'Notifications',
        component: Notifications,
        meta: {
            breadcrumb: 'Notifications'
        },
        exact: true,
        children: [
            {
                path: '/email',
                name: 'Emails',
                component: Emails,
                meta: {
                    breadcrumb: 'Emails'
                },
                exact: true
            },
            {
                path: '/webhook',
                name: 'Webhook',
                component: Webhook,
                meta: {
                    breadcrumb: 'Webhook'
                },
                exact: true
            }
        ]
    },
    {
        path: '/gateway',
        name: 'Gateway',
        component: Gateway,
        meta: {
            breadcrumb: 'Payment Gateways'
        },
    },
    {
        path: '/stripe',
        name: 'stripe',
        component: Stripe,
        meta: {
            breadcrumb: 'Stripe Settings'
        },
    },
    {
        path: '/paypal',
        name: 'paypal',
        component: PayPal,
        meta: {
            breadcrumb: 'PayPal Settings'
        },
    },
    {
        path: '/quick-setup',
        name: 'Onboarding',
        component: Onboarding,
        meta: {
            breadcrumb: 'Quick Setup'
        },
        exact: true
    },
    {
        path: '/subscriptions',
        name: 'Subscriptions',
        component: Subscriptions,
        meta: {
            active: 'subscriptions',
            breadcrumb: 'Subscriptions'
        }
    },
    {
        path: '/subscriptions/:id',
        name: 'SubscriptionDetail',
        component: SubscriptionDetail,
        meta: {
            breadcrumb: 'Subscription Detail'
        }
    }
];
