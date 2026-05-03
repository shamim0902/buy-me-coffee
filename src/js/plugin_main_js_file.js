import app from './elements.js';
import { formatAmount, formatMajorAmount, getCurrencySymbol } from './utils/formatAmount.js';

import {
    applyFilters,
    addFilter,
    addAction,
    doAction,
    removeAllActions
} from '@wordpress/hooks';

export default class BuyMeCoffee {
    constructor() {
        this.doAction = doAction;
        this.addFilter = addFilter;
        this.addAction = addAction;
        this.applyFilters = applyFilters;
        this.removeAllActions = removeAllActions;
        this.appVars = window.BuyMeCoffeeAdmin || {};
        this.app = this.extendVueConstructor();
    }

    extendVueConstructor() {
        const self = this;
        app.mixin({
            methods: {
                addFilter,
                applyFilters,
                doAction,
                addAction,
                removeAllActions,
                ucFirst: self.ucFirst,
                ucWords: self.ucWords,
                slugify: self.slugify,
                $get: self.$get,
                $post: self.$post,
                $adminGet: self.$adminGet,
                $adminPost: self.$adminPost,
                // $del: self.$del,
                // $put: self.$put,
                // $patch: self.$patch,
                $handleSuccess: self.handleSuccess,
                $handleError: self.handleError,
                $formatAmount: formatAmount,
                $formatMajorAmount: formatMajorAmount,
                $currencySymbol: getCurrencySymbol,
                $saveData: self.saveData,
                $getData: self.getData,
                convertToText: self.convertToText,
                $setTitle(title) {
                    document.title = title;
                }
            }
        });

        return app;
    }
    registerBlock(blockLocation, blockName, block) {
        const slug = this.appVars.slug || 'buy-me-coffee';
        this.addFilter(blockLocation, slug, function (components) {
            components[blockName] = block;
            return components;
        });
    }

    registerTopMenu(title, route) {
        if (!title || !route.name || !route.path || !route.component) {
            return;
        }

        const slug = this.appVars.slug || 'buy-me-coffee';

        this.addFilter('buy_me_coffee_top_menus', slug, function (menus) {
            menus = menus.filter(m => m.route !== route.name);
            menus.push({
                route: route.name,
                title: title
            });
            return menus;
        });

        this.addFilter('buy_me_coffee_global_routes', slug, function (routes) {
            routes = routes.filter(r => r.name !== route.name);
            routes.push(route);
            return routes;
        });
    }

    $publicAssets(path){
        return ((window.BuyMeCoffeeAdmin?.assets_url || '') + path);
    }

    $get(options) {
        return window.jQuery.get(window.BuyMeCoffeeAdmin.ajaxurl, options);
    }

    $adminGet(options) {
        options.action = 'buymecoffee_admin_ajax';
        return window.jQuery.get(window.BuyMeCoffeeAdmin.ajaxurl, options);
    }

    $post(options) {
        return window.jQuery.post(window.BuyMeCoffeeAdmin.ajaxurl, options);
    }

    $adminPost(options) {
        options.action = 'buymecoffee_admin_ajax';
        return window.jQuery.post(window.BuyMeCoffeeAdmin.ajaxurl, options);
    }

    $getJSON(options) {
        return window.jQuery.getJSON(window.BuyMeCoffeeAdmin.ajaxurl, options);
    }

    saveData(key, data) {
        let existingData = window.localStorage.getItem('__buymecoffee_data');

        if (!existingData) {
            existingData = {};
        } else {
            existingData = JSON.parse(existingData);
        }

        existingData[key] = data;

        window.localStorage.setItem('__buymecoffee_data', JSON.stringify(existingData));
        window.dispatchEvent(new CustomEvent('buymecoffee:data-saved', {
            detail: { key, data }
        }));
    }

    getData(key, defaultValue = false) {
        let existingData = window.localStorage.getItem('__buymecoffee_data');
        existingData = JSON.parse(existingData);
        if (!existingData) {
            return defaultValue;
        }

        if (existingData[key]) {
            return existingData[key];
        }

        return defaultValue;

    }

    longLocalDateTime(date) {
        return this.dateTimeFormat(
            date, 'ddd, DD MMM, YYYY hh:mm:ssa'
        );
    }

    ucFirst(text) {
        return text[0].toUpperCase() + text.slice(1).toLowerCase();
    }

    ucWords(text) {
        return (text + '').replace(/^(.)|\s+(.)/g, function ($1) {
            return $1.toUpperCase();
        })
    }

    slugify(text) {
        return text.toString().toLowerCase()
            .replace(/\s+/g, '-') // Replace spaces with -
            .replace(/[^\w\\-]+/g, '') // Remove all non-word chars
            .replace(/\\-\\-+/g, '-') // Replace multiple - with single -
            .replace(/^-+/, '') // Trim - from start of text
            .replace(/-+$/, ''); // Trim - from end of text
    }

    handleSuccess(message) {
        ElNotification({
            type: 'success',
            title: 'Success',
            message: message,
            offset: 32,
            dangerouslyUseHTMLString: false
        });
    }

    handleError(response) {
        if (response.responseJSON) {
            response = response.responseJSON;
        }
        let errorMessage = '';
        if (typeof response === 'string') {
            errorMessage = response;
        } else if (response?.data?.message) {
            errorMessage = response.data.message;
        } else if (response && response.message) {
            errorMessage = response.message;
        } else {
            errorMessage = this.convertToText(response);
        }
        if (!errorMessage) {
            errorMessage = 'Something is wrong!';
        }
        ElNotification({
            type: 'error',
            title: 'Error',
            message: errorMessage,
            offset: 32,
            dangerouslyUseHTMLString: false
        });
    }

    convertToText(obj) {
        const string = [];
        if (typeof (obj) === 'object' && (obj.join === undefined)) {
            for (const prop in obj) {
                string.push(this.convertToText(obj[prop]));
            }
        } else if (typeof (obj) === 'object' && !(obj.join === undefined)) {
            for (const prop in obj) {
                string.push(this.convertToText(obj[prop]));
            }
        } else if (typeof (obj) === 'function') {

        } else if (typeof (obj) === 'string') {
            string.push(obj)
        }

        return string.join('\n')
    }
}
