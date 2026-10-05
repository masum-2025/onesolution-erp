import { currencyDigits, decimalStringToMinor, formatNumber, minorToDecimalString } from '@/lib/format';

/**
 * Point of sale helpers. The cart is worked out exactly as the server does
 * (integers, half up), so the screen shows what the receipt will say; the
 * server works it out again and its numbers are the ones kept.
 */

const BANGLA = { '০': '0', '১': '1', '২': '2', '৩': '3', '৪': '4', '৫': '5', '৬': '6', '৭': '7', '৮': '8', '৯': '9' };
const WHOLE = 10000;

const half = (value, by) => Math.floor((value + Math.floor(by / 2)) / by);

/** "১,২৫০.৫" -> "1250.5" */
export function cleanNumber(text) {
    return String(text ?? '')
        .replace(/[০-৯]/g, (digit) => BANGLA[digit])
        .replace(/[,\s ]/g, '')
        .trim();
}

/** Typed money -> minor units; null when not an amount at or above zero. */
export function amountToMinor(text, currency) {
    const clean = cleanNumber(text);
    if (clean === '' || clean.startsWith('-')) return null;
    return decimalStringToMinor(clean, currencyDigits(currency));
}

/** Minor units -> an amount field's text. */
export function minorToText(minor, currency) {
    return minor === null || minor === undefined ? '' : minorToDecimalString(minor, currencyDigits(currency));
}

/** Typed quantity -> thousandths, no more decimals than the unit allows; null otherwise. */
export function quantityToMilli(text, decimals = 0) {
    const clean = cleanNumber(text);
    const match = clean.match(/^\d+(?:\.(\d+))?$/);
    if (!match || (match[1] ?? '').length > decimals) return null;
    const value = decimalStringToMinor(clean, 3);
    return value > 0 ? value : null;
}

/** Thousandths in the reader's digits ("১২.৫"). */
export function formatQuantity(milli) {
    const text = minorToDecimalString(milli ?? 0, 3).replace(/\.?0+$/, '');
    return (text === '' ? '0' : text).replace(/\d/g, (digit) => formatNumber(Number(digit)));
}

/** VAT inside a price or on top of it (Accounting's split). */
export function splitTax(amount, rateBp, pricesIncludeTax) {
    if (rateBp <= 0) return { net: amount, tax: 0 };
    if (!pricesIncludeTax) return { net: amount, tax: half(amount * rateBp, WHOLE) };
    const net = half(amount * WHOLE, WHOLE + rateBp);
    return { net, tax: amount - net };
}

/**
 * The cart's lines and totals: line amount = quantity x price (half up),
 * less its discount, VAT split, as the server will.
 */
export function priceCart(lines, pricesIncludeTax) {
    const out = lines.map((line) => {
        const gross = half(line.quantity_milli * line.unit_price_minor, 1000);
        const discount = Math.min(gross, Math.max(0, line.discount_minor ?? 0));
        const { net, tax } = splitTax(gross - discount, line.tax_rate_bp ?? 0, pricesIncludeTax);
        return { gross, discount, net, tax, total: net + tax };
    });
    const sum = (key) => out.reduce((total, line) => total + line[key], 0);
    return { lines: out, subtotal: sum('gross'), discount: sum('discount'), tax: sum('tax'), total: sum('total') };
}

/** The discount as basis points of the amount before it. */
export function discountShare(discount, subtotal) {
    return subtotal <= 0 ? 0 : half(discount * 10000, subtotal);
}

/** "12.5" (%) -> 1250 basis points; 0 when not a percentage. */
export function percentToBp(text) {
    const match = /^(\d{1,3})(?:\.(\d{1,2}))?$/.exec(cleanNumber(text));
    return match ? Number(match[1]) * 100 + Number((match[2] ?? '').padEnd(2, '0')) : 0;
}

/** Paid against owed: change only from cash; null when short or the change is more than the cash. */
export function settle(owed, payments) {
    const paid = payments.reduce((total, payment) => total + (payment.amount_minor || 0), 0);
    const cash = payments.filter((payment) => payment.method === 'cash').reduce((total, payment) => total + (payment.amount_minor || 0), 0);
    if (paid < owed) return { ok: false, reason: 'short', paid, change: 0, due: owed - paid };
    if (paid - owed > cash) return { ok: false, reason: 'change_without_cash', paid, change: paid - owed, due: 0 };
    return { ok: true, paid, change: paid - owed, due: 0 };
}

/** Round amounts a customer often hands over, above what is owed (notes of 10, 50, 100, 500, 1000 of the currency). */
export function quickCash(owed, currency) {
    const unit = 10 ** currencyDigits(currency);
    const notes = [10, 50, 100, 500, 1000].map((note) => note * unit);
    const options = new Set([owed]);
    for (const note of notes) {
        const rounded = Math.ceil(owed / note) * note;
        if (rounded > owed) options.add(rounded);
    }
    return [...options].sort((a, b) => a - b).slice(0, 4);
}

/** Tone of a shift's status badge. */
export function shiftTone(status) {
    return { open: 'ok', pending_review: 'warn', closed: 'neutral' }[status] ?? 'neutral';
}

/** Add an item to the cart, or one more of it (quantities in thousandths). */
export function addToCart(cart, item) {
    const existing = cart.find((line) => line.item_id === item.id);
    if (existing) {
        existing.quantity_milli += 1000;
        return cart;
    }
    cart.push({ item_id: item.id, sku: item.sku, name: item.name, unit_id: item.unit_id, unit_price_minor: item.sale_price_minor, tax_rate_bp: item.tax_rate_bp ?? 0, quantity_milli: 1000, discount_minor: 0 });
    return cart;
}

/** A sale's body for the server (or the offline queue). */
export function saleBody(cart, payments, extra = {}) {
    return {
        ...extra,
        lines: cart.map((line) => ({ item_id: line.item_id, quantity_milli: line.quantity_milli, ...(line.discount_minor ? { discount_minor: line.discount_minor } : {}) })),
        payments: payments.filter((payment) => payment.amount_minor > 0).map((payment) => ({ method: payment.method, amount_minor: payment.amount_minor, ...(payment.reference ? { reference: payment.reference } : {}) })),
    };
}
