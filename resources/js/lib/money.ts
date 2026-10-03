/**
 * Money arrives from the backend as an integer in the currency's minor unit
 * (cents, paisa). The browser only ever formats it: totals, rounding and
 * currency rules live on the server (InvoiceCalculator), never here.
 */

const formatters = new Map<string, Intl.NumberFormat>();

function formatterFor(currency: string, locale: string): Intl.NumberFormat {
    const key = `${locale}:${currency}`;
    let formatter = formatters.get(key);

    if (!formatter) {
        formatter = new Intl.NumberFormat(locale, { style: 'currency', currency });
        formatters.set(key, formatter);
    }

    return formatter;
}

/** Digits after the decimal point for a currency (USD and PKR: 2). */
export function minorUnits(currency: string): number {
    return formatterFor(currency, 'en-US').resolvedOptions().maximumFractionDigits ?? 2;
}

/**
 * formatMoney(505920, 'USD') → "$5,059.20"
 *
 * Throws on a non-integer so a float that slipped through is caught at the
 * edge instead of being printed as a plausible-looking amount.
 */
export function formatMoney(minor: number, currency: string, locale = 'en-US'): string {
    if (!Number.isInteger(minor)) {
        throw new TypeError(`formatMoney expects an integer amount in minor units, got ${minor}.`);
    }

    return formatterFor(currency, locale).format(minor / 10 ** minorUnits(currency));
}
