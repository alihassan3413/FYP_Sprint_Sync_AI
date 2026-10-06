/** A published SprintSync Desktop build (DesktopRelease::toDownload). */
export type DesktopDownload = {
    channel: 'stable' | 'uat';
    platform: 'macos' | 'windows' | 'linux';
    platform_label: string;
    arch: string;
    arch_label: string;
    version: string;
    size_bytes: number;
    sha256: string;
    min_os: string | null;
    signed: boolean;
    released_at: string;
    url: string;
};

/** A basic guess from the browser's user agent; every build stays listed either way. */
export function detectPlatform(userAgent = typeof navigator === 'undefined' ? '' : navigator.userAgent): DesktopDownload['platform'] | null {
    if (/Mac OS X|Macintosh/i.test(userAgent) && !/iPhone|iPad/i.test(userAgent)) return 'macos';
    if (/Windows/i.test(userAgent)) return 'windows';
    if (/Linux/i.test(userAgent) && !/Android/i.test(userAgent)) return 'linux';
    return null;
}

export function formatBytes(bytes: number): string {
    return bytes >= 1024 * 1024 ? `${(bytes / (1024 * 1024)).toFixed(0)} MB` : `${Math.max(1, Math.round(bytes / 1024))} KB`;
}

/** Short architecture names for people: "Apple silicon", "Intel". */
export function archName(download: DesktopDownload): string {
    if (download.arch === 'universal') return 'Universal';
    if (download.platform === 'macos') return download.arch === 'arm64' ? 'Apple silicon' : 'Intel';
    return download.arch === 'arm64' ? 'ARM' : '64-bit';
}

export type PlatformChoice = {
    platform: 'macos' | 'windows';
    label: string;
    /** The build the main button downloads; null when none is published yet. */
    download: DesktopDownload | null;
    /** Other architectures for the same platform (e.g. Intel Macs). */
    alternatives: DesktopDownload[];
};

const PREFERRED_ARCH: Record<PlatformChoice['platform'], string[]> = {
    macos: ['arm64', 'universal', 'x64'],
    windows: ['x64', 'arm64', 'universal'],
};

/**
 * macOS and Windows are always both offered; the visitor's platform (by user
 * agent) comes first. A platform with no published build is still listed so
 * the page can say so instead of hiding it.
 */
export function platformChoices(downloads: DesktopDownload[], detected: DesktopDownload['platform'] | null): PlatformChoice[] {
    const choices = (['macos', 'windows'] as const).map((platform) => {
        const builds = downloads
            .filter((download) => download.platform === platform)
            .sort((a, b) => PREFERRED_ARCH[platform].indexOf(a.arch) - PREFERRED_ARCH[platform].indexOf(b.arch));

        return { platform, label: platform === 'macos' ? 'macOS' : 'Windows', download: builds[0] ?? null, alternatives: builds.slice(1) };
    });

    return detected === 'windows' ? choices.reverse() : choices;
}
