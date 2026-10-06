/**
 * Where SprintSync lives. Set at build time with MAIN_VITE_SPRINTSYNC_URL
 * (never hard-coded for production). Packaged apps require HTTPS; plain
 * HTTP is accepted only for a local QA server (development, or a local UAT
 * build).
 */
export const DEV_DEFAULT_URL = 'http://127.0.0.1:8124';

const LOCAL_HOSTS = new Set(['127.0.0.1', 'localhost', '[::1]']);

/**
 * allowLoopbackHttp: a local UAT build (MAIN_VITE_SPRINTSYNC_LOCAL_UAT=1) may
 * reach a QA server on this computer over http when packaged. Any other host
 * still needs HTTPS; production builds never set it.
 */
export function resolveServerUrl(configured: string | undefined, isPackaged: boolean, allowLoopbackHttp = false): string {
    const raw = (configured ?? '').trim() || (isPackaged ? '' : DEV_DEFAULT_URL);

    if (raw === '') {
        throw new Error('SprintSync URL is not configured for this build.');
    }

    let url: URL;
    try {
        url = new URL(raw);
    } catch {
        throw new Error('SprintSync URL is not a valid address.');
    }

    if (url.username || url.password) {
        throw new Error('SprintSync URL must not contain credentials.');
    }

    const local = LOCAL_HOSTS.has(url.hostname);

    if (url.protocol === 'https:' || (url.protocol === 'http:' && local && (!isPackaged || allowLoopbackHttp))) {
        return url.origin;
    }

    throw new Error(isPackaged ? 'SprintSync must be reached over HTTPS.' : 'Use HTTPS, or http://127.0.0.1 for local QA.');
}

/** Which release line this build belongs to, shown in Settings. */
export function resolveChannel(configured: string | undefined): 'stable' | 'uat' {
    return configured === 'stable' ? 'stable' : 'uat';
}
