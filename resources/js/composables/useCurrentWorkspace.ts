import type { SharedData } from '@/types';
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

export function useCurrentWorkspace() {
    const page = usePage<SharedData>();

    const currentWorkspace = computed(() => {
        return page.props.workspace?.current ?? null;
    });

    function workspaceRoute(name: string, params: Record<string, unknown> = {}): string {
        const workspace = (params.workspace as string | undefined) ?? currentWorkspace.value?.slug;

        if (!workspace) {
            console.error(`workspaceRoute(): no workspace context available for route "${name}".`);
            return route('login');
        }

        return route(name, { ...params, workspace });
    }

    return {
        currentWorkspace,
        workspaceRoute,
    };
}
