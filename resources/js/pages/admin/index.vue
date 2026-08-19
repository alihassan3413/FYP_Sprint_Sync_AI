<script setup lang="ts">
import type { BreadcrumbItem } from '@/types';
import type { AssistantUsageData, PlatformMetricsData } from '@/types/generated';
import { Head, router } from '@inertiajs/vue3';
import { Bot, Building2, FolderKanban, ShieldCheck, Users } from 'lucide-vue-next';

/**
 * Read-only platform overview for super admins. Every figure here is
 * cross-workspace, which is why the route sits outside the tenant scope.
 */

interface Paginated<T> {
    data: T[];
    current_page: number;
    last_page: number;
    total: number;
    from: number | null;
    to: number | null;
}

interface DirectoryUser {
    id: number;
    name: string;
    email: string;
    is_verified: boolean;
    is_super_admin: boolean;
    workspaces_count: number;
    created_at: string | null;
}

interface DirectoryWorkspace {
    id: number;
    name: string;
    slug: string;
    is_active: boolean;
    owner_name: string | null;
    owner_email: string | null;
    members_count: number;
    projects_count: number;
    created_at: string | null;
}

const props = defineProps<{
    metrics: PlatformMetricsData;
    assistantUsage: AssistantUsageData;
    users: Paginated<DirectoryUser>;
    workspaces: Paginated<DirectoryWorkspace>;
    filters: { user_search: string; workspace_search: string };
}>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Admin', href: '/admin' }];

const userSearch = ref(props.filters.user_search);
const workspaceSearch = ref(props.filters.workspace_search);

const numberFormatter = new Intl.NumberFormat('en-US');

function formatNumber(value: number): string {
    return numberFormatter.format(value);
}

/** Config stores rates in cents, so money arrives here already in cents. */
function formatCents(cents: number): string {
    return `$${(cents / 100).toFixed(2)}`;
}

function formatDate(value: string | null): string {
    if (!value) return '—';

    return new Date(value).toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' });
}

function percentage(part: number, total: number): string {
    if (total === 0) return '0%';

    return `${Math.round((part / total) * 100)}%`;
}

/** Peak drives the bar heights; a flat zero series must not divide by zero. */
const peakSignups = computed(() => Math.max(1, ...props.metrics.signups.map((point) => point.count)));

const taskCompletion = computed(() => percentage(props.metrics.tasks_completed, props.metrics.tasks_total));

function reload(overrides: Record<string, string | number | undefined>) {
    router.get(
        '/admin',
        {
            user_search: userSearch.value || undefined,
            workspace_search: workspaceSearch.value || undefined,
            ...overrides,
        },
        { preserveState: true, preserveScroll: true, replace: true },
    );
}

// Searching resets to page one; keeping the old page would usually land on an
// empty result set.
const search = useDebounceFn(() => reload({ users_page: undefined, workspaces_page: undefined }), 350);

watch([userSearch, workspaceSearch], () => search());
</script>

<template>
    <Head title="Admin" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col">
            <div class="border-b px-4 py-4 md:px-6 lg:px-8">
                <AppPageHeader eyebrow="Platform" title="System overview" description="Everything across every workspace. Read-only." />
            </div>

            <div class="flex-1 space-y-8 px-4 py-6 md:px-6 lg:px-8">
                <!-- Platform metrics -->
                <section>
                    <div class="mb-3 flex items-center gap-2">
                        <Users class="text-muted-foreground size-4" />
                        <h2 class="text-sm font-medium">Platform</h2>
                    </div>

                    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                        <AppStatCard
                            label="Users"
                            :value="formatNumber(metrics.users_total)"
                            :hint="`+${formatNumber(metrics.users_new_30d)} in 30 days`"
                            :trend="metrics.users_new_30d > 0 ? 'up' : null"
                            :fraction="`${formatNumber(metrics.users_verified)} verified`"
                        />
                        <AppStatCard
                            label="Workspaces"
                            :value="formatNumber(metrics.workspaces_total)"
                            :fraction="`${formatNumber(metrics.workspaces_active)} active`"
                        />
                        <AppStatCard
                            label="Projects"
                            :value="formatNumber(metrics.projects_total)"
                            :hint="`${formatNumber(metrics.sprints_total)} sprints`"
                        />
                        <AppStatCard
                            label="Tasks"
                            :value="formatNumber(metrics.tasks_total)"
                            :hint="`${taskCompletion} complete`"
                            :fraction="`${formatNumber(metrics.meetings_total)} meetings`"
                        />
                    </div>

                    <div class="border-border/70 mt-3 rounded-xl border p-4">
                        <p class="text-muted-foreground mb-3 text-xs font-medium tracking-[0.06em] uppercase">Signups, last 30 days</p>

                        <div class="flex h-24 items-end gap-1" role="img" aria-label="Daily signups over the last 30 days">
                            <div
                                v-for="point in metrics.signups"
                                :key="point.date"
                                class="bg-primary/70 hover:bg-primary min-h-px flex-1 rounded-t transition-colors"
                                :style="{ height: `${(point.count / peakSignups) * 100}%` }"
                                :title="`${point.date}: ${point.count} signup${point.count === 1 ? '' : 's'}`"
                            />
                        </div>
                    </div>
                </section>

                <!-- AI assistant usage -->
                <section>
                    <div class="mb-3 flex items-center gap-2">
                        <Bot class="text-muted-foreground size-4" />
                        <h2 class="text-sm font-medium">AI assistant</h2>
                    </div>

                    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                        <AppStatCard label="Conversations" :value="formatNumber(assistantUsage.conversations_total)" />
                        <AppStatCard label="Messages" :value="formatNumber(assistantUsage.messages_total)" />
                        <AppStatCard
                            label="Tokens"
                            :value="formatNumber(assistantUsage.input_tokens + assistantUsage.output_tokens)"
                            :fraction="`${formatNumber(assistantUsage.input_tokens)} in / ${formatNumber(assistantUsage.output_tokens)} out`"
                        />
                        <AppStatCard
                            label="Estimated spend"
                            :value="formatCents(assistantUsage.estimated_cost_cents)"
                            :hint="`${formatCents(assistantUsage.cost_today_cents)} today`"
                        />
                    </div>

                    <div class="mt-3 grid gap-3 lg:grid-cols-2">
                        <div class="border-border/70 overflow-hidden rounded-xl border">
                            <p class="text-muted-foreground border-b px-4 py-2.5 text-xs font-medium tracking-[0.06em] uppercase">By model</p>

                            <AppEmptyState v-if="assistantUsage.by_model.length === 0" title="No assistant usage yet" class="py-8" />

                            <table v-else class="w-full text-sm">
                                <thead class="text-muted-foreground border-b text-xs">
                                    <tr>
                                        <th class="px-4 py-2 text-left font-medium">Model</th>
                                        <th class="px-4 py-2 text-right font-medium">Messages</th>
                                        <th class="px-4 py-2 text-right font-medium">Tokens</th>
                                        <th class="px-4 py-2 text-right font-medium">Cost</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="row in assistantUsage.by_model" :key="`${row.provider}-${row.model}`" class="border-b last:border-0">
                                        <td class="px-4 py-2">
                                            <span class="font-medium">{{ row.model }}</span>
                                            <span v-if="row.provider" class="text-muted-foreground ml-1.5 text-xs">{{ row.provider }}</span>
                                        </td>
                                        <td class="px-4 py-2 text-right tabular-nums">{{ formatNumber(row.messages) }}</td>
                                        <td class="px-4 py-2 text-right tabular-nums">{{ formatNumber(row.input_tokens + row.output_tokens) }}</td>
                                        <td class="px-4 py-2 text-right tabular-nums">{{ formatCents(row.estimated_cost_cents) }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <div class="border-border/70 overflow-hidden rounded-xl border">
                            <p class="text-muted-foreground border-b px-4 py-2.5 text-xs font-medium tracking-[0.06em] uppercase">Heaviest users</p>

                            <AppEmptyState v-if="assistantUsage.top_users.length === 0" title="No assistant usage yet" class="py-8" />

                            <table v-else class="w-full text-sm">
                                <thead class="text-muted-foreground border-b text-xs">
                                    <tr>
                                        <th class="px-4 py-2 text-left font-medium">User</th>
                                        <th class="px-4 py-2 text-right font-medium">Messages</th>
                                        <th class="px-4 py-2 text-right font-medium">Cost</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="user in assistantUsage.top_users" :key="user.id" class="border-b last:border-0">
                                        <td class="px-4 py-2">
                                            <div class="font-medium">{{ user.name }}</div>
                                            <div class="text-muted-foreground text-xs">{{ user.email }}</div>
                                        </td>
                                        <td class="px-4 py-2 text-right tabular-nums">{{ formatNumber(user.messages) }}</td>
                                        <td class="px-4 py-2 text-right tabular-nums">{{ formatCents(user.estimated_cost_cents) }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </section>

                <!-- Directory -->
                <section>
                    <div class="mb-3 flex items-center gap-2">
                        <Building2 class="text-muted-foreground size-4" />
                        <h2 class="text-sm font-medium">Directory</h2>
                    </div>

                    <Tabs default-value="users">
                        <TabsList>
                            <TabsTrigger value="users">Users</TabsTrigger>
                            <TabsTrigger value="workspaces">Workspaces</TabsTrigger>
                        </TabsList>

                        <TabsContent value="users">
                            <div class="mb-3 max-w-sm">
                                <AppSearchInput
                                    v-model="userSearch"
                                    placeholder="Search users by name or email…"
                                    shortcut=""
                                    :bind-shortcut="false"
                                />
                            </div>

                            <div class="border-border/70 overflow-hidden rounded-xl border">
                                <AppEmptyState v-if="users.data.length === 0" title="No users match that search" class="py-10" />

                                <table v-else class="w-full text-sm">
                                    <thead class="text-muted-foreground border-b text-xs">
                                        <tr>
                                            <th class="px-4 py-2 text-left font-medium">User</th>
                                            <th class="px-4 py-2 text-left font-medium">Status</th>
                                            <th class="px-4 py-2 text-right font-medium">Workspaces</th>
                                            <th class="px-4 py-2 text-right font-medium">Joined</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr v-for="user in users.data" :key="user.id" class="border-b last:border-0">
                                            <td class="px-4 py-2">
                                                <div class="flex items-center gap-1.5 font-medium">
                                                    {{ user.name }}
                                                    <ShieldCheck
                                                        v-if="user.is_super_admin"
                                                        class="size-3.5 text-emerald-600"
                                                        aria-label="Super admin"
                                                    />
                                                </div>
                                                <div class="text-muted-foreground text-xs">{{ user.email }}</div>
                                            </td>
                                            <td class="px-4 py-2">
                                                <AppBadge :variant="user.is_verified ? 'success' : 'warning'">
                                                    {{ user.is_verified ? 'Verified' : 'Unverified' }}
                                                </AppBadge>
                                            </td>
                                            <td class="px-4 py-2 text-right tabular-nums">{{ user.workspaces_count }}</td>
                                            <td class="text-muted-foreground px-4 py-2 text-right">{{ formatDate(user.created_at) }}</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>

                            <AppPagination
                                v-if="users.last_page > 1"
                                class="mt-3"
                                :current-page="users.current_page"
                                :last-page="users.last_page"
                                :total="users.total"
                                :from="users.from"
                                :to="users.to"
                                @change="(page) => reload({ users_page: page })"
                            />
                        </TabsContent>

                        <TabsContent value="workspaces">
                            <div class="mb-3 max-w-sm">
                                <AppSearchInput
                                    v-model="workspaceSearch"
                                    placeholder="Search workspaces by name or slug…"
                                    shortcut=""
                                    :bind-shortcut="false"
                                />
                            </div>

                            <div class="border-border/70 overflow-hidden rounded-xl border">
                                <AppEmptyState v-if="workspaces.data.length === 0" title="No workspaces match that search" class="py-10" />

                                <table v-else class="w-full text-sm">
                                    <thead class="text-muted-foreground border-b text-xs">
                                        <tr>
                                            <th class="px-4 py-2 text-left font-medium">Workspace</th>
                                            <th class="px-4 py-2 text-left font-medium">Owner</th>
                                            <th class="px-4 py-2 text-right font-medium">Members</th>
                                            <th class="px-4 py-2 text-right font-medium">Projects</th>
                                            <th class="px-4 py-2 text-right font-medium">Created</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr v-for="workspace in workspaces.data" :key="workspace.id" class="border-b last:border-0">
                                            <td class="px-4 py-2">
                                                <div class="flex items-center gap-1.5">
                                                    <FolderKanban class="text-muted-foreground size-3.5" />
                                                    <span class="font-medium">{{ workspace.name }}</span>
                                                    <AppBadge v-if="!workspace.is_active" variant="danger">Inactive</AppBadge>
                                                </div>
                                                <div class="text-muted-foreground text-xs">{{ workspace.slug }}</div>
                                            </td>
                                            <td class="px-4 py-2">
                                                <div>{{ workspace.owner_name ?? '—' }}</div>
                                                <div class="text-muted-foreground text-xs">{{ workspace.owner_email }}</div>
                                            </td>
                                            <td class="px-4 py-2 text-right tabular-nums">{{ workspace.members_count }}</td>
                                            <td class="px-4 py-2 text-right tabular-nums">{{ workspace.projects_count }}</td>
                                            <td class="text-muted-foreground px-4 py-2 text-right">{{ formatDate(workspace.created_at) }}</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>

                            <AppPagination
                                v-if="workspaces.last_page > 1"
                                class="mt-3"
                                :current-page="workspaces.current_page"
                                :last-page="workspaces.last_page"
                                :total="workspaces.total"
                                :from="workspaces.from"
                                :to="workspaces.to"
                                @change="(page) => reload({ workspaces_page: page })"
                            />
                        </TabsContent>
                    </Tabs>
                </section>
            </div>
        </div>
    </AppLayout>
</template>
