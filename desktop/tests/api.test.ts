import { describe, expect, it, vi } from 'vitest';

import { ApiError, NetworkError, SprintSyncApi, type FetchLike } from '../src/main/api';

const json = (status: number, body: unknown) => new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });

describe('SprintSync API client', () => {
    it('sends the token only as a Bearer header', async () => {
        const fetch = vi.fn<FetchLike>(async () => json(200, { user: { name: 'U', email: 'u@x' }, workspaces: [] }));
        await new SprintSyncApi('https://s.example', fetch, () => 'ssd_secret').me();

        const [url, init] = fetch.mock.calls[0]!;
        expect(url).toBe('https://s.example/api/desktop/me');
        expect((init.headers as Record<string, string>).Authorization).toBe('Bearer ssd_secret');
        expect(init.redirect).toBe('error');
    });

    it('does not send a token when signing in', async () => {
        const fetch = vi.fn<FetchLike>(async () => json(201, { token: 't', user: {}, workspaces: [] }));
        await new SprintSyncApi('https://s.example', fetch, () => 'ssd_old').signIn('a@b.c', 'pw', 'Mac', 'macos');

        expect((fetch.mock.calls[0]![1].headers as Record<string, string>).Authorization).toBeUndefined();
    });

    it("turns SprintSync's typed errors into ApiError without the token", async () => {
        const fetch: FetchLike = async () => json(409, { error: { code: 'TIME.TIMER.CHANGED', message: 'Your timer changed on another device.' } });
        const error = await new SprintSyncApi('https://s.example', fetch, () => 'ssd_secret').change('ws', 'stop', { operation_key: 'k', session: 's', occurred_at: 'now' }).catch((e) => e);

        expect(error).toBeInstanceOf(ApiError);
        expect(error.code).toBe('TIME.TIMER.CHANGED');
        expect(error.status).toBe(409);
        expect(JSON.stringify(error) + String(error)).not.toContain('ssd_secret');
    });

    it('reads validation messages', async () => {
        const fetch: FetchLike = async () => json(422, { message: 'Invalid', errors: { email: ['These credentials do not match our records.'] } });
        const error = await new SprintSyncApi('https://s.example', fetch, () => null).signIn('a', 'b', 'c', 'macos').catch((e) => e);

        expect(error.message).toBe('These credentials do not match our records.');
    });

    it('reports no connection as a NetworkError (safe to retry)', async () => {
        const fetch: FetchLike = async () => {
            throw new TypeError('fetch failed');
        };

        await expect(new SprintSyncApi('https://s.example', fetch, () => null).me()).rejects.toBeInstanceOf(NetworkError);
    });

    it('gives up on slow answers', async () => {
        const fetch: FetchLike = (_url, init) => new Promise((_resolve, reject) => init.signal?.addEventListener('abort', () => reject(new Error('aborted'))));

        await expect(new SprintSyncApi('https://s.example', fetch, () => null, 20).me()).rejects.toBeInstanceOf(NetworkError);
    });
});
