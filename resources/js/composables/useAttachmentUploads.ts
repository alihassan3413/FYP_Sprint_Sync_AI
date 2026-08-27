import { DEFAULT_ATTACHMENT_LIMITS, validateAttachment, type AttachmentLimits } from '@/lib/attachments';
import { getCsrfToken } from '@/lib/csrf';
import type { SharedData } from '@/types';
import { usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

export interface UploadedAttachment {
    id: number;
    name: string;
    mime: string;
    size: number;
    width: number | null;
    height: number | null;
    url: string;
}

export interface PendingUpload {
    key: string;
    name: string;
    size: number;
    previewUrl: string | null;
    isImage: boolean;
    uploading: boolean;
    error: string | null;
    attachment: UploadedAttachment | null;
}

export function useAttachmentUploads(maxFiles: number) {
    const pending = ref<PendingUpload[]>([]);
    /** Files refused before upload — wrong type, too big, or over the count. */
    const rejections = ref<string[]>([]);

    const page = usePage<SharedData>();

    const limits = computed<AttachmentLimits>(() => page.props.attachments ?? DEFAULT_ATTACHMENT_LIMITS);

    /** `accept` for a file input, so the picker filters before anyone chooses. */
    const acceptAttribute = computed(() =>
        limits.value.allowed_extensions.length > 0 ? limits.value.allowed_extensions.map((ext) => `.${ext}`).join(',') : undefined,
    );

    const maxBytes = computed(() => limits.value.max_kilobytes * 1024);

    function dismissRejections(): void {
        rejections.value = [];
    }

    function attachmentIds(): number[] {
        return pending.value.filter((item) => item.attachment !== null).map((item) => item.attachment!.id);
    }

    function isBusy(): boolean {
        return pending.value.some((item) => item.uploading);
    }

    async function upload(file: File): Promise<void> {
        const isImage = file.type.startsWith('image/');

        const entry: PendingUpload = {
            key: `${file.name}-${file.size}-${Date.now()}-${Math.random()}`,
            name: file.name,
            size: file.size,
            previewUrl: isImage ? URL.createObjectURL(file) : null,
            isImage,
            uploading: true,
            error: null,
            attachment: null,
        };

        pending.value.push(entry);

        const body = new FormData();
        body.append('file', file);

        try {
            const response = await fetch('/attachments', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'X-XSRF-TOKEN': getCsrfToken(),
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body,
            });

            if (!response.ok) {
                const payload = await response.json().catch(() => null);
                entry.error = payload?.errors?.file?.[0] ?? payload?.message ?? 'Upload failed.';

                return;
            }

            entry.attachment = (await response.json()) as UploadedAttachment;
        } catch {
            entry.error = 'Upload failed.';
        } finally {
            entry.uploading = false;
        }
    }

    async function add(files: FileList | File[]): Promise<void> {
        rejections.value = [];

        const incoming = Array.from(files);
        const accepted: File[] = [];

        for (const file of incoming) {
            const problem = validateAttachment(file, limits.value);

            if (problem !== null) {
                rejections.value.push(problem);

                continue;
            }

            accepted.push(file);
        }

        const room = maxFiles - pending.value.length;

        if (accepted.length > room) {
            const dropped = accepted.length - Math.max(room, 0);
            rejections.value.push(`Only ${maxFiles} files can be attached, so ${dropped} were left out.`);
        }

        if (room <= 0) return;

        await Promise.all(accepted.slice(0, room).map((file) => upload(file)));
    }

    function remove(key: string): void {
        const entry = pending.value.find((item) => item.key === key);

        if (entry?.previewUrl) {
            URL.revokeObjectURL(entry.previewUrl);
        }

        pending.value = pending.value.filter((item) => item.key !== key);
    }

    function clear(): void {
        pending.value.forEach((item) => item.previewUrl && URL.revokeObjectURL(item.previewUrl));
        pending.value = [];
        rejections.value = [];
    }

    return { pending, rejections, limits, acceptAttribute, maxBytes, attachmentIds, isBusy, add, remove, clear, dismissRejections };
}
