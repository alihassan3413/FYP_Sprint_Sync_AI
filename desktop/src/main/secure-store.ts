import { existsSync, readFileSync, rmSync } from 'node:fs';
import { join } from 'node:path';

import { writeJsonDurably } from './durable-file';

/**
 * Where the sign-in token is kept between launches. It never reaches the
 * window, logs or the local state file.
 */
export interface SecretStore {
    /** False when the OS offers no real protection; the token then lives in memory only. */
    isAvailable(): boolean;
    read(): string | null;
    write(secret: string): void;
    clear(): void;
}

/** The subset of Electron's safeStorage used here (injected so it can be tested). */
export interface SafeStorageLike {
    isEncryptionAvailable(): boolean;
    encryptString(plain: string): Buffer;
    decryptString(encrypted: Buffer): string;
    getSelectedStorageBackend?(): string;
}

/**
 * Electron safeStorage: the token is encrypted with a key held by the OS —
 * the macOS Keychain, Windows DPAPI (tied to the Windows account), or on
 * Linux the Secret Service / KWallet. Only the encrypted bytes are written to
 * disk. If Linux has no keyring, safeStorage would fall back to a hard-coded
 * key ("basic_text"); that is not protection, so we refuse to persist and
 * keep the token in memory for this session instead.
 */
export class SafeStorageSecretStore implements SecretStore {
    private memory: string | null = null;

    constructor(
        private readonly safeStorage: SafeStorageLike,
        private readonly path: string,
        private readonly platform: NodeJS.Platform,
    ) {}

    static inFolder(safeStorage: SafeStorageLike, folder: string, platform: NodeJS.Platform): SafeStorageSecretStore {
        return new SafeStorageSecretStore(safeStorage, join(folder, 'sprintsync-session.bin'), platform);
    }

    isAvailable(): boolean {
        if (!this.safeStorage.isEncryptionAvailable()) return false;

        return !(this.platform === 'linux' && this.safeStorage.getSelectedStorageBackend?.() === 'basic_text');
    }

    read(): string | null {
        if (this.memory !== null) return this.memory;
        if (!this.isAvailable() || !existsSync(this.path)) return null;

        try {
            const { data } = JSON.parse(readFileSync(this.path, 'utf8')) as { data: string };

            return (this.memory = this.safeStorage.decryptString(Buffer.from(data, 'base64')));
        } catch {
            this.clear();

            return null;
        }
    }

    write(secret: string): void {
        this.memory = secret;

        if (this.isAvailable()) {
            writeJsonDurably(this.path, { data: this.safeStorage.encryptString(secret).toString('base64') });
        }
    }

    clear(): void {
        this.memory = null;
        rmSync(this.path, { force: true });
    }
}
