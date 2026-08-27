<script setup lang="ts">
import type { Sprint } from '@/lib/sprints';
import { CalendarDays, Loader2, UserRound } from 'lucide-vue-next';

import type { TaskMember } from '@/lib/tasks';
import type { SharedData } from '@/types';

const props = defineProps<{
    sprints?: Sprint[];
    open: boolean;
    projectId: number;
    members: TaskMember[];
}>();

const emit = defineEmits<{
    'update:open': [value: boolean];
    created: [];
}>();

const { workspaceRoute } = useCurrentWorkspace();
const page = usePage<SharedData>();

const TITLE_MAX = 150;
const DESCRIPTION_MAX = 5000;

const maxAttachments = computed(() => page.props.attachments?.max_per_task ?? 10);

const picker = ref<{ attachmentIds: () => number[]; isBusy: () => boolean; clear: () => void } | null>(null);

const form = useForm<{
    title: string;
    description: string;
    assigned_to: number | null;
    due_date: string;
    sprint_id: string;
    attachment_ids: number[];
}>({
    title: '',
    description: '',
    assigned_to: null,
    due_date: '',
    sprint_id: '',
    attachment_ids: [],
});

const titleLength = computed(() => form.title.trim().length);
const isUploading = computed(() => picker.value?.isBusy() ?? false);

const canSubmit = computed(() => !form.processing && !isUploading.value && titleLength.value >= 2);

/** Today, so the date picker cannot offer a due date already in the past. */
const today = new Date().toISOString().slice(0, 10);

const currentSprintId = computed(() => props.sprints?.find((sprint) => sprint.is_current)?.id ?? null);

function submit() {
    if (!canSubmit.value) return;

    form.attachment_ids = picker.value?.attachmentIds() ?? [];

    form.post(workspaceRoute('workspace.projects.tasks.store', { project: props.projectId }), {
        preserveScroll: true,
        onSuccess: () => {
            emit('created');
            reset();
            emit('update:open', false);
        },
    });
}

function reset() {
    form.reset();
    form.clearErrors();
    picker.value?.clear();
}

function handleClose(value: boolean) {
    if (form.processing) return;

    if (!value) {
        reset();
    }

    emit('update:open', value);
}

/** Long forms push the button off screen; keep the keyboard shortcut honest. */
function onKeydown(event: KeyboardEvent) {
    if ((event.metaKey || event.ctrlKey) && event.key === 'Enter') {
        event.preventDefault();
        submit();
    }
}
</script>

<template>
    <AppModal
        :open="open"
        title="New task"
        description="Add a task to this project's board."
        size="xl"
        :close-on-overlay-click="!form.processing && !isUploading"
        @update:open="handleClose"
    >
        <form id="create-task-form" class="space-y-5 pt-1" @submit.prevent="submit" @keydown="onKeydown">
            <div class="grid gap-1.5">
                <div class="flex items-baseline justify-between">
                    <Label for="task-title" class="text-sm font-medium"> Title <span class="text-red-500" aria-hidden="true">*</span> </Label>
                    <span :class="['text-xs tabular-nums', form.title.length > TITLE_MAX ? 'text-destructive' : 'text-muted-foreground']">
                        {{ form.title.length }}/{{ TITLE_MAX }}
                    </span>
                </div>

                <Input
                    id="task-title"
                    v-model="form.title"
                    placeholder="e.g. Wireframe the onboarding flow"
                    class="h-10 text-[15px]"
                    autocomplete="off"
                    autofocus
                    required
                />
                <InputError :message="form.errors.title" />
            </div>

            <div class="grid gap-1.5">
                <div class="flex items-baseline justify-between">
                    <Label for="task-description" class="text-sm font-medium">
                        Description <span class="text-muted-foreground font-normal">(optional)</span>
                    </Label>
                    <span
                        v-if="form.description.length > 0"
                        :class="['text-xs tabular-nums', form.description.length > DESCRIPTION_MAX ? 'text-destructive' : 'text-muted-foreground']"
                    >
                        {{ form.description.length }}/{{ DESCRIPTION_MAX }}
                    </span>
                </div>

                <Textarea
                    id="task-description"
                    v-model="form.description"
                    placeholder="Any context teammates should know?"
                    rows="4"
                    class="resize-y"
                />
                <InputError :message="form.errors.description" />
            </div>

            <!-- Three equal columns so the row reads as one band of metadata
                 rather than the ragged two-column grid it used to be. -->
            <div class="grid gap-4 sm:grid-cols-3">
                <div class="grid gap-1.5">
                    <Label class="flex items-center gap-1.5 text-sm font-medium">
                        <UserRound class="text-muted-foreground size-3.5" /> Assignee
                    </Label>
                    <AssigneePicker v-model="form.assigned_to" :members="members" />
                    <InputError :message="form.errors.assigned_to" />
                </div>

                <div class="grid gap-1.5">
                    <Label for="task-due-date" class="flex items-center gap-1.5 text-sm font-medium">
                        <CalendarDays class="text-muted-foreground size-3.5" /> Due date
                    </Label>
                    <Input id="task-due-date" v-model="form.due_date" type="date" :min="today" class="h-9" />
                    <InputError :message="form.errors.due_date" />
                </div>

                <div class="grid gap-1.5">
                    <Label for="task-sprint" class="text-sm font-medium">Sprint</Label>
                    <select
                        id="task-sprint"
                        v-model="form.sprint_id"
                        class="border-input bg-muted/40 focus:bg-background focus:ring-ring/40 h-9 rounded-lg border px-3 text-sm transition-colors focus:ring-2 focus:outline-none"
                    >
                        <option value="">No sprint</option>
                        <option v-for="sprint in sprints" :key="sprint.id" :value="String(sprint.id)">
                            {{ sprint.name }}{{ sprint.id === currentSprintId ? ' (current)' : '' }}
                        </option>
                    </select>
                    <InputError :message="form.errors.sprint_id" />
                </div>
            </div>

            <AppAttachmentPicker ref="picker" :max-files="maxAttachments" :disabled="form.processing" />
            <InputError :message="form.errors.attachment_ids" />
        </form>

        <template #footer>
            <span class="text-muted-foreground mr-auto hidden text-xs sm:block">
                <kbd class="bg-muted rounded border px-1 py-0.5 font-sans text-[10px]">⌘</kbd>
                +
                <kbd class="bg-muted rounded border px-1 py-0.5 font-sans text-[10px]">Enter</kbd>
                to create
            </span>

            <Button type="button" variant="outline" :disabled="form.processing" @click="handleClose(false)">Cancel</Button>

            <Button type="submit" form="create-task-form" :disabled="!canSubmit">
                <Loader2 v-if="form.processing" class="mr-2 h-4 w-4 animate-spin" />
                {{ form.processing ? 'Creating…' : isUploading ? 'Uploading…' : 'Create task' }}
            </Button>
        </template>
    </AppModal>
</template>
