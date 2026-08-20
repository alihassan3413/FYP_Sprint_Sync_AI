import type { SharedData } from '@/types';
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

export function useCurrentWorkspace() {
    const page = usePage<SharedData>();

    const currentWorkspace = computed(() => {
        return page.props.workspace?.current ?? null;
    });

    /**
     * The workspace slug sitting in the current URL, for pages that are already
     * tenant-scoped. Shared props can go stale; the address bar cannot.
     */
    const workspaceFromUrl = computed(() => {
        const [first] = page.url.split('?')[0].split('/').filter(Boolean);

        return first ?? null;
    });

    function workspaceRoute(name: string, params: Record<string, unknown> = {}): string {
        const workspace = (params.workspace as string | undefined) ?? currentWorkspace.value?.slug ?? workspaceFromUrl.value;

        if (!workspace) {
            /*
             * Deliberately not route('login'): sending someone to the login page
             * mid-session reads as a dead button. A hash keeps them where they
             * are and leaves the failure visible in the console instead.
             */
            console.error(`workspaceRoute(): no workspace context available for route "${name}".`);

            return '#';
        }

        return route(name, { ...params, workspace });
    }

    return {
        currentWorkspace,
        workspaceRoute,
    };
}
