function getCookie(name: string): string | null {
    const value = document.cookie.split('; ').find((row) => row.startsWith(`${name}=`));

    if (!value) {
        return null;
    }

    return decodeURIComponent(value.split('=')[1] ?? '');
}

export function getCsrfToken(): string {
    return getCookie('XSRF-TOKEN') || document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.getAttribute('content') || '';
}
