import { usePage } from '@inertiajs/vue3';
import { computed, type ComputedRef } from 'vue';

import { detectTimezone } from '@/lib/timezones';
import type { SharedData } from '@/types';

/**
 * The timezone the backend resolved for the current user. Falls back to the
 * browser zone only when there is no authenticated user to resolve one for.
 */
export function useUserTimezone(): ComputedRef<string> {
    const page = usePage<SharedData>();

    return computed(() => page.props.auth?.timezone ?? detectTimezone());
}
