import type { SharedData } from '@/types';
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

export function useCurrentWorkspace() {
    const page = usePage<SharedData>();

    const currentWorkspace = computed(() => {
        return page.props.workspace?.current ?? null;
    });

    function workspaceRoute(
        name: string,
        params: Record<string, unknown> = {},
    ): string {
        if (!currentWorkspace.value) {
            return route('login');
        }

        return route(name, {
            workspace: currentWorkspace.value.slug,
            ...params,
        });
    }

    return {
        currentWorkspace,
        workspaceRoute,
    };
}
