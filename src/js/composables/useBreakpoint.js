import { ref, onMounted, onUnmounted } from 'vue';

/**
 * Reactive breakpoint detection.
 * Returns 'sm' (<768), 'md' (768-1279), 'lg' (>=1280)
 */
export function useBreakpoint() {
    const breakpoint = ref('lg');
    const isMobile = ref(false);
    const isTablet = ref(false);
    const isDesktop = ref(true);

    function update() {
        const width = window.innerWidth;
        if (width < 768) {
            breakpoint.value = 'sm';
            isMobile.value = true;
            isTablet.value = false;
            isDesktop.value = false;
        } else if (width < 1280) {
            breakpoint.value = 'md';
            isMobile.value = false;
            isTablet.value = true;
            isDesktop.value = false;
        } else {
            breakpoint.value = 'lg';
            isMobile.value = false;
            isTablet.value = false;
            isDesktop.value = true;
        }
    }

    let resizeHandler;

    onMounted(() => {
        update();
        resizeHandler = () => update();
        window.addEventListener('resize', resizeHandler, { passive: true });
    });

    onUnmounted(() => {
        if (resizeHandler) {
            window.removeEventListener('resize', resizeHandler);
        }
    });

    return {
        breakpoint,
        isMobile,
        isTablet,
        isDesktop,
    };
}
