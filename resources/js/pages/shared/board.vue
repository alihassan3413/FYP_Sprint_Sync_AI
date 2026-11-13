<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { CalendarClock, CircleCheck, Clock, Eye, Inbox, LogIn, Plus, Send, Users } from 'lucide-vue-next';

import AppLogo from '@/components/AppLogo.vue';
import { formatDueDate, isOverdue } from '@/lib/tasks';

type Column = { id: number; name: string; is_done: boolean; is_default: boolean; position: number };
type SharedTask = {
    id: number;
    title: string;
    description: string | null;
    board_column_id: number;
    due_date: string | null;
    assignee_name: string | null;
};

const props = defineProps<{
    token: string;
    project: { name: string; description: string | null; workspace: string };
    access: 'view' | 'add';
    columns: Column[];
    tasks: SharedTask[];
    /** Where the visitor stands with this board. */
    viewer: 'guest' | 'can_add' | 'pending' | 'approved' | 'declined' | 'can_request';
    projectUrl: string | null;
}>();

const lists = computed(() =>
    props.columns.map((column, index) => ({
        ...column,
        dot: column.is_done ? 'bg-emerald-500' : index === 0 ? 'bg-slate-400' : 'bg-sky-500',
        tasks: props.tasks.filter((task) => task.board_column_id === column.id),
    })),
);

const busy = ref(false);

function post(name: 'shared-board.join' | 'shared-board.request') {
    router.post(route(name, props.token), {}, { preserveScroll: true, onStart: () => (busy.value = true), onFinish: () => (busy.value = false) });
}
</script>

<template>
    <Head :title="`${project.name} · Shared board`" />

    <div class="bg-background text-foreground flex min-h-svh flex-col">
        <header class="flex items-center justify-between gap-3 border-b px-4 py-3 md:px-6">
            <AppLogo />
            <span class="text-muted-foreground inline-flex items-center gap-1.5 text-xs">
                <Eye class="size-3.5" />
                Shared board
            </span>
        </header>

        <main class="flex flex-1 flex-col gap-5 px-4 py-6 md:px-6">
            <div class="flex flex-col gap-4 md:flex-row md:items-end md:justify-between">
                <div class="min-w-0">
                    <p class="text-muted-foreground text-xs font-medium tracking-[0.06em] uppercase">{{ project.workspace }}</p>
                    <h1 class="mt-1 truncate text-2xl font-semibold tracking-tight">{{ project.name }}</h1>
                    <p v-if="project.description" class="text-muted-foreground mt-1 line-clamp-2 max-w-2xl text-sm">{{ project.description }}</p>
                    <p class="text-muted-foreground mt-2 inline-flex items-center gap-1.5 text-xs">
                        <Users class="size-3.5" />
                        {{
                            access === 'add'
                                ? 'Anyone with this link can view the board and add tasks.'
                                : 'Anyone with this link can view this board.'
                        }}
                    </p>
                </div>

                <!-- What the visitor can do next -->
                <div class="flex shrink-0 flex-col items-start gap-1.5 md:items-end" data-testid="shared-board-action">
                    <template v-if="viewer === 'guest'">
                        <Button as-child class="gap-1.5">
                            <Link :href="route('shared-board.sign-in', token)">
                                <LogIn class="size-4" />
                                {{ access === 'add' ? 'Sign in to add tasks' : 'Sign in to ask for access' }}
                            </Link>
                        </Button>
                        <p class="text-muted-foreground text-xs">No account yet? You can create one for free on the next screen.</p>
                    </template>

                    <template v-else-if="viewer === 'can_add'">
                        <Button v-if="access === 'add'" class="gap-1.5" :disabled="busy" @click="post('shared-board.join')">
                            <Plus class="size-4" />
                            Add a task
                        </Button>
                        <Button v-else-if="projectUrl" as-child class="gap-1.5">
                            <a :href="projectUrl"><Plus class="size-4" /> Add a task</a>
                        </Button>
                    </template>

                    <template v-else-if="viewer === 'pending'">
                        <span class="inline-flex items-center gap-1.5 rounded-lg border px-3 py-2 text-sm">
                            <Clock class="size-4 text-amber-600" />
                            Request sent. Waiting for a manager to approve it.
                        </span>
                    </template>

                    <template v-else-if="access === 'add'">
                        <Button class="gap-1.5" :disabled="busy" @click="post('shared-board.join')">
                            <Plus class="size-4" />
                            Add a task
                        </Button>
                    </template>

                    <template v-else>
                        <Button class="gap-1.5" :disabled="busy" @click="post('shared-board.request')">
                            <Send class="size-4" />
                            {{ viewer === 'declined' ? 'Ask again to add tasks' : 'Ask to add tasks' }}
                        </Button>
                        <p class="text-muted-foreground text-xs">
                            {{ viewer === 'declined' ? 'Your last request was declined.' : 'The board’s managers will get your request.' }}
                        </p>
                    </template>
                </div>
            </div>

            <div class="flex items-start gap-3 overflow-x-auto pb-3 [scrollbar-width:thin]">
                <section
                    v-for="list in lists"
                    :key="list.id"
                    class="bg-muted/40 dark:bg-muted/20 flex max-h-[calc(100dvh-14rem)] min-h-56 w-[19rem] shrink-0 flex-col rounded-2xl border"
                    :aria-label="`${list.name} list`"
                >
                    <header class="flex items-center gap-2 px-3 pt-3 pb-2">
                        <span class="size-2 shrink-0 rounded-full" :class="list.dot" aria-hidden="true" />
                        <h2 class="truncate text-[13px] font-semibold">{{ list.name }}</h2>
                        <span class="bg-background text-muted-foreground rounded-full border px-1.5 text-[11px] leading-5 font-medium tabular-nums">
                            {{ list.tasks.length }}
                        </span>
                    </header>

                    <div class="flex min-h-0 flex-1 flex-col gap-2 overflow-y-auto px-2 pb-2 [scrollbar-width:thin]">
                        <article v-for="task in list.tasks" :key="task.id" class="bg-card shrink-0 rounded-xl border p-3 shadow-xs">
                            <p class="text-sm leading-snug font-medium text-pretty" :class="list.is_done && 'text-muted-foreground'">
                                {{ task.title }}
                            </p>
                            <p v-if="task.description" class="text-muted-foreground mt-1 line-clamp-2 text-xs leading-relaxed">
                                {{ task.description }}
                            </p>

                            <div class="border-border/60 mt-3 flex items-center justify-between gap-2 border-t pt-2.5">
                                <div class="flex min-w-0 items-center gap-1.5">
                                    <AppAvatar v-if="task.assignee_name" :name="task.assignee_name" size="xs" />
                                    <span class="text-muted-foreground truncate text-[11px]">{{ task.assignee_name ?? 'Unassigned' }}</span>
                                </div>
                                <AppBadge v-if="list.is_done" variant="success" size="sm">
                                    <template #icon><CircleCheck class="size-3" /></template>
                                    Done
                                </AppBadge>
                                <AppBadge v-else-if="task.due_date" :variant="isOverdue(task, false) ? 'danger' : 'neutral'" size="sm">
                                    <template #icon><CalendarClock class="size-3" /></template>
                                    {{ isOverdue(task, false) ? 'Overdue · ' : '' }}{{ formatDueDate(task.due_date) }}
                                </AppBadge>
                            </div>
                        </article>

                        <div
                            v-if="list.tasks.length === 0"
                            class="text-muted-foreground border-border/80 flex flex-1 flex-col items-center justify-center gap-1.5 rounded-xl border border-dashed px-3 py-8 text-xs"
                        >
                            <Inbox class="size-4 opacity-50" />
                            No tasks here.
                        </div>
                    </div>
                </section>
            </div>
        </main>
    </div>
</template>
