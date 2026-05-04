<script setup lang="ts">
import { useSidebar } from "@/components/ui/sidebar";
import type { SharedData, WorkspaceSummary } from "@/types";
import { Check, ChevronsUpDown, Plus, Settings } from "lucide-vue-next";

const page = usePage<SharedData>();
const { state } = useSidebar();

const workspace = computed(() => page.props.workspace ?? null);
const current = computed(() => workspace.value?.current ?? null);
const available = computed<WorkspaceSummary[]>(() => workspace.value?.available ?? []);
const isCollapsed = computed(() => state.value === "collapsed");
const isCreateWorkspaceOpen = ref(false);
const switchingId = ref<number | null>(null);

function initialsOf(name: string): string {
  if (!name) return "?";
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
    `/workspace/${workspaceItem.id}/switch`,
    {},
    {
      preserveScroll: true,
      onFinish: () => {
        switchingId.value = null;
      },
    }
  );
}

function openCreateWorkspaceModal() {
  isCreateWorkspaceOpen.value = true;
}
</script>

<template>
  <DropdownMenu v-if="current">
    <DropdownMenuTrigger as-child>
      <SidebarMenuButton
        size="lg"
        class="data-[state=open]:bg-sidebar-accent data-[state=open]:text-sidebar-accent-foreground"
      >
        <div
          class="flex aspect-square size-8 items-center justify-center text-sm font-extrabold text-black shrink-0 rounded-sm bg-primary"
        >
          {{ initialsOf(current.name) }}
        </div>

        <div
          v-if="!isCollapsed"
          class="grid flex-1 text-left text-sm leading-tight min-w-0"
        >
          <span class="truncate font-semibold">{{ current.name }}</span>
          <span class="truncate text-xs capitalize text-muted-foreground">
            {{ current.role }}
          </span>
        </div>

        <ChevronsUpDown v-if="!isCollapsed" class="ml-auto size-4 shrink-0 opacity-60" />
      </SidebarMenuButton>
    </DropdownMenuTrigger>

    <DropdownMenuContent
      class="w-[--radix-dropdown-menu-trigger-width] min-w-64 rounded-md"
      align="start"
      side="bottom"
      :side-offset="4"
    >
      <!-- List of workspaces -->
      <DropdownMenuLabel
        class="text-xs font-semibold uppercase tracking-wider text-muted-foreground"
      >
        Workspaces
      </DropdownMenuLabel>

      <DropdownMenuGroup>
        <DropdownMenuItem
          v-for="ws in available"
          :key="ws.id"
          class="gap-3 cursor-pointer"
          :disabled="switchingId !== null"
          @click="switchWorkspace(ws)"
        >
          <!-- Avatar -->
          <div
            class="flex aspect-square size-7 items-center justify-center border border-black text-xs font-extrabold text-black shrink-0 bg-primary rounded-sm"
          >
            {{ initialsOf(ws.name) }}
          </div>

          <!-- Name + role -->
          <div class="flex-1 min-w-0">
            <p class="truncate text-sm font-medium">{{ ws.name }}</p>
            <p class="truncate text-xs capitalize text-muted-foreground">
              {{ ws.role }}
            </p>
          </div>

          <!-- Check mark for current -->
          <Check v-if="ws.is_current" class="size-4 shrink-0 text-foreground" />
          <!-- Loader for switching -->
          <div
            v-else-if="switchingId === ws.id"
            class="size-4 shrink-0 animate-spin border-2 border-black border-t-transparent rounded-full"
          />
        </DropdownMenuItem>
      </DropdownMenuGroup>

      <DropdownMenuSeparator />

      <!-- Actions -->
      <DropdownMenuItem
        class="gap-2 cursor-pointer"
        @select.prevent="openCreateWorkspaceModal"
      >
        <Plus class="size-4" />
        <span>New workspace</span>
      </DropdownMenuItem>

      <DropdownMenuItem as-child class="gap-2 cursor-pointer">
        <Link href="/workspace/settings">
          <Settings class="size-4" />
          <span>Workspace settings</span>
        </Link>
      </DropdownMenuItem>
    </DropdownMenuContent>
  </DropdownMenu>

  <SidebarMenuButton v-else size="lg" as-child>
    <Link href="/workspace/invitations/create" class="gap-3">
      <div
        class="flex aspect-square size-8 items-center justify-center border-2 border-black bg-white shrink-0"
      >
        <Plus class="size-4" />
      </div>
      <div v-if="!isCollapsed" class="grid flex-1 text-left text-sm leading-tight">
        <span class="font-semibold">Create workspace</span>
        <span class="text-xs text-muted-foreground">Get started</span>
      </div>
    </Link>
  </SidebarMenuButton>

  <CreateWorkspaceModal v-model:open="isCreateWorkspaceOpen" />
</template>
