import { handleError } from '@/lib/errors/handleError';
import { useNotificationStore } from '@/stores/notification.store';
import type { FlashError } from '@/types/errors';
import { router, usePage } from '@inertiajs/vue3';
import { watch } from 'vue';

interface FlashBag {
    success?: string | null;
    error?: FlashError | string | null;
    info?: string | null;
    warning?: string | null;
}

/**
 * The flash payload already turned into toasts.
 *
 * Module scope on purpose: the layout that calls this composable remounts on
 * some navigations, and each remount re-runs the `immediate` watcher against
 * the flash bag that is still sitting in the page props. Without a marker that
 * outlives the component, that shows the same message a second time.
 *
 * Inertia builds a fresh props object per response, so identity is enough to
 * tell one response's flash from the next — two separate requests carrying the
 * same wording are still two distinct objects, and both get shown.
 */
let lastHandled: FlashBag | null = null;

/** router.on() returns nothing useful to dedupe on, so guard registration. */
let exceptionListenerBound = false;

/**
 * Bridges Inertia flash messages to the toast system.
 *
 * Backend sends:
 *   back()->with('success', 'Workspace created.')
 *   back()->with('error', ['code' => '...', 'message' => '...', 'meta' => [...]])
 *   back()->with('info', 'Some info.')
 *
 * This composable watches `page.props.flash` and converts them to toasts.
 * Call it ONCE in your root component (after Pinia is mounted).
 *
 * It also listens for Inertia router exceptions (network errors, 500s on
 * Inertia requests) and routes them through handleError too.
 */
export function useFlashToasts(): void {
    const page = usePage<{ flash?: FlashBag }>();

    const notify = useNotificationStore();

    watch(
        () => page.props.flash,
        (flash) => {
            if (!flash || flash === lastHandled) return;

            lastHandled = flash;

            if (flash.success) {
                notify.success(flash.success);
            }

            if (flash.info) {
                notify.info(flash.info);
            }

            if (flash.warning) {
                notify.warning(flash.warning);
            }

            if (flash.error) {
                // Errors flow through handleError so code-based handlers fire
                // (session expired → redirect, limit reached → action toast, etc.)
                handleError(flash.error);
            }
        },
        // Not deep: the bag is replaced wholesale each response, and watching
        // into it fired the callback more than once for a single visit.
        // immediate catches the flash present on the very first page load.
        { immediate: true },
    );

    if (exceptionListenerBound) return;

    exceptionListenerBound = true;

    // Catch Inertia-level errors (network failures, server crashes during navigation)
    router.on('exception', (event) => {
        handleError(event.detail.exception);
    });
}
