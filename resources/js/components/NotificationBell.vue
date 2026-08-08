<script setup lang="ts">
import { DropdownMenu, DropdownMenuContent, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import type { NotificationItem, SharedData } from '@/types';
import { router, usePage } from '@inertiajs/vue3';
import { Bell, CheckCheck, Inbox } from 'lucide-vue-next';
import { computed } from 'vue';

const page = usePage<SharedData>();
const notifications = computed(() => page.props.notifications);
const unreadCount = computed(() => notifications.value?.unread_count ?? 0);
const badgeLabel = computed(() => (unreadCount.value > 9 ? '9+' : String(unreadCount.value)));

function relativeTime(iso: string): string {
    const diffSeconds = Math.round((Date.now() - new Date(iso).getTime()) / 1000);

    if (diffSeconds < 60) return 'just now';

    const diffMinutes = Math.round(diffSeconds / 60);
    if (diffMinutes < 60) return `${diffMinutes}m ago`;

    const diffHours = Math.round(diffMinutes / 60);
    if (diffHours < 24) return `${diffHours}h ago`;

    const diffDays = Math.round(diffHours / 24);
    if (diffDays < 7) return `${diffDays}d ago`;

    return new Date(iso).toLocaleDateString(undefined, { month: 'short', day: 'numeric' });
}

function openNotification(notification: NotificationItem) {
    if (notification.read_at !== null) {
        if (notification.url) router.visit(notification.url);
        return;
    }

    router.post(
        route('notifications.read', { notification: notification.id }),
        {},
        {
            preserveScroll: true,
            preserveState: true,
            only: ['notifications'],
            onSuccess: () => {
                if (notification.url) router.visit(notification.url);
            },
        },
    );
}

function markAllRead() {
    router.post(
        route('notifications.read-all'),
        {},
        { preserveScroll: true, preserveState: true, only: ['notifications'] },
    );
}
</script>

<template>
    <DropdownMenu v-if="notifications">
        <DropdownMenuTrigger as-child>
            <button
                type="button"
                class="text-muted-foreground hover:bg-accent hover:text-accent-foreground relative inline-flex size-9 shrink-0 items-center justify-center rounded-md transition-colors"
                aria-label="Notifications"
            >
                <Bell class="size-[18px]" />
                <span
                    v-if="unreadCount > 0"
                    class="bg-destructive absolute top-1 right-1 flex size-4 items-center justify-center rounded-full text-[10px] leading-none font-medium text-white"
                >
                    {{ badgeLabel }}
                </span>
            </button>
        </DropdownMenuTrigger>

        <DropdownMenuContent align="end" :side-offset="8" class="w-96 p-0">
            <div class="flex items-center justify-between border-b px-3 py-2.5">
                <span class="text-sm font-semibold">Notifications</span>
                <button
                    v-if="unreadCount > 0"
                    type="button"
                    class="text-muted-foreground hover:text-foreground flex items-center gap-1 text-xs font-medium"
                    @click="markAllRead"
                >
                    <CheckCheck class="size-3.5" />
                    Mark all read
                </button>
            </div>

            <div v-if="notifications.recent.length === 0" class="flex flex-col items-center gap-2 px-4 py-10 text-center">
                <Inbox class="text-muted-foreground/30 size-6" />
                <p class="text-muted-foreground text-xs">You're all caught up.</p>
            </div>

            <div v-else class="max-h-96 overflow-y-auto">
                <button
                    v-for="notification in notifications.recent"
                    :key="notification.id"
                    type="button"
                    class="hover:bg-accent flex w-full items-start gap-2.5 border-b px-3 py-3 text-left transition-colors last:border-b-0"
                    :class="notification.read_at === null && 'bg-primary/5'"
                    @click="openNotification(notification)"
                >
                    <span
                        class="mt-1.5 size-1.5 shrink-0 rounded-full"
                        :class="notification.read_at === null ? 'bg-primary' : 'bg-transparent'"
                    />

                    <span class="min-w-0 flex-1">
                        <span class="flex items-baseline justify-between gap-2">
                            <span class="text-foreground truncate text-sm font-medium">{{ notification.title }}</span>
                            <span class="text-muted-foreground shrink-0 text-[11px]">{{ relativeTime(notification.created_at) }}</span>
                        </span>
                        <span class="text-muted-foreground mt-0.5 block text-xs leading-relaxed">{{ notification.message }}</span>
                    </span>
                </button>
            </div>
        </DropdownMenuContent>
    </DropdownMenu>
</template>
