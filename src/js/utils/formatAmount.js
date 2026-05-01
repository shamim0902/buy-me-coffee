const decodeHtml = (value) => {
    const textArea = document.createElement('textarea');
    textArea.innerHTML = String(value || '');
    return textArea.value.replace(/\u00a0/g, ' ');
};

const getRuntimeConfig = () => window.BuyMeCoffeeAdmin || window.buymecoffee_general || {};

export const getCurrencySymbol = (currency) => {
    const config = getRuntimeConfig();
    const symbols = config.currency_symbols || {};
    const code = (currency || config.default_currency || 'USD').toUpperCase();
    return decodeHtml(symbols[code] || code + ' ');
};

const addThousands = (value, separator) => value.replace(/\B(?=(\d{3})+(?!\d))/g, separator);

export const formatMajorAmount = (amount, currency, options = {}) => {
    const config = getRuntimeConfig();
    const settings = config.formatting_settings || {};
    const decimalSetting = options.decimalSeparator || settings.decimal_separator || 'dot';
    const position = options.currencyPosition || settings.currency_position || 'before';
    const decimalSeparator = decimalSetting === 'comma' ? ',' : '.';
    const thousandSeparator = decimalSeparator === ',' ? '.' : ',';
    const code = (currency || config.default_currency || settings.currency || 'USD').toUpperCase();
    const symbol = getCurrencySymbol(code);
    const number = Number(amount || 0);
    const fixed = Number.isFinite(number) ? number.toFixed(2) : '0.00';
    const [whole, decimal] = fixed.split('.');
    const formattedNumber = addThousands(whole, thousandSeparator) + decimalSeparator + decimal;

    switch (position) {
        case 'after':
            return formattedNumber + symbol;
        case 'iso_before':
            return code + ' ' + formattedNumber;
        case 'iso_after':
            return formattedNumber + ' ' + code;
        case 'symbool_before_iso':
            return symbol + formattedNumber + ' ' + code;
        case 'symbool_after_iso':
            return code + ' ' + formattedNumber + symbol;
        case 'symbool_and_iso':
            return code + ' ' + symbol + formattedNumber;
        case 'before':
        default:
            return symbol + formattedNumber;
    }
};

export const formatAmount = (cents, currency, options = {}) => {
    if ((cents === null || cents === undefined || cents === '') && options.empty !== undefined) {
        return options.empty;
    }

    return formatMajorAmount(Number(cents || 0) / 100, currency, options);
};
