import tailwindcss from '@tailwindcss/vite'
import vue from '@vitejs/plugin-vue'
import laravel from 'laravel-vite-plugin'
import path from 'node:path'
import { defineConfig } from 'vite'

import AutoImport from 'unplugin-auto-import/vite'
import Components from 'unplugin-vue-components/vite'
import Icons from 'unplugin-icons/vite'
import IconsResolver from 'unplugin-icons/resolver'

import {
    autoImportDirs,
    autoImportImports,
} from './frontend-auto-import.config.mjs'

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/js/app.ts', 'resources/css/app.css'],
            refresh: true,
        }),

        tailwindcss(),

        vue({
            template: {
                transformAssetUrls: {
                    base: null,
                    includeAbsolute: false,
                },
            },
        }),

        AutoImport({
            vueTemplate: true,
            dts: 'resources/js/types/auto-imports.d.ts',
            dtsMode: 'overwrite',
            imports: autoImportImports,
            dirs: autoImportDirs,
        }),

        Icons({
            compiler: 'vue3',
            autoInstall: true,
        }),

        Components({
            deep: true,
            extensions: ['vue'],
            dts: 'resources/js/types/components.d.ts',
            dirs: [
                'resources/js/components',
                'resources/js/layouts',
                'resources/js/modules',
            ],
            resolvers: [
                IconsResolver({
                    prefix: 'Icon',
                }),
            ],
        }),
    ],

    resolve: {
        alias: {
            '@': path.resolve(__dirname, './resources/js'),
        },
    },
})
