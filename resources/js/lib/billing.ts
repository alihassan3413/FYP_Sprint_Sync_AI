/**
 * Recurring invoice helpers for the browser.
 *
 * Amounts are integers in minor units and percentages are basis points, exactly
 * as on the server. Typed input is parsed with string operations, never through
 * a float, and the live totals mirror InvoiceCalculator (half-up rounding, once
 * per adjustment). The server recalculates and is authoritative.
 */

export type PricingMode = 'fixed' | 'hourly';
export type AdjustmentKind = 'fee' | 'tax' | 'discount';
export type AdjustmentType = 'percentage' | 'fixed';

export interface TeamMemberOption {
    public_id: string;
    name: string;
    title: string | null;
}

/** "2000" → 200000, "5.55" → 555, "2.5" (percent) → 250. Null when not a valid amount. */
export function parseScaled(value: string, decimals = 2): number | null {
    const trimmed = value.trim().replace(/,/g, '');

    if (!new RegExp(`^\\d{1,9}(\\.\\d{0,${decimals}})?$`).test(trimmed)) return null;

    const [whole, fraction = ''] = trimmed.split('.');

    return Number(whole + fraction.padEnd(decimals, '0'));
}

/** 200000 → "2000.00" for an input field. */
export function minorToInput(minor: number): string {
    const sign = minor < 0 ? '-' : '';
    const abs = Math.abs(minor);

    return `${sign}${Math.floor(abs / 100)}.${String(abs % 100).padStart(2, '0')}`;
}

/** 200 → "2", 250 → "2.5" */
export function basisPointsToInput(basisPoints: number): string {
    return minorToInput(basisPoints).replace(/\.?0+$/, '');
}

export function formatMoney(minor: number, currency: string): string {
    return new Intl.NumberFormat(undefined, { style: 'currency', currency, minimumFractionDigits: 2 }).format(minor / 100);
}

/**
 * Integer division rounding half away from zero, as Money::divideHalfUp. BigInt
 * because amount × basis points can pass JavaScript's safe-integer range.
 */
function divideHalfUp(numerator: bigint, denominator: bigint): number {
    const abs = numerator < 0n ? -numerator : numerator;
    const quotient = abs / denominator + ((abs % denominator) * 2n >= denominator ? 1n : 0n);

    return Number(numerator < 0n ? -quotient : quotient);
}

export interface PlanTotals {
    subtotal: number;
    adjustments: number[];
    total: number;
}

export function calculateTotals(lineAmounts: number[], adjustments: { kind: AdjustmentKind; type: AdjustmentType; value: number }[]): PlanTotals {
    const subtotal = lineAmounts.reduce((sum, amount) => sum + amount, 0);
    const amounts = adjustments.map((adjustment) => {
        const amount = adjustment.type === 'percentage' ? divideHalfUp(BigInt(subtotal) * BigInt(adjustment.value), 10_000n) : adjustment.value;

        return adjustment.kind === 'discount' ? -amount : amount;
    });

    return { subtotal, adjustments: amounts, total: amounts.reduce((sum, amount) => sum + amount, subtotal) };
}

export function ordinal(day: number): string {
    const suffix = day % 10 === 1 && day !== 11 ? 'st' : day % 10 === 2 && day !== 12 ? 'nd' : day % 10 === 3 && day !== 13 ? 'rd' : 'th';

    return `${day}${suffix}`;
}

/** Parse a Y-m-d calendar date without any timezone shift. */
function calendarDate(value: string): Date {
    const [year, month, day] = value.split('-').map(Number);

    return new Date(year, month - 1, day);
}

/** "2026-11-01" → "Nov 1" */
export function formatShortDate(value: string): string {
    return calendarDate(value).toLocaleDateString(undefined, { month: 'short', day: 'numeric' });
}

/** "2026-10-01" → "October" */
export function monthName(value: string): string {
    return calendarDate(value).toLocaleDateString(undefined, { month: 'long' });
}

/** Hourly drafts are prepared on the 1st, when last month's hours are complete; fixed plans prepare on their send day. */
export function generationDayFor(mode: PricingMode, sendDay: number): number {
    return mode === 'hourly' ? 1 : sendDay;
}

/**
 * Mirrors BillingSchedule::nextCycle for a plan that isn't saved yet: the first
 * month whose draft is generated strictly after today, its planned send date
 * and the month it bills.
 */
export function nextCycle(
    generationDay: number,
    sendDay: number,
    period: 'previous_month' | 'current_month',
    today: string,
): { generationOn: string; sendOn: string; periodStart: string } {
    const now = calendarDate(today);
    let month = new Date(now.getFullYear(), now.getMonth(), 1);

    if (new Date(month.getFullYear(), month.getMonth(), generationDay) <= now) month = new Date(month.getFullYear(), month.getMonth() + 1, 1);

    const iso = (date: Date) => `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;

    return {
        generationOn: iso(new Date(month.getFullYear(), month.getMonth(), generationDay)),
        sendOn: iso(new Date(month.getFullYear(), month.getMonth(), sendDay)),
        periodStart: iso(new Date(month.getFullYear(), month.getMonth() - (period === 'previous_month' ? 1 : 0), 1)),
    };
}

export const REMINDER_OPTIONS = [
    { value: 0, label: 'on the day' },
    { value: 1, label: '1 day before' },
    { value: 3, label: '3 days before' },
    { value: 7, label: '7 days before' },
] as const;
