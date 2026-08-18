/**
 * IANA timezone helpers. The backend is authoritative for which zone a user is
 * in; these helpers only cover detection and presentation.
 */

const FALLBACK_TIMEZONES = [
    'UTC',
    'Africa/Cairo',
    'Africa/Johannesburg',
    'Africa/Lagos',
    'America/Chicago',
    'America/Denver',
    'America/Los_Angeles',
    'America/Mexico_City',
    'America/New_York',
    'America/Sao_Paulo',
    'America/Toronto',
    'Asia/Dhaka',
    'Asia/Dubai',
    'Asia/Hong_Kong',
    'Asia/Jakarta',
    'Asia/Karachi',
    'Asia/Kolkata',
    'Asia/Riyadh',
    'Asia/Seoul',
    'Asia/Shanghai',
    'Asia/Singapore',
    'Asia/Tokyo',
    'Australia/Melbourne',
    'Australia/Perth',
    'Australia/Sydney',
    'Europe/Amsterdam',
    'Europe/Berlin',
    'Europe/Dublin',
    'Europe/Istanbul',
    'Europe/Lisbon',
    'Europe/London',
    'Europe/Madrid',
    'Europe/Moscow',
    'Europe/Paris',
    'Europe/Rome',
    'Europe/Stockholm',
    'Europe/Warsaw',
    'Europe/Zurich',
    'Pacific/Auckland',
];

export interface TimezoneOption {
    value: string;
    label: string;
}

export function detectTimezone(): string {
    try {
        return Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC';
    } catch {
        return 'UTC';
    }
}

export function isSupportedTimezone(timeZone: string | null | undefined): timeZone is string {
    if (!timeZone) return false;

    try {
        Intl.DateTimeFormat(undefined, { timeZone });
        return true;
    } catch {
        return false;
    }
}

export function timezoneOffsetLabel(timeZone: string, at: Date = new Date()): string {
    try {
        const parts = new Intl.DateTimeFormat('en-US', { timeZone, timeZoneName: 'longOffset' }).formatToParts(at);
        const offset = parts.find((part) => part.type === 'timeZoneName')?.value ?? '';
        return offset.replace('GMT', 'UTC') || 'UTC';
    } catch {
        return 'UTC';
    }
}

export function timezoneAbbreviation(timeZone: string, at: Date = new Date()): string {
    try {
        const parts = new Intl.DateTimeFormat('en-US', { timeZone, timeZoneName: 'short' }).formatToParts(at);
        return parts.find((part) => part.type === 'timeZoneName')?.value ?? timezoneOffsetLabel(timeZone, at);
    } catch {
        return timezoneOffsetLabel(timeZone, at);
    }
}

function supportedTimezones(): string[] {
    try {
        const supported = (Intl as { supportedValuesOf?: (key: string) => string[] }).supportedValuesOf?.('timeZone');
        if (supported && supported.length > 0) {
            return supported.includes('UTC') ? supported : ['UTC', ...supported];
        }
    } catch {
        /* fall through to the curated list */
    }

    return FALLBACK_TIMEZONES;
}

export function timezoneOptions(): TimezoneOption[] {
    const at = new Date();

    return supportedTimezones()
        .map((value) => ({
            value,
            label: `${value.replace(/_/g, ' ')} (${timezoneOffsetLabel(value, at)})`,
        }))
        .sort((a, b) => a.value.localeCompare(b.value));
}
