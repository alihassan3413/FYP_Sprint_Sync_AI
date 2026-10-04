/**
 * Time tracking helpers for the browser. Durations arrive as integer
 * seconds; the server is the authority for every total.
 */

/** 27120 → "7h 32m"; -1800 → "−0h 30m". */
export function formatDuration(seconds: number): string {
    const sign = seconds < 0 ? '−' : '';
    const total = Math.abs(seconds);
    const hours = Math.floor(total / 3600);
    const minutes = Math.floor((total % 3600) / 60);

    return `${sign}${hours}h ${String(minutes).padStart(2, '0')}m`;
}

/** Live clock text for a running timer: "1:05:09". */
export function formatClock(seconds: number): string {
    const s = Math.max(0, Math.floor(seconds));

    return `${Math.floor(s / 3600)}:${String(Math.floor((s % 3600) / 60)).padStart(2, '0')}:${String(s % 60).padStart(2, '0')}`;
}

/** A fresh request key, so a retried request is recognised by the server. */
export function requestKey(): string {
    return crypto.randomUUID();
}

/** "9:12 AM" in the workspace's timezone. */
export function formatTimeOfDay(iso: string, timeZone: string): string {
    return new Date(iso).toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit', timeZone });
}

/** "Oct 12" for a business date (Y-m-d), without timezone shifts. */
export function formatBusinessDate(date: string): string {
    const [year, month, day] = date.split('-').map(Number);

    return new Date(year, month - 1, day).toLocaleDateString(undefined, { month: 'short', day: 'numeric' });
}

/** "Oct 1–9", "Oct 12" or "Sep 28 – Oct 3". */
export function formatPeriod(start: string, end: string): string {
    if (start === end) return formatBusinessDate(start);

    const [, startMonth] = start.split('-');
    const [, endMonth, endDay] = end.split('-');

    return startMonth === endMonth ? `${formatBusinessDate(start)}–${Number(endDay)}` : `${formatBusinessDate(start)} – ${formatBusinessDate(end)}`;
}
