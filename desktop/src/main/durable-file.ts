import { closeSync, fsyncSync, openSync, readFileSync, renameSync, writeSync } from 'node:fs';
import { dirname } from 'node:path';

/**
 * Writes JSON so a crash or power cut leaves either the old file or the new
 * one, never half of each: write a temporary file, flush it to disk, then
 * rename it over the original (atomic on the same disk) and flush the folder.
 */
export function writeJsonDurably(path: string, data: unknown): void {
    const temporary = `${path}.tmp`;
    const fd = openSync(temporary, 'w', 0o600);

    try {
        writeSync(fd, JSON.stringify(data));
        fsyncSync(fd);
    } finally {
        closeSync(fd);
    }

    renameSync(temporary, path);

    try {
        const folder = openSync(dirname(path), 'r');
        fsyncSync(folder);
        closeSync(folder);
    } catch {
        // Some platforms (Windows) can't open folders; the rename is still atomic there.
    }
}

export function readJson<T>(path: string, fallback: T): T {
    try {
        return JSON.parse(readFileSync(path, 'utf8')) as T;
    } catch {
        return fallback;
    }
}
