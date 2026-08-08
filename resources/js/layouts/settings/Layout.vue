<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { Bell, KeyRound, SunMoon, UserRound } from 'lucide-vue-next';
import { computed } from 'vue';

const navItems = [
    { title: 'Profile', hint: 'Photo, name and email', href: '/settings/profile', icon: UserRound },
    { title: 'Password', hint: 'Sign-in credentials', href: '/settings/password', icon: KeyRound },
    { title: 'Notifications', hint: 'What reaches you, and how', href: '/settings/notifications', icon: Bell },
    { title: 'Appearance', hint: 'Theme and contrast', href: '/settings/appearance', icon: SunMoon },
];

const page = usePage();

const currentPath = computed(() => page.url.split('?')[0].replace(/\/$/, ''));
</script>

<template>
    <div class="mx-auto w-full max-w-5xl px-4 py-8 sm:px-6 lg:px-8 lg:py-10">
        <header class="mb-8">
            <p class="text-muted-foreground text-[11px] font-medium tracking-[0.14em] uppercase">Account</p>
            <h1 class="text-foreground mt-2 text-2xl font-semibold tracking-tight sm:text-[28px]">Settings</h1>
            <p class="text-muted-foreground mt-1.5 max-w-xl text-sm leading-relaxed">
                Manage your personal details, sign-in security, and how the workspace looks and keeps you informed.
            </p>
        </header>

        <div class="flex flex-col gap-8 lg:flex-row lg:gap-12">
            <aside class="lg:w-56 lg:shrink-0">
                <nav
                    class="-mx-4 flex snap-x gap-1.5 overflow-x-auto px-4 pb-1 [scrollbar-width:none] lg:sticky lg:top-6 lg:mx-0 lg:flex-col lg:gap-0.5 lg:overflow-visible lg:px-0 lg:pb-0"
                >
                    <Link
                        v-for="item in navItems"
                        :key="item.href"
                        :href="item.href"
                        prefetch
                        :class="[
                            'group relative flex shrink-0 snap-start items-center gap-2.5 rounded-lg px-3 py-2 text-sm transition-colors lg:w-full lg:gap-3 lg:py-2.5',
                            currentPath === item.href
                                ? 'bg-card text-foreground ring-border/70 lg:bg-muted/60 shadow-[0_1px_2px_rgba(16,24,40,0.04)] ring-1 lg:shadow-none lg:ring-0'
                                : 'text-muted-foreground hover:bg-muted/50 hover:text-foreground',
                        ]"
                    >
                        <span
                            v-if="currentPath === item.href"
                            class="bg-primary absolute top-1/2 -left-px hidden h-5 w-[3px] -translate-y-1/2 rounded-full lg:block"
                        />

                        <component
                            :is="item.icon"
                            :class="[
                                'size-4 shrink-0 transition-colors',
                                currentPath === item.href ? 'text-foreground' : 'text-muted-foreground/70 group-hover:text-foreground',
                            ]"
                        />

                        <span class="flex min-w-0 flex-col">
                            <span class="font-medium whitespace-nowrap">{{ item.title }}</span>
                            <span
                                :class="[
                                    'hidden truncate text-[11px] lg:block',
                                    currentPath === item.href ? 'text-muted-foreground' : 'text-muted-foreground/70',
                                ]"
                            >
                                {{ item.hint }}
                            </span>
                        </span>
                    </Link>
                </nav>
            </aside>

            <div class="flex min-w-0 flex-1 flex-col gap-5">
                <slot />
            </div>
        </div>
    </div>
</template>
