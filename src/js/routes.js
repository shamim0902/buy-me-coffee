const Dashboard = () => import('./Components/Dashboard.vue');
const Settings = () => import('./Components/Settings.vue');
const PayPal = () => import('./Components/PayPal.vue');
const Stripe = () => import('./Components/Stripe.vue');
const Gateway = () => import('./Components/Gateway.vue');
const Supporter = () => import('./Components/Supporter.vue');
const Onboarding = () => import('./Components/Onboarding.vue');
const Supporters = () => import('./Components/Supporters.vue');
const Notifications = () => import('./Components/Notifications.vue');
const Emails = () => import('./Components/Email/Emails.vue');
const Webhook = () => import('./Components/Webhook.vue');
const Subscriptions = () => import('./Components/Subscriptions/Subscriptions.vue');
const SubscriptionDetail = () => import('./Components/Subscriptions/SubscriptionDetail.vue');
const ActivityLog = () => import('./Components/ActivityLog.vue');

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
    },
    {
        path: '/activity-log',
        name: 'ActivityLog',
        component: ActivityLog,
        meta: {
            active: 'activity-log',
            breadcrumb: 'Activity Log'
        }
    }
];
