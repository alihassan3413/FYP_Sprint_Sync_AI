import { useNotificationStore } from '@/stores/notification.store';
import type { AppError } from '@/types/errors';
import { router } from '@inertiajs/vue3';

/**
 * Per-code custom handlers.
 *
 * The default behavior for any error is a toast. But for some codes
 * we want richer UX — a redirect, a modal, a special CTA. Register
 * those overrides here.
 *
 * ─── HOW TO ADD A NEW HANDLER ─────────────────────────────────────
 *
 *   1. Define the error code in backend's ErrorCode registry
 *   2. Throw the exception from your domain code
 *   3. Add an entry to `handlers` below with custom UX
 *
 * The handler receives the parsed AppError and a NotificationStore
 * instance. It can do anything: navigate, show a modal, fire an
 * analytics event, etc. Returns true if handled (no default toast),
 * or false to let the default toast fire too.
 */

type CodeHandler = (error: AppError, notify: ReturnType<typeof useNotificationStore>) => boolean;

const handlers: Record<string, CodeHandler> = {
    /**
     * Session expired → redirect to login. Show one toast on the way out.
     */
    'AUTH.SESSION.EXPIRED': (error, notify) => {
        notify.warning('Your session has expired. Please sign in again.', {
            duration: 4000,
        });
        setTimeout(() => router.visit('/login'), 800);
        return true; // we handled it; no extra toast
    },

    /**
     * Unauthenticated → redirect to login (no toast — would be redundant).
     */
    'AUTH.UNAUTHENTICATED': () => {
        router.visit('/login');
        return true;
    },

    /**
     * Workspace limit reached → toast with an "Upgrade" CTA.
     * The backend includes meta.upgrade_url, so we use it dynamically.
     */
    'WORKSPACE.LIMIT.REACHED': (error, notify) => {
        const upgradeUrl = error.meta.upgrade_url as string | undefined;

        notify.error(error.message, {
            title: 'Upgrade required',
            traceId: error.meta.trace_id,
            duration: 8000,
            action: upgradeUrl
                ? {
                      label: 'Upgrade',
                      onClick: () => router.visit(upgradeUrl),
                  }
                : undefined,
        });
        return true;
    },

    /**
     * Already a member → toast that links to the workspace.
     */
    'WORKSPACE.ALREADY_MEMBER': (error, notify) => {
        notify.info(error.message, {
            title: 'Already a member',
            duration: 5000,
        });
        return true;
    },

    // Add more handlers here as features grow.
    // Example pattern for an upcoming Teams module:
    //
    //   'TEAM.MEMBER.LIMIT_REACHED': (error, notify) => { ... },
    //   'TEAM.INVITATION.ALREADY_PENDING': (error, notify) => { ... },
};

/**
 * Look up a handler by exact code, or by code prefix
 * (e.g. AUTH.* could fall through to a generic auth handler later).
 */
export function getCodeHandler(code: string): CodeHandler | null {
    return handlers[code] ?? null;
}
