import {
    LayoutDashboard,
    Users,
    Receipt,
    Settings,
    Palette,
    Code2,
    CreditCard,
    Bell,
    RefreshCw,
    ClipboardList,
    Users2,
} from 'lucide-vue-next';

export const mainItems = [
    { label: 'Dashboard', route: '/', icon: LayoutDashboard, activeNames: ['Dashboard'] },
    { label: 'Transactions', route: '/recent-transactions', icon: Receipt, activeNames: ['RecentTransactions'] },
    { label: 'Subscriptions', route: '/subscriptions', icon: RefreshCw, activeNames: ['Subscriptions', 'SubscriptionDetail'] },
    { label: 'Memberships', route: '/memberships', icon: Users2, activeNames: ['Memberships', 'LevelNew', 'LevelEdit'] },
    { label: 'Supporters', route: '/supporters', icon: Users, activeNames: ['Supporters', 'Supporter'] },
    { label: 'Log', route: '/activity-log', icon: ClipboardList, activeNames: ['ActivityLog'] },
];

export const configItems = [
    {
        label: 'Settings',
        route: '/settings',
        icon: Settings,
        activeNames: ['Settings'],
        children: [
            { label: 'General', icon: Settings, query: { tab: 'general' } },
            { label: 'Appearance', icon: Palette, query: { tab: 'appearance' } },
            { label: 'Shortcodes', icon: Code2, query: { tab: 'shortcodes' } },
        ],
    },
    { label: 'Gateways', route: '/gateway', icon: CreditCard, activeNames: ['Gateway', 'stripe', 'paypal'] },
    { label: 'Notifications', route: '/notifications', icon: Bell, activeNames: ['Notifications', 'Emails', 'Webhook'] },
];
