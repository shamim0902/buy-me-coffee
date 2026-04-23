import { ref, watch, onUnmounted } from 'vue';

/**
 * Debounce a ref value. Returns a new ref that updates
 * after the specified delay when the source changes.
 */
export function useDebouncedRef(source, delay = 300) {
    const debounced = ref(source.value);
    let timer = null;

    watch(source, (val) => {
        if (timer) clearTimeout(timer);
        timer = setTimeout(() => {
            debounced.value = val;
        }, delay);
    });

    onUnmounted(() => {
        if (timer) clearTimeout(timer);
    });

    return debounced;
}

/**
 * Creates a debounced function that delays invocation
 * until after `delay` ms since the last call.
 */
export function useDebounce(fn, delay = 300) {
    let timer = null;

    const debounced = (...args) => {
        if (timer) clearTimeout(timer);
        timer = setTimeout(() => fn(...args), delay);
    };

    onUnmounted(() => {
        if (timer) clearTimeout(timer);
    });

    return debounced;
}
