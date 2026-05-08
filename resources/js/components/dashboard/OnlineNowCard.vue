<script setup lang="ts">
/**
 * OnlineNowCard — list of currently active members.
 *
 * Reuses the same `Member` shape from lib/members. Shows up to `maxVisible`
 * members and offers a "+N more" link to the full team page.
 */

import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';

import type { Member } from '@/lib/members';

interface Props {
    members: Member[];
    /** Where "+N more" links to */
    viewAllHref: string;
    maxVisible?: number;
}

const props = withDefaults(defineProps<Props>(), {
    maxVisible: 4,
});

const visible = computed(() => props.members.slice(0, props.maxVisible));
const overflow = computed(() => Math.max(0, props.members.length - props.maxVisible));

const roleLabel: Record<Member['role'], string> = {
    owner: 'Owner',
    admin: 'Admin',
    member: 'Member',
    guest: 'Guest',
    billing: 'Billing',
};
</script>

<template>
    <div class="bg-card rounded-xl border p-5 shadow-sm">
        <div class="mb-3.5 flex items-center gap-1.5">
            <span class="relative flex size-2">
                <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-emerald-500 opacity-60" />
                <span class="relative inline-flex size-2 rounded-full bg-emerald-500" />
            </span>
            <h3 class="text-[13px] font-semibold tracking-tight">Online now</h3>
            <span class="text-muted-foreground ml-auto text-[11px] tabular-nums">
                {{ members.length }}
            </span>
        </div>

        <div v-if="members.length === 0" class="text-muted-foreground py-4 text-center text-xs">No teammates online right now</div>

        <ul v-else class="space-y-2.5">
            <li v-for="m in visible" :key="m.id" class="flex items-center gap-2.5">
                <AppAvatar :name="m.name" :email="m.email" :src="m.avatar_url" status="active" size="sm" />
                <div class="min-w-0 flex-1">
                    <p class="truncate text-[12.5px] leading-tight font-medium">
                        {{ m.name }}
                        <span v-if="m.is_self" class="text-muted-foreground ml-0.5 text-[10.5px] font-medium"> · you </span>
                    </p>
                    <p class="text-muted-foreground truncate text-[10.5px]">
                        {{ roleLabel[m.role] }}
                    </p>
                </div>
            </li>
        </ul>

        <Link
            v-if="overflow > 0"
            :href="viewAllHref"
            class="text-muted-foreground hover:text-foreground mt-3 inline-flex items-center text-[11.5px] font-medium"
        >
            +{{ overflow }} more →
        </Link>
    </div>
</template>
