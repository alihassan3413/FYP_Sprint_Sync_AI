<script setup lang="ts">
import { Eye, Loader2, LogOut } from 'lucide-vue-next';

import type { SharedData } from '@/types';

const page = usePage<SharedData>();
const impersonation = computed(() => page.props.impersonation ?? null);
const leaving = ref(false);

function stop() {
    if (leaving.value) return;

    leaving.value = true;
    router.post(route('impersonation.stop'), {}, { onFinish: () => (leaving.value = false) });
}
</script>

<template>
    <div
        v-if="impersonation"
        class="flex flex-wrap items-center gap-x-3 gap-y-2 border-b border-amber-500/30 bg-amber-400/15 px-4 py-2 text-sm text-amber-900 md:px-6 dark:text-amber-200"
        role="status"
        data-testid="impersonation-banner"
    >
        <Eye class="size-4 shrink-0" aria-hidden="true" />
        <p class="min-w-0 flex-1">
            You’re viewing SprintSync as <span class="font-semibold">{{ impersonation.user_name }}</span
            >. Anything you change here is real.
        </p>
        <Button
            size="sm"
            variant="outline"
            class="h-8 shrink-0 gap-1.5 bg-transparent"
            :disabled="leaving"
            data-testid="stop-impersonating"
            @click="stop"
        >
            <Loader2 v-if="leaving" class="size-3.5 animate-spin" />
            <LogOut v-else class="size-3.5" />
            Return to admin
        </Button>
    </div>
</template>
