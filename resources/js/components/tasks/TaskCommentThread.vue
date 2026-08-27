<script setup lang="ts">
import { Loader2, MessageSquare, Paperclip, Trash2 } from 'lucide-vue-next';

import { formatBytes } from '@/lib/attachments';

import type { TaskComment } from '@/lib/tasks';

const props = defineProps<{
    comments: TaskComment[];
    projectId: number;
    taskId: number;
    currentUserId: number;
    canModerate: boolean;
}>();

const { workspaceRoute } = useCurrentWorkspace();
const notify = useNotificationStore();

const localComments = ref<TaskComment[]>([...props.comments]);

watch(
    () => props.comments,
    (comments) => {
        localComments.value = [...comments];
    },
);

const listRef = ref<HTMLElement | null>(null);

function scrollToBottom() {
    nextTick(() => {
        if (listRef.value) {
            listRef.value.scrollTop = listRef.value.scrollHeight;
        }
    });
}

defineExpose({ scrollToBottom });

const deletingId = ref<number | null>(null);

function remove(comment: TaskComment) {
    deletingId.value = comment.id;

    router.delete(
        workspaceRoute('workspace.projects.tasks.comments.destroy', { project: props.projectId, task: props.taskId, comment: comment.id }),
        {
            preserveScroll: true,
            onError: () => {
                notify.error("Couldn't delete that comment. Please try again.");
            },
            onFinish: () => {
                deletingId.value = null;
            },
        },
    );
}

function canDelete(comment: TaskComment): boolean {
    return props.canModerate || comment.user_id === props.currentUserId;
}

function formatTimestamp(iso: string): string {
    return new Date(iso).toLocaleString(undefined, { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' });
}
</script>

<template>
    <div>
        <div class="mb-4 flex items-center justify-between">
            <h3 class="text-sm font-semibold">Discussion</h3>
            <span v-if="localComments.length > 0" class="text-muted-foreground text-xs tabular-nums">{{ localComments.length }}</span>
        </div>

        <div
            v-if="localComments.length === 0"
            class="flex flex-col items-center justify-center gap-2 rounded-lg border border-dashed py-10 text-center"
        >
            <MessageSquare class="text-muted-foreground/30 size-6" />
            <p class="text-muted-foreground text-xs">No comments yet — start the conversation.</p>
        </div>

        <div v-else ref="listRef" class="max-h-[420px] space-y-5 overflow-y-auto pr-1">
            <div v-for="comment in localComments" :key="comment.id" class="group flex items-start gap-3">
                <AppAvatar :name="comment.user_name" size="sm" class="mt-0.5 shrink-0" />

                <div class="min-w-0 flex-1 border-b pb-5 last:border-b-0 last:pb-0">
                    <div class="flex items-center gap-2">
                        <span class="text-foreground text-sm font-semibold">{{ comment.user_name }}</span>
                        <span class="text-muted-foreground text-xs">{{ formatTimestamp(comment.created_at) }}</span>

                        <button
                            v-if="canDelete(comment)"
                            type="button"
                            class="text-muted-foreground hover:text-destructive hover:bg-muted ml-auto shrink-0 rounded-md p-1 opacity-0 transition-opacity group-hover:opacity-100"
                            :disabled="deletingId === comment.id"
                            aria-label="Delete comment"
                            @click="remove(comment)"
                        >
                            <Loader2 v-if="deletingId === comment.id" class="size-3.5 animate-spin" />
                            <Trash2 v-else class="size-3.5" />
                        </button>
                    </div>
                    <p v-if="comment.body" class="text-foreground/90 mt-1.5 text-sm leading-relaxed break-words">{{ comment.body }}</p>

                    <div v-if="comment.attachments?.length" class="mt-2.5 flex flex-wrap gap-2">
                        <template v-for="attachment in comment.attachments" :key="attachment.id">
                            <a
                                v-if="attachment.is_image"
                                :href="attachment.url"
                                target="_blank"
                                rel="noopener"
                                class="border-border/70 hover:border-foreground/25 block overflow-hidden rounded-lg border transition-colors"
                            >
                                <img :src="attachment.url" :alt="attachment.name" loading="lazy" class="max-h-44 w-auto object-cover" />
                            </a>

                            <a
                                v-else
                                :href="attachment.url"
                                target="_blank"
                                rel="noopener"
                                class="bg-muted/30 border-border/70 hover:border-foreground/25 flex items-center gap-2 rounded-lg border p-2 pr-3 transition-colors"
                            >
                                <span class="bg-background grid size-9 place-items-center rounded">
                                    <Paperclip class="text-muted-foreground size-4" />
                                </span>
                                <span class="min-w-0">
                                    <span class="block max-w-[12rem] truncate text-[12.5px] font-medium">{{ attachment.name }}</span>
                                    <span class="text-muted-foreground block text-[11px]">{{ formatBytes(attachment.size) }}</span>
                                </span>
                            </a>
                        </template>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
