import { ref, watch, onMounted } from 'vue';

const STORAGE_KEY = '__buymecoffee_theme';

// Shared state across all component instances
const isDark = ref(false);

let initialized = false;

function applyTheme(dark) {
    if (dark) {
        document.documentElement.classList.add('dark');
    } else {
        document.documentElement.classList.remove('dark');
    }
}

function initTheme() {
    if (initialized) return;
    initialized = true;

    const stored = localStorage.getItem(STORAGE_KEY);
    if (stored !== null) {
        isDark.value = stored === 'true';
    } else {
        isDark.value = window.matchMedia('(prefers-color-scheme: dark)').matches;
    }
    applyTheme(isDark.value);

    // Listen for system theme changes
    window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', (e) => {
        if (localStorage.getItem(STORAGE_KEY) === null) {
            isDark.value = e.matches;
        }
    });
}

/**
 * Composable for dark mode toggle.
 * Shared reactive state -- all components see the same isDark value.
 */
export function useTheme() {
    onMounted(() => {
        initTheme();
    });

    function toggleTheme() {
        isDark.value = !isDark.value;
        localStorage.setItem(STORAGE_KEY, String(isDark.value));
        applyTheme(isDark.value);
    }

    function setTheme(dark) {
        isDark.value = dark;
        localStorage.setItem(STORAGE_KEY, String(dark));
        applyTheme(dark);
    }

    watch(isDark, (val) => {
        applyTheme(val);
    });

    return {
        isDark,
        toggleTheme,
        setTheme,
    };
}
