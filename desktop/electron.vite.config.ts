import vue from '@vitejs/plugin-vue';
import { defineConfig } from 'electron-vite';
import type { Plugin } from 'vite';

/**
 * Content Security Policy for the renderer. Scripts only from the app
 * itself; the renderer never talks to the network (the main process does),
 * so connect-src only opens up for Vite's dev server during `npm run dev`.
 */
function contentSecurityPolicy(): Plugin {
    let dev = false;

    return {
        name: 'sprintsync-csp',
        configResolved(config) {
            dev = config.command === 'serve';
        },
        transformIndexHtml(html) {
            const policy = [
                "default-src 'self'",
                "script-src 'self'",
                "style-src 'self' 'unsafe-inline'",
                "img-src 'self' data:",
                "font-src 'self'",
                dev ? "connect-src 'self' ws://localhost:* http://localhost:*" : "connect-src 'none'",
                "object-src 'none'",
                "base-uri 'none'",
                "form-action 'none'",
                "frame-ancestors 'none'",
            ].join('; ');

            return html.replace('<!-- CSP -->', `<meta http-equiv="Content-Security-Policy" content="${policy}" />`);
        },
    };
}

export default defineConfig({
    main: {
        build: { externalizeDeps: true },
    },
    preload: {
        build: {
            externalizeDeps: true,
            rollupOptions: { output: { format: 'cjs', entryFileNames: '[name].cjs' } },
        },
    },
    renderer: {
        plugins: [vue(), contentSecurityPolicy()],
    },
});
