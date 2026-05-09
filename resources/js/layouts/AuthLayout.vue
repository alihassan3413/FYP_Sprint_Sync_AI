<script setup lang="ts">
import { Sparkles } from 'lucide-vue-next';

defineProps<{
    title?: string;
    description?: string;
}>();
</script>

<template>
    <div class="min-h-svh bg-[#FAF8F3] text-black">
        <div class="lg:grid lg:grid-cols-[1.1fr_1fr]">
            <aside class="relative hidden overflow-hidden border-r-[1.5px] border-black lg:block">
                <div
                    class="pointer-events-none absolute right-10 top-10 h-16 w-16 opacity-50"
                    style="background-image: radial-gradient(circle, #000 1px, transparent 1px); background-size: 8px 8px;"
                />
                <div
                    class="pointer-events-none absolute -bottom-32 -left-24 h-px w-[520px] rotate-[26deg] bg-black/15"
                />
                <span
                    class="pointer-events-none absolute right-[18%] top-[44%] h-3 w-3 rounded-full border-[1.5px] border-black"
                />

                <div class="relative flex h-full min-h-svh flex-col px-12 py-11 xl:px-16 xl:py-14">
                    <Link :href="route('home')" class="flex w-fit items-center gap-2.5">
                        <div class="flex h-7 w-7 items-center justify-center border-[1.5px] border-black bg-black">
                            <AppLogoIcon class="size-3.5 fill-current text-[#D4FF4A]" />
                        </div>
                        <span class="text-[13px] font-bold tracking-tight">Sprint Sync</span>
                    </Link>

                    <div class="mt-12 max-w-[460px] xl:mt-16">
                        <h2 class="text-[52px] font-black leading-[0.95] tracking-[-0.035em] xl:text-[60px]">
                            Plan sprints<br />faster with
                            <span class="relative inline-block whitespace-nowrap">
                                <span
                                    class="absolute inset-x-[-6px] bottom-[6px] h-[18px] -skew-x-6 bg-[#D4FF4A]"
                                />
                                <span class="relative">AI.</span>
                            </span>
                        </h2>
                        <p class="mt-5 max-w-[340px] text-[15px] leading-relaxed text-zinc-600">
                            Drag, drop, and let AI handle the sprint math while your team ships work.
                        </p>
                    </div>

                    <div class="relative mt-12 flex-1 xl:mt-14">
                        <div class="flex gap-3">
                            <div class="w-[150px]">
                                <div class="mb-3 flex items-center justify-between">
                                    <span class="text-[10px] font-bold uppercase tracking-[0.2em] text-zinc-500">
                                        Todo
                                    </span>
                                    <span class="text-[10px] font-medium text-zinc-400">3</span>
                                </div>
                                <div class="space-y-2.5">
                                    <div class="border-[1.5px] border-black bg-white p-3">
                                        <p class="text-[11px] font-semibold leading-snug">
                                            Mobile push notifications
                                        </p>
                                        <div class="mt-2.5 flex items-center justify-between">
                                            <span class="flex items-center gap-1.5">
                                                <span class="h-1.5 w-1.5 bg-zinc-400" />
                                                <span class="text-[9px] font-semibold uppercase tracking-[0.14em] text-zinc-500">
                                                    Backlog
                                                </span>
                                            </span>
                                            <span
                                                class="flex h-4 w-4 items-center justify-center rounded-full border-[1.5px] border-black bg-white text-[8px] font-bold"
                                            >
                                                M
                                            </span>
                                        </div>
                                    </div>
                                    <div
                                        class="-rotate-[1.5deg] border-[1.5px] border-black bg-white p-3 shadow-[3px_3px_0_#000]"
                                    >
                                        <p class="text-[11px] font-semibold leading-snug">
                                            Refactor auth middleware
                                        </p>
                                        <div class="mt-2.5 flex items-center justify-between">
                                            <span class="flex items-center gap-1.5">
                                                <span class="h-1.5 w-1.5 bg-black" />
                                                <span class="text-[9px] font-semibold uppercase tracking-[0.14em] text-zinc-500">
                                                    Tech
                                                </span>
                                            </span>
                                            <span
                                                class="flex h-4 w-4 items-center justify-center rounded-full border-[1.5px] border-black bg-white text-[8px] font-bold"
                                            >
                                                JK
                                            </span>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="w-[150px]">
                                <div class="mb-3 flex items-center justify-between">
                                    <span class="text-[10px] font-bold uppercase tracking-[0.2em] text-zinc-500">
                                        In review
                                    </span>
                                    <span class="text-[10px] font-medium text-zinc-400">1</span>
                                </div>
                                <div class="space-y-2.5">
                                    <div class="border-[1.5px] border-black bg-white p-3">
                                        <p class="text-[11px] font-semibold leading-snug">
                                            Sprint AI suggestions v2
                                        </p>
                                        <div class="mt-2.5 flex items-center justify-between">
                                            <span class="flex items-center gap-1.5">
                                                <span class="h-1.5 w-1.5 rounded-full border-[1.5px] border-black" />
                                                <span class="text-[9px] font-semibold uppercase tracking-[0.14em] text-zinc-500">
                                                    QA
                                                </span>
                                            </span>
                                            <span
                                                class="flex h-4 w-4 items-center justify-center rounded-full border-[1.5px] border-black bg-white text-[8px] font-bold"
                                            >
                                                RS
                                            </span>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="w-[150px]">
                                <div class="mb-3 flex items-center justify-between">
                                    <span class="text-[10px] font-bold uppercase tracking-[0.2em] text-zinc-500">
                                        Done
                                    </span>
                                    <span class="text-[10px] font-medium text-zinc-400">2</span>
                                </div>
                                <div class="space-y-2.5">
                                    <div class="border-[1.5px] border-black bg-white p-3 shadow-[3px_3px_0_#000]">
                                        <p class="text-[11px] font-semibold leading-snug">
                                            API rate limiting
                                        </p>
                                        <div class="mt-2.5 flex items-center justify-between">
                                            <span class="flex items-center gap-1.5">
                                                <span class="h-1.5 w-1.5 rounded-full bg-black" />
                                                <span class="text-[9px] font-semibold uppercase tracking-[0.14em] text-zinc-500">
                                                    Shipped
                                                </span>
                                            </span>
                                            <span
                                                class="flex h-4 w-4 items-center justify-center rounded-full border-[1.5px] border-black bg-white text-[8px] font-bold"
                                            >
                                                AL
                                            </span>
                                        </div>
                                    </div>
                                    <div class="border-[1.5px] border-black bg-white p-3">
                                        <p class="text-[11px] font-semibold leading-snug">
                                            Onboarding redesign
                                        </p>
                                        <div class="mt-2.5 flex items-center justify-between">
                                            <span class="text-[9px] font-semibold uppercase tracking-[0.14em] text-zinc-500">
                                                S
                                            </span>
                                            <span
                                                class="flex h-4 w-4 items-center justify-center rounded-full border-[1.5px] border-black bg-white text-[8px] font-bold"
                                            >
                                                MR
                                            </span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div
                            class="absolute right-[-10px] top-[122px] w-[228px] -rotate-[2.5deg] border-[1.5px] border-black bg-white p-3.5 shadow-[5px_5px_0_#000]"
                        >
                            <div class="mb-1.5 flex items-center gap-2">
                                <span
                                    class="flex h-5 w-5 items-center justify-center border-[1.5px] border-black bg-[#D4FF4A]"
                                >
                                    <Sparkles class="h-3 w-3" :stroke-width="2.5" />
                                </span>
                                <span class="text-[9px] font-bold uppercase tracking-[0.18em]">
                                    AI suggestion
                                </span>
                            </div>
                            <p class="text-[12px] font-medium leading-snug">
                                Move 2 tasks to next sprint to balance team capacity.
                            </p>
                        </div>
                    </div>

                    <div class="mt-10 flex items-end justify-between gap-6">
                        <div>
                            <p class="mb-2.5 text-[10px] font-bold uppercase tracking-[0.2em] text-zinc-500">
                                Team
                            </p>
                            <div class="flex -space-x-2">
                                <span
                                    class="relative z-40 flex h-8 w-8 items-center justify-center rounded-full border-[1.5px] border-black bg-white text-[10px] font-bold ring-[2.5px] ring-[#D4FF4A]"
                                >
                                    JK
                                </span>
                                <span
                                    class="relative z-30 flex h-8 w-8 items-center justify-center rounded-full border-[1.5px] border-black bg-white text-[10px] font-bold"
                                >
                                    RS
                                </span>
                                <span
                                    class="relative z-20 flex h-8 w-8 items-center justify-center rounded-full border-[1.5px] border-black bg-white text-[10px] font-bold"
                                >
                                    AL
                                </span>
                                <span
                                    class="relative z-10 flex h-8 w-8 items-center justify-center rounded-full border-[1.5px] border-black bg-black text-[10px] font-bold text-white"
                                >
                                    +3
                                </span>
                            </div>
                        </div>

                        <div class="text-right">
                            <p class="text-[10px] font-bold uppercase tracking-[0.2em] text-zinc-500">
                                Sprint 14 · 78% complete
                            </p>
                            <div class="mt-2.5 flex items-end justify-end gap-[3px]">
                                <span class="block h-2 w-1.5 bg-black/40" />
                                <span class="block h-3 w-1.5 bg-black/55" />
                                <span class="block h-[10px] w-1.5 bg-black/70" />
                                <span class="block h-4 w-1.5 bg-black" />
                                <span class="block h-[18px] w-1.5 bg-black" />
                                <span class="block h-6 w-1.5 border-[1.5px] border-black bg-[#D4FF4A]" />
                            </div>
                        </div>
                    </div>
                </div>
            </aside>

            <main class="flex min-h-svh flex-col items-center justify-center px-6 py-12 md:px-10">
                <Link :href="route('home')" class="mb-8 flex items-center gap-2.5 lg:hidden">
                    <div class="flex h-7 w-7 items-center justify-center border-[1.5px] border-black bg-black">
                        <AppLogoIcon class="size-3.5 fill-current text-[#D4FF4A]" />
                    </div>
                    <span class="text-[13px] font-bold tracking-tight">Sprint Sync</span>
                </Link>

                <div class="w-full max-w-sm">
                    <div class="border-[1.5px] border-black bg-white p-6 shadow-[3px_3px_0_#000]">
                        <div class="mb-5">
                            <h1 class="text-[22px] font-black leading-tight tracking-tight">
                                {{ title }}
                            </h1>
                            <p v-if="description" class="mt-1.5 text-sm text-zinc-500">
                                {{ description }}
                            </p>
                        </div>
                        <slot />
                    </div>
                </div>
            </main>
        </div>
    </div>
</template>
