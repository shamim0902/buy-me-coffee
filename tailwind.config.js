/** @type {import('tailwindcss').Config} */
module.exports = {
    darkMode: 'selector',
    content: ['./src/js/**/*.{vue,js,php}'],
    theme: {
        extend: {
            fontFamily: {
                sans: ['Inter', '-apple-system', 'BlinkMacSystemFont', 'Segoe UI', 'sans-serif'],
                mono: ['JetBrains Mono', 'Fira Code', 'Consolas', 'monospace'],
            },
            colors: {
                primary: {
                    50:  '#f0fdfa',
                    100: '#ccfbf1',
                    200: '#99f6e4',
                    300: '#5eead4',
                    400: '#2dd4bf',
                    500: '#0d9488',
                    600: '#0f766e',
                    700: '#115e59',
                    800: '#134e4a',
                    900: '#042f2e',
                },
                neutral: {
                    25:  '#fcfcfd',
                    50:  '#f8fafc',
                    100: '#f1f5f9',
                    200: '#e2e8f0',
                    300: '#cbd5e1',
                    400: '#94a3b8',
                    500: '#64748b',
                    600: '#475569',
                    700: '#334155',
                    800: '#1e293b',
                    900: '#0f172a',
                    950: '#020617',
                },
                success: {
                    50:  '#f0fdf4',
                    100: '#dcfce7',
                    500: '#22c55e',
                    600: '#16a34a',
                    700: '#15803d',
                },
                warning: {
                    50:  '#fffbeb',
                    100: '#fef3c7',
                    500: '#f59e0b',
                    600: '#d97706',
                    700: '#b45309',
                },
                error: {
                    50:  '#fef2f2',
                    100: '#fee2e2',
                    500: '#ef4444',
                    600: '#dc2626',
                    700: '#b91c1c',
                },
                info: {
                    50:  '#eff6ff',
                    100: '#dbeafe',
                    500: '#3b82f6',
                    600: '#2563eb',
                    700: '#1d4ed8',
                },
            },
            fontSize: {
                xs:   ['0.75rem',   { lineHeight: '1rem' }],
                sm:   ['0.8125rem', { lineHeight: '1.25rem' }],
                base: ['0.875rem',  { lineHeight: '1.375rem' }],
                lg:   ['1rem',      { lineHeight: '1.5rem' }],
                xl:   ['1.125rem',  { lineHeight: '1.75rem' }],
                '2xl': ['1.5rem',   { lineHeight: '2rem' }],
                '3xl': ['1.875rem', { lineHeight: '2.25rem' }],
                '4xl': ['2.25rem',  { lineHeight: '2.5rem' }],
            },
            borderRadius: {
                sm:   '4px',
                md:   '6px',
                lg:   '8px',
                xl:   '12px',
                '2xl': '16px',
            },
            boxShadow: {
                xs:   '0 1px 2px 0 rgb(0 0 0 / 0.05)',
                sm:   '0 1px 3px 0 rgb(0 0 0 / 0.1), 0 1px 2px -1px rgb(0 0 0 / 0.1)',
                md:   '0 4px 6px -1px rgb(0 0 0 / 0.1), 0 2px 4px -2px rgb(0 0 0 / 0.1)',
                lg:   '0 10px 15px -3px rgb(0 0 0 / 0.1), 0 4px 6px -4px rgb(0 0 0 / 0.1)',
                xl:   '0 20px 25px -5px rgb(0 0 0 / 0.1), 0 8px 10px -6px rgb(0 0 0 / 0.1)',
                ring: '0 0 0 3px rgb(13 148 136 / 0.15)',
            },
            transitionDuration: {
                fast:    '100ms',
                normal:  '200ms',
                slow:    '300ms',
                slower:  '500ms',
            },
            transitionTimingFunction: {
                default: 'cubic-bezier(0.4, 0, 0.2, 1)',
                spring:  'cubic-bezier(0.34, 1.56, 0.64, 1)',
            },
            animation: {
                'skeleton': 'pulse 1.5s ease-in-out infinite',
                'fade-in':  'fadeIn 200ms ease-out',
                'slide-up': 'slideUp 200ms ease-out',
                'counter':  'counter 800ms ease-out',
            },
            keyframes: {
                fadeIn: {
                    '0%':   { opacity: '0' },
                    '100%': { opacity: '1' },
                },
                slideUp: {
                    '0%':   { opacity: '0', transform: 'translateY(8px)' },
                    '100%': { opacity: '1', transform: 'translateY(0)' },
                },
            },
            spacing: {
                '4.5': '1.125rem', // 18px
                '13':  '3.25rem',  // 52px
                '15':  '3.75rem',  // 60px
                '18':  '4.5rem',   // 72px
            },
            width: {
                'sidebar-expanded':  '240px',
                'sidebar-collapsed': '64px',
            },
        },
    },
    plugins: [],
}
