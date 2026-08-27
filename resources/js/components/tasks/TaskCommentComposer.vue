<script setup lang="ts">
import { Loader2, Paperclip, Send, X } from 'lucide-vue-next';

import { useAttachmentUploads } from '@/composables/useAttachmentUploads';
import { formatBytes } from '@/lib/attachments';

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

const MAX_ATTACHMENTS = 6;

const fileInput = ref<HTMLInputElement | null>(null);
const { pending, attachmentIds, isBusy, add, remove, clear } = useAttachmentUploads(MAX_ATTACHMENTS);

const form = useForm<{ body: string; attachment_ids: number[] }>({ body: '', attachment_ids: [] });

const canSubmit = computed(() => !form.processing && !isBusy() && (form.body.trim().length > 0 || attachmentIds().length > 0));

function pickFiles(event: Event) {
    const input = event.target as HTMLInputElement;

    if (input.files) {
        void add(input.files);
    }

    input.value = '';
}

function onPaste(event: ClipboardEvent) {
    const files = Array.from(event.clipboardData?.files ?? []);

    if (files.length > 0) {
        event.preventDefault();
        void add(files);
    }
}

function onDrop(event: DragEvent) {
    const files = event.dataTransfer?.files;

    if (files && files.length > 0) {
        void add(files);
    }
}

function submit() {
    if (!canSubmit.value) return;

    form.attachment_ids = attachmentIds();

    form.post(workspaceRoute('workspace.projects.tasks.comments.store', { project: props.projectId, task: props.taskId }), {
        preserveScroll: true,
        onSuccess: () => {
            form.reset();
            clear();
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
        class="bg-muted/20 focus-within:border-ring/50 focus-within:ring-ring/25 rounded-xl border transition-shadow focus-within:ring-2"
        @submit.prevent="submit"
        @dragover.prevent
        @drop.prevent="onDrop"
    >
        <div v-if="pending.length > 0" class="flex flex-wrap gap-2 border-b p-2.5">
            <div v-for="item in pending" :key="item.key" class="bg-background relative flex items-center gap-2 rounded-lg border p-1.5 pr-7">
                <img v-if="item.isImage && item.previewUrl" :src="item.previewUrl" :alt="item.name" class="size-9 rounded object-cover" />
                <span v-else class="bg-muted grid size-9 place-items-center rounded">
                    <Paperclip class="text-muted-foreground size-4" />
                </span>

                <span class="min-w-0">
                    <span class="block max-w-[9rem] truncate text-[12px] font-medium">{{ item.name }}</span>
                    <span v-if="item.error" class="text-destructive block text-[11px]">{{ item.error }}</span>
                    <span v-else-if="item.uploading" class="text-muted-foreground block text-[11px]">Uploading…</span>
                    <span v-else class="text-muted-foreground block text-[11px]">{{ formatBytes(item.size) }}</span>
                </span>

                <button
                    type="button"
                    class="text-muted-foreground hover:text-foreground absolute top-1 right-1 rounded p-0.5"
                    :aria-label="`Remove ${item.name}`"
                    @click="remove(item.key)"
                >
                    <X class="size-3.5" />
                </button>
            </div>
        </div>

        <div class="flex items-center gap-2 py-1.5 pr-1.5 pl-2">
            <AppAvatar :name="currentUserName" size="sm" class="shrink-0" />

            <Textarea
                v-model="form.body"
                placeholder="Add a comment…"
                rows="1"
                class="min-h-8 flex-1 resize-none border-0 bg-transparent px-1 py-1.5 text-sm shadow-none focus-visible:ring-0"
                :disabled="form.processing"
                @paste="onPaste"
                @keydown.enter.exact.prevent="submit"
            />

            <input
                ref="fileInput"
                type="file"
                multiple
                class="hidden"
                :accept="'image/*,.pdf,.txt,.csv,.doc,.docx,.xls,.xlsx,.zip'"
                @change="pickFiles"
            />

            <Button
                type="button"
                size="icon"
                variant="ghost"
                class="size-8 shrink-0 rounded-lg"
                :disabled="form.processing || pending.length >= MAX_ATTACHMENTS"
                aria-label="Attach a file"
                @click="fileInput?.click()"
            >
                <Paperclip class="size-4" />
            </Button>

            <Button type="submit" size="icon" class="size-8 shrink-0 rounded-lg" :disabled="!canSubmit" aria-label="Send comment">
                <Loader2 v-if="form.processing" class="size-4 animate-spin" />
                <Send v-else class="size-4" />
            </Button>
        </div>
    </form>
</template>
