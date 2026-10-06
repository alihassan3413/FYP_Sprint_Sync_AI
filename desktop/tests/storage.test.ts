import { existsSync, readFileSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';

import { describe, expect, it } from 'vitest';

import { LocalStore } from '../src/main/local-store';
import { SafeStorageSecretStore, type SafeStorageLike } from '../src/main/secure-store';
import { tempFolder } from './fixtures';

/** Stands in for the OS keychain: reversible, but not plain text. */
function fakeSafeStorage(backend = 'keychain', available = true): SafeStorageLike {
    return {
        isEncryptionAvailable: () => available,
        encryptString: (plain) => Buffer.from(plain.split('').reverse().join(''), 'utf8'),
        decryptString: (data) => data.toString('utf8').split('').reverse().join(''),
        getSelectedStorageBackend: () => backend,
    };
}

describe('local state and the offline queue', () => {
    it('survives a restart', () => {
        const folder = tempFolder();
        const store = LocalStore.inFolder(folder);
        store.enqueue({ id: 'op-1', action: 'stop', workspace: 'w', session: 's', occurredAt: '2026-10-12T14:00:00Z', createdAt: '2026-10-12T14:00:00Z' });
        store.update({ selection: { project: 'p', task: null } });

        const reopened = LocalStore.inFolder(folder);
        expect(reopened.get().queue.map((op) => op.id)).toEqual(['op-1']);
        expect(reopened.get().selection.project).toBe('p');

        reopened.dequeue('op-1');
        expect(LocalStore.inFolder(folder).get().queue).toEqual([]);
    });

    it('writes atomically and never leaves a temporary file', () => {
        const folder = tempFolder();
        LocalStore.inFolder(folder).update({ workspace: 'w' });

        expect(existsSync(join(folder, 'sprintsync-state.json.tmp'))).toBe(false);
    });

    it('starts empty from a damaged file instead of crashing', () => {
        const folder = tempFolder();
        writeFileSync(join(folder, 'sprintsync-state.json'), '{"queue": [');

        expect(LocalStore.inFolder(folder).get().queue).toEqual([]);
    });

    it('never holds the token', () => {
        const folder = tempFolder();
        const secrets = SafeStorageSecretStore.inFolder(fakeSafeStorage(), folder, 'darwin');
        secrets.write('ssd_secret-token');
        LocalStore.inFolder(folder).update({ workspace: 'w' });

        expect(readFileSync(join(folder, 'sprintsync-state.json'), 'utf8')).not.toContain('ssd_secret-token');
    });
});

describe('secure token storage', () => {
    it('keeps only encrypted bytes on disk and reads them back after a restart', () => {
        const folder = tempFolder();
        SafeStorageSecretStore.inFolder(fakeSafeStorage(), folder, 'darwin').write('ssd_secret-token');

        expect(readFileSync(join(folder, 'sprintsync-session.bin'), 'utf8')).not.toContain('ssd_secret-token');
        expect(SafeStorageSecretStore.inFolder(fakeSafeStorage(), folder, 'darwin').read()).toBe('ssd_secret-token');
    });

    it('forgets the token on sign out', () => {
        const folder = tempFolder();
        const secrets = SafeStorageSecretStore.inFolder(fakeSafeStorage(), folder, 'darwin');
        secrets.write('ssd_secret-token');
        secrets.clear();

        expect(secrets.read()).toBeNull();
        expect(existsSync(join(folder, 'sprintsync-session.bin'))).toBe(false);
    });

    it('does not write the token to disk when Linux has no keyring', () => {
        const folder = tempFolder();
        const secrets = SafeStorageSecretStore.inFolder(fakeSafeStorage('basic_text'), folder, 'linux');
        secrets.write('ssd_secret-token');

        expect(secrets.isAvailable()).toBe(false);
        expect(secrets.read()).toBe('ssd_secret-token');
        expect(existsSync(join(folder, 'sprintsync-session.bin'))).toBe(false);
    });

    it('does not write the token when the OS offers no encryption', () => {
        const folder = tempFolder();
        SafeStorageSecretStore.inFolder(fakeSafeStorage('keychain', false), folder, 'win32').write('ssd_secret-token');

        expect(existsSync(join(folder, 'sprintsync-session.bin'))).toBe(false);
    });
});
