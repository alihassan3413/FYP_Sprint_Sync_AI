/**
 * Billing client types. A "client" here is a business the workspace invoices,
 * not the guest workspace role.
 */

export interface Client {
    /** Opaque ULID used in URLs. The database id is never sent to the browser. */
    public_id: string;
    name: string;
    billing_email: string;
    cc_emails: string[];
    /** ISO 4217 code, e.g. "USD" */
    currency: string;
    address: string | null;
    tax_id: string | null;
    /** Authorized, cache-busted URL to the private logo; null when there is none. */
    logo_url: string | null;
    /** ISO datetime, null while active */
    archived_at: string | null;
    /** ISO datetime */
    created_at: string;
}

export interface CurrencyOption {
    value: string;
    label: string;
}

/** "RocketFlood" → "RF", "Acme Corp" → "AC" */
export function clientInitials(name: string): string {
    const words = name.trim().split(/\s+/).filter(Boolean);

    if (words.length > 1) {
        return (words[0][0] + words[1][0]).toUpperCase();
    }

    const capitals = name.match(/[A-Z]/g) ?? [];

    return (capitals.length > 1 ? capitals.slice(0, 2).join('') : name.slice(0, 2)).toUpperCase();
}
