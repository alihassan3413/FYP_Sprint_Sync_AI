export interface CommentAttachment {
    id: number;
    name: string;
    mime: string;
    size: number;
    width: number | null;
    height: number | null;
    url: string;
    is_image: boolean;
}

export interface AttachmentLimits {
    max_kilobytes: number;
    allowed_extensions: string[];
    max_per_task: number;
    max_per_comment: number;
}

/**
 * Used when the shared props have not arrived (or on a page rendered without
 * them). Deliberately permissive on type — the server allowlist is the real
 * gate, and guessing wrong here would block a file that is actually fine.
 */
export const DEFAULT_ATTACHMENT_LIMITS: AttachmentLimits = {
    max_kilobytes: 10240,
    allowed_extensions: [],
    max_per_task: 10,
    max_per_comment: 6,
};

export function extensionOf(filename: string): string {
    const dot = filename.lastIndexOf('.');

    return dot === -1 ? '' : filename.slice(dot + 1).toLowerCase();
}

/**
 * Mirrors StoreAttachmentRequest so an oversized or unsupported file is
 * refused instantly instead of after a long upload that ends in a 422.
 * Returns null when the file is acceptable.
 */
export function validateAttachment(file: File, limits: AttachmentLimits): string | null {
    if (file.size > limits.max_kilobytes * 1024) {
        return `${file.name} is ${formatBytes(file.size)} — the limit is ${formatBytes(limits.max_kilobytes * 1024)}.`;
    }

    if (file.size === 0) {
        return `${file.name} is empty.`;
    }

    const extension = extensionOf(file.name);

    if (limits.allowed_extensions.length > 0 && !limits.allowed_extensions.includes(extension)) {
        return `${extension ? `.${extension} files` : 'That file type'} cannot be attached.`;
    }

    return null;
}

export function formatBytes(bytes: number): string {
    if (bytes < 1024) return `${bytes} B`;
    if (bytes < 1024 * 1024) return `${Math.round(bytes / 1024)} KB`;

    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}
