import { describe, expect, it } from 'vitest';

import { DEV_DEFAULT_URL, resolveChannel, resolveServerUrl } from '../src/main/config';

describe('server address', () => {
    it('defaults to the local QA server in development', () => {
        expect(resolveServerUrl(undefined, false)).toBe(DEV_DEFAULT_URL);
    });

    it('allows plain http only for this computer, only in development', () => {
        expect(resolveServerUrl('http://localhost:8124', false)).toBe('http://localhost:8124');
        expect(() => resolveServerUrl('http://sprintsync.example.com', false)).toThrow();
        expect(() => resolveServerUrl('http://127.0.0.1:8124', true)).toThrow('HTTPS');
    });

    it('lets a local UAT build reach only this computer over http', () => {
        expect(resolveServerUrl('http://127.0.0.1:8124', true, true)).toBe('http://127.0.0.1:8124');
        expect(() => resolveServerUrl('http://sprintsync.example.com', true, true)).toThrow('HTTPS');
        expect(() => resolveServerUrl('http://192.168.1.20:8124', true, true)).toThrow('HTTPS');
    });

    it('labels the build channel, uat unless stable', () => {
        expect(resolveChannel('stable')).toBe('stable');
        expect(resolveChannel(undefined)).toBe('uat');
        expect(resolveChannel('nightly')).toBe('uat');
    });

    it('requires a configured https address for packaged builds', () => {
        expect(() => resolveServerUrl(undefined, true)).toThrow('not configured');
        expect(resolveServerUrl('https://app.sprintsync.example/some/path', true)).toBe('https://app.sprintsync.example');
    });

    it('refuses odd addresses', () => {
        expect(() => resolveServerUrl('not a url', false)).toThrow();
        expect(() => resolveServerUrl('https://user:pass@sprintsync.example', true)).toThrow('credentials');
        expect(() => resolveServerUrl('file:///etc/passwd', false)).toThrow();
    });
});
