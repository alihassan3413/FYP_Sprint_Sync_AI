export const autoImportDirs = [
    'resources/js/composables/**',
    'resources/js/stores/**',
    'resources/js/lib/**',
    'resources/js/utils/**',
    'resources/js/modules/**/composables/**',
    'resources/js/modules/**/helpers/**',
    'resources/js/modules/**/forms/**',
]

export const autoImportImports = [
    'vue',
    {
        '@inertiajs/vue3': [
            'router',
            'useForm',
            'usePage',
            'useRemember',
            'Head',
            'Link',
        ],

        pinia: [
            'defineStore',
            'storeToRefs',
        ],

        '@vueuse/core': [
            'useDark',
            'useToggle',
            'useLocalStorage',
            'useDebounceFn',
            'useClipboard',
        ],
    },
]
