<script setup lang="ts">
import { Loader2, Send } from 'lucide-vue-next';

const props = defineProps<{
    projectId: number;
    taskId: number;
    currentUserName: string;
}>();

const emit = defineEmits<{
    (e: 'posted'): void;
}>();

const { workspaceRoute } = useCurrentWorkspace();
const notify = useNotificationStore();

const form = useForm<{ body: string }>({ body: '' });

function submit() {
    if (form.body.trim().length === 0 || form.processing) return;

    form.post(workspaceRoute('workspace.projects.tasks.comments.store', { project: props.projectId, task: props.taskId }), {
        preserveScroll: true,
        onSuccess: () => {
            form.reset();
            emit('posted');
        },
        onError: () => {
            notify.error("Couldn't post your comment. Please try again.");
        },
    });
}
</script>

<template>
    <form
        class="bg-muted/20 focus-within:border-ring/50 focus-within:ring-ring/25 flex items-center gap-2 rounded-xl border py-1.5 pr-1.5 pl-2 transition-shadow focus-within:ring-2"
        @submit.prevent="submit"
    >
        <AppAvatar :name="currentUserName" size="sm" class="shrink-0" />

        <Textarea
            v-model="form.body"
            placeholder="Add a comment…"
            rows="1"
            class="min-h-8 flex-1 resize-none border-0 bg-transparent px-1 py-1.5 text-sm shadow-none focus-visible:ring-0"
            :disabled="form.processing"
            @keydown.enter.exact.prevent="submit"
        />

        <Button
            type="submit"
            size="icon"
            class="size-8 shrink-0 rounded-lg"
            :disabled="form.processing || form.body.trim().length === 0"
            aria-label="Send comment"
        >
            <Loader2 v-if="form.processing" class="size-4 animate-spin" />
            <Send v-else class="size-4" />
        </Button>
    </form>
</template>
