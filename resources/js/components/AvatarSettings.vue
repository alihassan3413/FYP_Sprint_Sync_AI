<script setup lang="ts">
import { useForm, usePage } from '@inertiajs/vue3';
import { Camera, Check, ImagePlus, Loader2, Trash2, X } from 'lucide-vue-next';
import { computed, onBeforeUnmount, ref } from 'vue';

import SavedIndicator from '@/components/settings/SavedIndicator.vue';
import SettingsSection from '@/components/settings/SettingsSection.vue';
import AppAvatar from '@/components/ui/AppAvatar.vue';
import { Button } from '@/components/ui/button';
import { type SharedData, type User } from '@/types';

const ACCEPTED_TYPES = ['image/png', 'image/jpeg', 'image/webp', 'image/gif', 'image/bmp'];
const MAX_BYTES = 2 * 1024 * 1024;

const page = usePage<SharedData>();
const user = computed(() => page.props.auth.user as User);

const fileInput = ref<HTMLInputElement | null>(null);
const isDragging = ref(false);
const localError = ref<string | null>(null);
const pendingFile = ref<File | null>(null);
const previewUrl = ref<string | null>(null);

const uploadForm = useForm<{ avatar: File | null }>({ avatar: null });
const removeForm = useForm({});

const errorMessage = computed(() => localError.value ?? uploadForm.errors.avatar);
const displayedAvatar = computed(() => previewUrl.value ?? user.value.avatar_url);
const hasPendingSelection = computed(() => pendingFile.value !== null);

function openFilePicker() {
    fileInput.value?.click();
}

function resetInput() {
    if (fileInput.value) {
        fileInput.value.value = '';
    }
}

function releasePreview() {
    if (previewUrl.value !== null) {
        URL.revokeObjectURL(previewUrl.value);
        previewUrl.value = null;
    }
}

function selectFile(file: File) {
    localError.value = null;

    if (!ACCEPTED_TYPES.includes(file.type)) {
        localError.value = 'Use a PNG, JPG, WEBP or GIF image.';
        resetInput();

        return;
    }

    if (file.size > MAX_BYTES) {
        localError.value = 'That image is larger than 2 MB. Try a smaller one.';
        resetInput();

        return;
    }

    releasePreview();
    pendingFile.value = file;
    previewUrl.value = URL.createObjectURL(file);
    uploadForm.clearErrors();
}

function confirmUpload() {
    if (pendingFile.value === null || uploadForm.processing) {
        return;
    }

    uploadForm.avatar = pendingFile.value;
    uploadForm.post(route('profile.avatar.update'), {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: cancelSelection,
        onFinish: resetInput,
    });
}

function cancelSelection() {
    releasePreview();
    pendingFile.value = null;
    uploadForm.avatar = null;
    localError.value = null;
    resetInput();
}

function onFileSelected(event: Event) {
    const file = (event.target as HTMLInputElement).files?.[0];

    if (file) {
        selectFile(file);
    }
}

function onDrop(event: DragEvent) {
    isDragging.value = false;

    const file = event.dataTransfer?.files?.[0];

    if (file) {
        selectFile(file);
    }
}

function removeAvatar() {
    localError.value = null;
    removeForm.delete(route('profile.avatar.destroy'), { preserveScroll: true });
}

onBeforeUnmount(releasePreview);
</script>

<template>
    <SettingsSection :icon="Camera" title="Profile photo" description="Helps teammates recognise you across boards, meetings and comments.">
        <div
            :class="[
                'flex flex-col items-center gap-6 rounded-xl border border-dashed p-5 text-center transition-colors sm:flex-row sm:items-center sm:p-6 sm:text-left',
                isDragging ? 'border-primary bg-primary/5' : 'border-border bg-muted/20',
            ]"
            @dragover.prevent="isDragging = true"
            @dragenter.prevent="isDragging = true"
            @dragleave.prevent="isDragging = false"
            @drop.prevent="onDrop"
        >
            <input ref="fileInput" type="file" class="hidden" :accept="ACCEPTED_TYPES.join(',')" @change="onFileSelected" />

            <button
                type="button"
                class="group ring-offset-background focus-visible:ring-ring relative rounded-full focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-none"
                :aria-label="user.avatar_url ? 'Change profile photo' : 'Upload profile photo'"
                @click="openFilePicker"
            >
                <AppAvatar :name="user.name" :email="user.email" :src="displayedAvatar" size="2xl" />

                <span
                    v-if="hasPendingSelection"
                    class="ring-primary ring-offset-background pointer-events-none absolute inset-0 rounded-full ring-2 ring-offset-2"
                    aria-hidden="true"
                />

                <span
                    class="bg-foreground/55 text-background absolute inset-0 flex items-center justify-center rounded-full opacity-0 backdrop-blur-[1px] transition-opacity duration-200 group-hover:opacity-100 group-focus-visible:opacity-100"
                >
                    <Camera class="size-5" />
                </span>

                <span v-if="uploadForm.processing" class="bg-background/75 absolute inset-0 flex items-center justify-center rounded-full">
                    <Loader2 class="text-foreground size-5 animate-spin" />
                </span>
            </button>

            <div class="flex min-w-0 flex-1 flex-col items-center gap-3 sm:items-start">
                <div class="space-y-1">
                    <template v-if="hasPendingSelection">
                        <p class="text-foreground text-sm font-medium">Preview — not saved yet</p>
                        <p class="text-muted-foreground text-xs">This is how your photo will look. Save it to make the change.</p>
                    </template>
                    <template v-else>
                        <p class="text-foreground text-sm font-medium">Drag an image here, or pick one from your device</p>
                        <p class="text-muted-foreground text-xs">PNG, JPG, WEBP or GIF · up to 2 MB · square works best</p>
                    </template>
                </div>

                <div v-if="hasPendingSelection" class="flex flex-wrap items-center justify-center gap-2 sm:justify-start">
                    <Button type="button" size="sm" :disabled="uploadForm.processing" @click="confirmUpload">
                        <Loader2 v-if="uploadForm.processing" class="animate-spin" />
                        <Check v-else />
                        {{ uploadForm.processing ? 'Saving…' : 'Save photo' }}
                    </Button>

                    <Button type="button" variant="outline" size="sm" :disabled="uploadForm.processing" @click="openFilePicker">
                        <ImagePlus />
                        Choose another
                    </Button>

                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        class="text-muted-foreground"
                        :disabled="uploadForm.processing"
                        @click="cancelSelection"
                    >
                        <X />
                        Cancel
                    </Button>
                </div>

                <div v-else class="flex flex-wrap items-center justify-center gap-2 sm:justify-start">
                    <Button type="button" variant="outline" size="sm" @click="openFilePicker">
                        <ImagePlus />
                        {{ user.avatar_url ? 'Replace photo' : 'Upload photo' }}
                    </Button>

                    <Button
                        v-if="user.avatar_url"
                        type="button"
                        variant="ghost"
                        size="sm"
                        class="text-muted-foreground hover:text-destructive"
                        :disabled="removeForm.processing"
                        @click="removeAvatar"
                    >
                        <Trash2 />
                        Remove
                    </Button>
                </div>

                <div v-if="uploadForm.progress" class="bg-muted h-1 w-full max-w-56 overflow-hidden rounded-full">
                    <div
                        class="bg-foreground h-full rounded-full transition-all duration-200"
                        :style="{ width: `${uploadForm.progress.percentage}%` }"
                    />
                </div>

                <p v-if="errorMessage" class="text-destructive text-[13px] font-medium">{{ errorMessage }}</p>

                <SavedIndicator
                    :show="uploadForm.recentlySuccessful || removeForm.recentlySuccessful"
                    :label="removeForm.recentlySuccessful ? 'Photo removed' : 'Photo updated'"
                />
            </div>
        </div>
    </SettingsSection>
</template>
