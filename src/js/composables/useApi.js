import { ref } from 'vue';

/**
 * Composable replacing jQuery AJAX wrappers ($get, $post, $adminGet, $adminPost).
 * Uses native fetch with AbortController support.
 */
export function useApi() {
    const loading = ref(false);
    const error = ref(null);

    const appVars = window.BuyMeCoffeeAdmin || {};

    function buildFormData(params) {
        const formData = new FormData();
        function appendData(data, prefix = '') {
            if (data && typeof data === 'object' && !(data instanceof File)) {
                Object.keys(data).forEach(key => {
                    const fullKey = prefix ? `${prefix}[${key}]` : key;
                    appendData(data[key], fullKey);
                });
            } else {
                formData.append(prefix, data == null ? '' : data);
            }
        }
        appendData(params);
        return formData;
    }

    async function request(method, params = {}, signal) {
        const url = appVars.ajaxurl;
        if (!url) throw new Error('AJAX URL not configured');

        const options = {
            method,
            signal,
        };

        if (method === 'GET') {
            const qs = new URLSearchParams(params).toString();
            const fetchUrl = qs ? `${url}?${qs}` : url;
            const res = await fetch(fetchUrl, options);
            if (!res.ok) throw new Error(`HTTP ${res.status}`);
            return res.json();
        }

        options.body = buildFormData(params);
        const res = await fetch(url, options);
        if (!res.ok) throw new Error(`HTTP ${res.status}`);
        return res.json();
    }

    async function adminGet(route, data = {}, signal) {
        const params = {
            action: 'buymecoffee_admin_ajax',
            buymecoffee_nonce: appVars.buymecoffee_nonce,
            route,
            data,
        };
        loading.value = true;
        error.value = null;
        try {
            const result = await request('GET', params, signal);
            return result;
        } catch (err) {
            if (err.name !== 'AbortError') {
                error.value = err.message;
            }
            throw err;
        } finally {
            loading.value = false;
        }
    }

    async function adminPost(route, data = {}, signal) {
        const params = {
            action: 'buymecoffee_admin_ajax',
            buymecoffee_nonce: appVars.buymecoffee_nonce,
            route,
            data,
        };
        loading.value = true;
        error.value = null;
        try {
            const result = await request('POST', params, signal);
            return result;
        } catch (err) {
            if (err.name !== 'AbortError') {
                error.value = err.message;
            }
            throw err;
        } finally {
            loading.value = false;
        }
    }

    async function get(params = {}, signal) {
        loading.value = true;
        error.value = null;
        try {
            return await request('GET', params, signal);
        } catch (err) {
            if (err.name !== 'AbortError') error.value = err.message;
            throw err;
        } finally {
            loading.value = false;
        }
    }

    async function post(params = {}, signal) {
        loading.value = true;
        error.value = null;
        try {
            return await request('POST', params, signal);
        } catch (err) {
            if (err.name !== 'AbortError') error.value = err.message;
            throw err;
        } finally {
            loading.value = false;
        }
    }

    return {
        loading,
        error,
        adminGet,
        adminPost,
        get,
        post,
    };
}
