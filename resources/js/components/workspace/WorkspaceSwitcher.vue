<script setup lang="ts">
import { useSidebar } from '@/components/ui/sidebar';
import { useCurrentWorkspace } from '@/composables/useCurrentWorkspace';
import type { SharedData, WorkspaceSummary } from '@/types';
import { Check, ChevronsUpDown, Plus, Settings } from 'lucide-vue-next';
import { Link } from '@inertiajs/vue3';

const page = usePage<SharedData>();
const { state } = useSidebar();
const { workspaceRoute } = useCurrentWorkspace();

const workspace = computed(() => page.props.workspace ?? null);

const current = computed(() => workspace.value?.current ?? null);

const available = computed<WorkspaceSummary[]>(() => workspace.value?.available ?? []);
const isCollapsed = computed(() => state.value === 'collapsed');
const isCreateWorkspaceOpen = ref(false);
const switchingId = ref<number | null>(null);

function initialsOf(name: string): string {
    if (!name) return '?';
    const parts = name.trim().split(/\s+/);
    if (parts.length === 1) {
        return parts[0].slice(0, 2).toUpperCase();
    }
    return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase();
}

function switchWorkspace(workspaceItem: WorkspaceSummary) {
    if (workspaceItem.is_current || switchingId.value !== null) return;

    switchingId.value = workspaceItem.id;

    router.post(
        route('workspace.switch', {
            workspace: workspaceItem.slug,
        }),
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                switchingId.value = null;
            },
        },
    );
}

function openCreateWorkspaceModal() {
    isCreateWorkspaceOpen.value = true;
}
</script>

<template>
    <DropdownMenu v-if="current">
        <DropdownMenuTrigger as-child>
            <SidebarMenuButton size="lg" class="data-[state=open]:bg-sidebar-accent data-[state=open]:text-sidebar-accent-foreground cursor-pointer">
                <div class="bg-primary flex aspect-square size-8 shrink-0 items-center justify-center rounded-sm text-sm font-extrabold text-black">
                    {{ initialsOf(current.name) }}
                </div>

                <div v-if="!isCollapsed" class="grid min-w-0 flex-1 text-left text-sm leading-tight">
                    <span class="truncate font-semibold">{{ current.name }}</span>
                    <span class="text-muted-foreground truncate text-xs capitalize">
                        {{ current.role }}
                    </span>
                </div>

                <ChevronsUpDown v-if="!isCollapsed" class="ml-auto size-4 shrink-0 opacity-60" />
            </SidebarMenuButton>
        </DropdownMenuTrigger>

        <DropdownMenuContent class="w-[--radix-dropdown-menu-trigger-width] min-w-64 rounded-md" align="start" side="bottom" :side-offset="4">
            <!-- List of workspaces -->
            <DropdownMenuLabel class="text-muted-foreground text-xs font-semibold tracking-wider uppercase"> Workspaces </DropdownMenuLabel>

            <DropdownMenuGroup>
                <DropdownMenuItem
                    v-for="ws in available"
                    :key="ws.id"
                    class="cursor-pointer gap-3"
                    :disabled="switchingId !== null"
                    @click="switchWorkspace(ws)"
                >
                    <!-- Avatar -->
                    <div
                        class="bg-primary flex aspect-square size-7 shrink-0 items-center justify-center rounded-sm border border-black text-xs font-extrabold text-black"
                    >
                        {{ initialsOf(ws.name) }}
                    </div>

                    <!-- Name + role -->
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-sm font-medium">{{ ws.name }}</p>
                        <p class="text-muted-foreground truncate text-xs capitalize">
                            {{ ws.role }}
                        </p>
                    </div>

                    <!-- Check mark for current -->
                    <Check v-if="ws.is_current" class="text-foreground size-4 shrink-0" />
                    <!-- Loader for switching -->
                    <div
                        v-else-if="switchingId === ws.id"
                        class="size-4 shrink-0 animate-spin rounded-full border-2 border-black border-t-transparent"
                    />
                </DropdownMenuItem>
            </DropdownMenuGroup>

            <DropdownMenuSeparator />

            <!-- Actions -->
            <DropdownMenuItem class="cursor-pointer gap-2" @select.prevent="openCreateWorkspaceModal">
                <Plus class="size-4" />
                <span>New workspace</span>
            </DropdownMenuItem>

            <DropdownMenuItem as-child class="cursor-pointer gap-2">
                <Link :href="workspaceRoute('workspace.settings')">
                    <Settings class="size-4" />
                    <span>Workspace settings</span>
                </Link>
            </DropdownMenuItem>
        </DropdownMenuContent>
    </DropdownMenu>

    <SidebarMenuButton v-else size="lg" as-child>
        <Link :href="workspaceRoute('workspace.invitations.create')" class="gap-3">
            <div class="flex aspect-square size-8 shrink-0 items-center justify-center border-2 border-black bg-white">
                <Plus class="size-4" />
            </div>
            <div v-if="!isCollapsed" class="grid flex-1 text-left text-sm leading-tight">
                <span class="font-semibold">Create workspace</span>
                <span class="text-muted-foreground text-xs">Get started</span>
            </div>
        </Link>
    </SidebarMenuButton>

    <CreateWorkspaceModal v-model:open="isCreateWorkspaceOpen" />
</template>
