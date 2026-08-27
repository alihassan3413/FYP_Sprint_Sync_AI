<script setup lang="ts">
import { File, FileArchive, FileSpreadsheet, FileText, Film, ImageIcon, Loader2, Music, Paperclip, Upload, X } from 'lucide-vue-next';

import { useAttachmentUploads } from '@/composables/useAttachmentUploads';
import { extensionOf, formatBytes } from '@/lib/attachments';

/**
 * Drag-and-drop file picker over {@see useAttachmentUploads}.
 *
 * Files upload the moment they are chosen and are held unclaimed until the
 * surrounding form is submitted with their ids, so an abandoned form leaves
 * nothing behind but rows the pruner sweeps up.
 */

const props = withDefaults(
    defineProps<{
        maxFiles: number;
        disabled?: boolean;
        /** Listen for pastes anywhere in the document while mounted. */
        capturePaste?: boolean;
    }>(),
    { disabled: false, capturePaste: false },
);

const { pending, rejections, limits, acceptAttribute, maxBytes, attachmentIds, isBusy, add, remove, clear, dismissRejections } =
    useAttachmentUploads(props.maxFiles);

const fileInput = ref<HTMLInputElement | null>(null);
const isDraggingOver = ref(false);
/** Nested children fire dragleave on every hop; count depth instead. */
let dragDepth = 0;

const remaining = computed(() => props.maxFiles - pending.value.length);
const isFull = computed(() => remaining.value <= 0);

const uploadedCount = computed(() => pending.value.filter((item) => item.attachment !== null).length);

/** A short, human list rather than the full 40-extension allowlist. */
const summary = computed(() => `Images, PDF, Office docs, CSV, audio, video, archives — up to ${formatBytes(maxBytes.value)} each`);

function pickFiles(event: Event) {
    const input = event.target as HTMLInputElement;

    if (input.files) {
        void add(input.files);
    }

    input.value = '';
}

function onDrop(event: DragEvent) {
    dragDepth = 0;
    isDraggingOver.value = false;

    if (props.disabled) return;

    const files = event.dataTransfer?.files;

    if (files && files.length > 0) {
        void add(files);
    }
}

function onDragEnter() {
    if (props.disabled) return;

    dragDepth += 1;
    isDraggingOver.value = true;
}

function onDragLeave() {
    dragDepth = Math.max(0, dragDepth - 1);

    if (dragDepth === 0) {
        isDraggingOver.value = false;
    }
}

function onPaste(event: ClipboardEvent) {
    if (props.disabled || isFull.value) return;

    const files = Array.from(event.clipboardData?.files ?? []);

    if (files.length > 0) {
        event.preventDefault();
        void add(files);
    }
}

if (props.capturePaste) {
    onMounted(() => document.addEventListener('paste', onPaste));
    onBeforeUnmount(() => document.removeEventListener('paste', onPaste));
}

/** Icon by family, so a spreadsheet does not look like a video. */
function iconFor(item: { isImage: boolean; name: string }) {
    if (item.isImage) return ImageIcon;

    const extension = extensionOf(item.name);

    if (['pdf', 'doc', 'docx', 'odt', 'rtf', 'txt', 'md'].includes(extension)) return FileText;
    if (['csv', 'tsv', 'xls', 'xlsx', 'ods'].includes(extension)) return FileSpreadsheet;
    if (['zip', '7z', 'gz', 'tar'].includes(extension)) return FileArchive;
    if (['mp4', 'mov', 'webm'].includes(extension)) return Film;
    if (['mp3', 'wav', 'm4a', 'ogg'].includes(extension)) return Music;

    return File;
}

defineExpose({ attachmentIds, isBusy, clear, pending });
</script>

<template>
    <div class="grid gap-2">
        <div class="flex items-center justify-between">
            <Label class="text-sm font-medium">
                Attachments <span class="text-muted-foreground font-normal">(optional)</span>
            </Label>

            <span v-if="pending.length > 0" class="text-muted-foreground text-xs tabular-nums">
                {{ pending.length }} / {{ maxFiles }}
            </span>
        </div>

        <div
            :class="[
                'relative rounded-xl border border-dashed transition-colors',
                isDraggingOver ? 'border-primary bg-primary/5' : 'border-input bg-muted/20',
                disabled && 'pointer-events-none opacity-60',
            ]"
            @dragover.prevent
            @dragenter.prevent="onDragEnter"
            @dragleave.prevent="onDragLeave"
            @drop.prevent="onDrop"
        >
            <button
                v-if="pending.length === 0"
                type="button"
                class="flex w-full flex-col items-center gap-1.5 px-4 py-6 text-center"
                :disabled="disabled"
                @click="fileInput?.click()"
            >
                <span class="bg-background grid size-9 place-items-center rounded-full border">
                    <Upload class="text-muted-foreground size-4" />
                </span>
                <span class="text-sm font-medium">
                    Drop files here or <span class="text-primary-text underline-offset-2 hover:underline">browse</span>
                </span>
                <span class="text-muted-foreground text-xs">{{ summary }}</span>
            </button>

            <div v-else class="p-2.5">
                <ul class="grid gap-2 sm:grid-cols-2">
                    <li
                        v-for="item in pending"
                        :key="item.key"
                        :class="[
                            'bg-background relative flex items-center gap-2.5 rounded-lg border p-2 pr-8',
                            item.error && 'border-destructive/50',
                        ]"
                    >
                        <img
                            v-if="item.isImage && item.previewUrl"
                            :src="item.previewUrl"
                            :alt="item.name"
                            class="size-10 shrink-0 rounded object-cover"
                        />
                        <span v-else class="bg-muted grid size-10 shrink-0 place-items-center rounded">
                            <component :is="iconFor(item)" class="text-muted-foreground size-4" />
                        </span>

                        <span class="min-w-0 flex-1">
                            <span class="block truncate text-[13px] font-medium" :title="item.name">{{ item.name }}</span>

                            <span v-if="item.error" class="text-destructive block text-[11px]">{{ item.error }}</span>
                            <span v-else-if="item.uploading" class="text-muted-foreground flex items-center gap-1 text-[11px]">
                                <Loader2 class="size-3 animate-spin" /> Uploading…
                            </span>
                            <span v-else class="text-muted-foreground block text-[11px]">{{ formatBytes(item.size) }}</span>
                        </span>

                        <button
                            type="button"
                            class="text-muted-foreground hover:text-foreground absolute top-1.5 right-1.5 rounded p-0.5 transition-colors"
                            :aria-label="`Remove ${item.name}`"
                            @click="remove(item.key)"
                        >
                            <X class="size-3.5" />
                        </button>
                    </li>
                </ul>

                <button
                    v-if="!isFull"
                    type="button"
                    class="text-muted-foreground hover:text-foreground mt-2 flex w-full items-center justify-center gap-1.5 rounded-lg border border-dashed py-2 text-xs font-medium transition-colors"
                    :disabled="disabled"
                    @click="fileInput?.click()"
                >
                    <Paperclip class="size-3.5" /> Add {{ remaining === 1 ? 'one more file' : `up to ${remaining} more` }}
                </button>

                <p v-else class="text-muted-foreground mt-2 text-center text-xs">
                    That is the maximum of {{ maxFiles }} files.
                </p>
            </div>

            <input ref="fileInput" type="file" multiple class="hidden" :accept="acceptAttribute" @change="pickFiles" />
        </div>

        <!-- Files refused before upload. Dismissible so they do not linger
             over a form the user has already corrected. -->
        <div v-if="rejections.length > 0" class="border-destructive/40 bg-destructive/5 rounded-lg border px-3 py-2">
            <div class="flex items-start justify-between gap-2">
                <ul class="text-destructive grid gap-0.5 text-xs">
                    <li v-for="(message, index) in rejections" :key="index">{{ message }}</li>
                </ul>

                <button
                    type="button"
                    class="text-destructive/70 hover:text-destructive shrink-0 rounded p-0.5"
                    aria-label="Dismiss upload warnings"
                    @click="dismissRejections"
                >
                    <X class="size-3.5" />
                </button>
            </div>
        </div>

        <p v-if="pending.length > 0" class="text-muted-foreground text-xs">
            {{ uploadedCount }} of {{ pending.length }} ready · {{ summary }}
        </p>
    </div>
</template>
