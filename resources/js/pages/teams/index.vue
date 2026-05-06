<script setup lang="ts">
import { Activity, ListFilter, Mail, Plus, Users } from 'lucide-vue-next';
import { type BreadcrumbItem } from '@/types';
import { formatLastActive, type Member } from '@/lib/members';
import { Head, Link } from '@inertiajs/vue3';

const props = defineProps<{
  members?: Member[];
//   seats?: { used: number; total: number };
  counts?: { active: number; pending: number; total: number };
  loading?: boolean;
}>();

const { workspaceRoute } = useCurrentWorkspace();

const breadcrumbs: BreadcrumbItem[] = [
  { title: 'Team', href: workspaceRoute('workspace.teams.index') },
];


const search = ref('');
const filter = ref<string>('all');
const sort = ref<SortState>({ key: 'name', direction: 'asc' });

const allMembers = computed(() => props.members ?? []);

const stats = computed(() => props.counts ?? {
  total: allMembers.value.length,
  active: allMembers.value.filter((m) => m.status === 'active').length,
  pending: allMembers.value.filter((m) => m.status === 'pending').length,
});

const activeMembers = computed(() =>
  allMembers.value.filter((m) => m.status === 'active'),
);

const filterOptions = computed(() => [
  { value: 'all', label: 'All', count: allMembers.value.length },
  { value: 'active', label: 'Active',
    count: allMembers.value.filter((m) => m.status === 'active').length },
  { value: 'pending', label: 'Invited',
    count: allMembers.value.filter((m) => m.status === 'pending').length },
  { value: 'suspended', label: 'Suspended',
    count: allMembers.value.filter((m) => m.status === 'suspended').length },
]);

const filtered = computed(() => {
  let list = allMembers.value;

  if (filter.value !== 'all') {
    list = list.filter((m) => m.status === filter.value);
  }

  if (search.value.trim()) {
    const q = search.value.trim().toLowerCase();
    list = list.filter(
      (m) =>
        m.name.toLowerCase().includes(q) ||
        m.email.toLowerCase().includes(q) ||
        m.role.toLowerCase().includes(q),
    );
  }

  return list;
});

const seatUsage = computed(() => {
  const used = stats.value.total;
  const total = 10;

  return {
    used,
    total,
  };
});

const currentUserId = computed(
  () => allMembers.value.find((m) => m.is_self)?.id ?? null,
);

const columns: Column<Member>[] = [
  { key: 'name', label: 'Member', sortable: true,
    accessor: (m) => m.name?.toLowerCase() ?? m.email.toLowerCase() },
  { key: 'role', label: 'Role', sortable: true, width: '140px', hideOnMobile: true },
  { key: 'status', label: 'Status', sortable: true, width: '140px' },
  { key: 'last_active_at', label: 'Last active', sortable: true,
    width: '160px', hideOnMobile: true,
    accessor: (m) => (m.last_active_at ? new Date(m.last_active_at).getTime() : 0) },
  { key: 'actions', label: '', align: 'right', width: '64px' },
];

function onResendInvite(m: Member) {
  // router.post(workspaceRoute('workspace.invitations.resend', m.id));
  console.log('resend', m);
}
function onCopyInviteLink(m: Member) {
  // navigator.clipboard.writeText(...);
  console.log('copy link', m);
}
function onChangeRole(m: Member) {
  console.log('change role', m);
}
function onTransferTasks(m: Member) {
  console.log('transfer tasks', m);
}
function onRemove(m: Member) {
  // router.delete(workspaceRoute('workspace.members.destroy', m.id));
  console.log('remove', m);
}
function onRevokeInvite(m: Member) {
  // router.delete(workspaceRoute('workspace.invitations.destroy', m.id));
  console.log('revoke', m);
}

function handleClick() {
  console.log('Invite member clicked');
}
</script>

<template>
  <Head title="Team" />

  <AppLayout :breadcrumbs="breadcrumbs">
    <div class="flex h-full flex-1 flex-col gap-6 p-4 md:p-6 lg:p-8">

      <!-- Page header -->
      <AppPageHeader
        eyebrow="Workspace"
        title="Team"
        description="Manage who has access to this workspace, control roles, and invite teammates."
      >
        <template #actions>
          <Button as-child size="sm" class="gap-1.5" @click="handleClick">
            <Link :href="workspaceRoute('workspace.invitations.create')">
              <Plus class="size-3.5" />
              Invite member
            </Link>
          </Button>
        </template>
      </AppPageHeader>

      <!-- Stat cards -->
      <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
        <AppStatCard label="Total members" :value="stats.total">
          <template #icon><Users class="size-3.5" /></template>
        </AppStatCard>

        <!-- Active now: number + live presence stack -->
        <div class="group relative rounded-xl border bg-card p-4 shadow-sm transition-colors hover:border-foreground/15">
          <div class="flex items-center gap-1.5 text-[11px] font-medium uppercase tracking-[0.06em] text-muted-foreground">
            <Activity class="size-3.5 text-emerald-500" />
            <span>Active now</span>
          </div>
          <div class="mt-2 flex items-center justify-between gap-3">
            <div class="flex items-baseline gap-2">
              <span class="text-2xl font-semibold tracking-tight tabular-nums">
                {{ stats.active }}
              </span>
              <span class="text-xs text-muted-foreground tabular-nums">
                of {{ stats.total }}
              </span>
            </div>
            <AppAvatarStack
              v-if="activeMembers.length > 0"
              :users="activeMembers"
              :max="3"
              size="xs"
            />
          </div>
        </div>

        <AppStatCard
          label="Pending invites"
          :value="stats.pending"
          :hint="stats.pending > 0 ? 'Awaiting reply' : undefined"
        >
          <template #icon><Mail class="size-3.5" /></template>
        </AppStatCard>

        <SeatUsageCard
            :used="seatUsage.used"
            :total="seatUsage.total"
        />
      </div>

      <!-- AI insight strip -->
      <AppAiInsight title="AI workload balance" beta>
        <span class="font-medium text-foreground">Ali</span>
        is carrying 38% of open sprint tasks this week.
        <button class="font-medium text-foreground underline-offset-4 hover:underline">
          Suggest a redistribution
        </button>
      </AppAiInsight>

      <!-- Members table -->
      <div class="overflow-hidden rounded-xl border bg-card shadow-sm">
        <AppListToolBar
          v-model:search="search"
          v-model:filter="filter"
          :filter-options="filterOptions"
          search-placeholder="Search members…"
        >
          <template #right>
            <Button variant="outline" size="sm" class="gap-1.5 text-xs">
              <ListFilter class="size-3.5" />
              Filter
            </Button>
          </template>
        </AppListToolBar>

        <AppDataTable
          v-model:sort="sort"
          :columns="columns"
          :rows="filtered"
          row-key="id"
          :loading="loading"
          :highlighted-key="currentUserId ?? undefined"
        >
          <template #cell-name="{ row }">
            <MemberCell :member="row" />
          </template>

          <template #cell-role="{ value }">
            <AppRoleBadge :role="value" />
          </template>

          <template #cell-status="{ value }">
            <AppStatusBadge :status="value" />
          </template>

          <template #cell-last_active_at="{ row }">
            <span class="text-xs tabular-nums text-muted-foreground">
              {{ formatLastActive(row.last_active_at, row.status) }}
            </span>
          </template>

          <template #cell-actions="{ row }">
            <MemberActionsMenu
              :member="row"
              @resend-invite="onResendInvite"
              @copy-invite-link="onCopyInviteLink"
              @change-role="onChangeRole"
              @transfer-tasks="onTransferTasks"
              @remove="onRemove"
              @revoke-invite="onRevokeInvite"
            />
          </template>

          <template #empty>
            <AppEmptyState
              :title="search || filter !== 'all' ? 'No members match your filters' : 'No team members yet'"
              :description="
                search || filter !== 'all'
                  ? 'Try adjusting your search or clearing filters.'
                  : 'Invite your first teammate to start collaborating on sprints.'
              "
            >
              <template #actions>
                <Button
                  v-if="search || filter !== 'all'"
                  variant="outline"
                  size="sm"
                  @click="search = ''; filter = 'all'"
                >
                  Clear filters
                </Button>
                <Button v-else as-child size="sm">
                  <Link :href="workspaceRoute('workspace.invitations.create')">
                    Invite your first teammate
                  </Link>
                </Button>
              </template>
            </AppEmptyState>
          </template>

          <template #table-footer="{ rows }">
            <div class="flex items-center justify-between px-4 py-2.5 text-xs text-muted-foreground">
              <p class="tabular-nums">
                Showing <span class="font-medium text-foreground">{{ rows.length }}</span>
                of <span class="font-medium text-foreground">{{ allMembers.length }}</span>
                {{ allMembers.length === 1 ? 'member' : 'members' }}
              </p>
              <div class="flex items-center gap-1.5">
                <kbd class="rounded border border-border bg-background px-1.5 py-0.5 font-mono text-[10px]">↑</kbd>
                <kbd class="rounded border border-border bg-background px-1.5 py-0.5 font-mono text-[10px]">↓</kbd>
                <span>to navigate</span>
              </div>
            </div>
          </template>
        </AppDataTable>
      </div>
    </div>
  </AppLayout>
</template>
