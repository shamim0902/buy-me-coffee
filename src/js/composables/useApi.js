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

    function buildSearchParams(params) {
        const searchParams = new URLSearchParams();

        function appendData(data, prefix = '') {
            if (data && typeof data === 'object' && !(data instanceof File)) {
                Object.keys(data).forEach(key => {
                    const fullKey = prefix ? `${prefix}[${key}]` : key;
                    appendData(data[key], fullKey);
                });
            } else if (prefix) {
                searchParams.append(prefix, data == null ? '' : data);
            }
        }

        appendData(params);
        return searchParams;
    }

    async function request(method, params = {}, signal) {
        const url = appVars.ajaxurl;
        if (!url) throw new Error('AJAX URL not configured');

        const options = {
            method,
            signal,
        };

        if (method === 'GET') {
            const qs = buildSearchParams(params).toString();
            const fetchUrl = qs ? `${url}?${qs}` : url;
            const res = await fetch(fetchUrl, options);
            if (!res.ok) throw new Error(await getErrorMessage(res));
            return parseJsonResponse(res);
        }

        options.body = buildFormData(params);
        const res = await fetch(url, options);
        if (!res.ok) throw new Error(await getErrorMessage(res));
        return parseJsonResponse(res);
    }

    async function parseJsonResponse(res) {
        const text = await res.text();
        if (!text) {
            throw new Error('The server returned an empty response. Please refresh and try again.');
        }

        try {
            return JSON.parse(text);
        } catch (error) {
            throw new Error('The server returned an invalid response. Please refresh and try again.');
        }
    }

    async function getErrorMessage(res) {
        try {
            const json = await res.clone().json();
            return json?.data?.message || json?.message || `HTTP ${res.status}`;
        } catch (error) {
            try {
                return await res.text() || `HTTP ${res.status}`;
            } catch (textError) {
                return `HTTP ${res.status}`;
            }
        }
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
