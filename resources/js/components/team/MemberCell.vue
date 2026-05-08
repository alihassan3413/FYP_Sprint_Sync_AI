<script setup lang="ts">
/**
 * MemberCell — the standard "person" cell.
 *
 * Renders avatar with presence + name + email + optional "you" tag.
 * Used in the Teams table, but also reusable for:
 *   - Task assignee columns
 *   - Mention previews
 *   - Comment authors
 *   - Audit log actor columns
 */

import { memberPresence, type Member } from '@/lib/members';

interface Props {
    member: Member;
    /** Avatar size — defaults to md */
    size?: 'sm' | 'md' | 'lg';
    /** Hide the email line (useful in dense tables) */
    hideEmail?: boolean;
}

withDefaults(defineProps<Props>(), {
    size: 'md',
    hideEmail: false,
});
</script>

<template>
    <div class="flex min-w-0 items-center gap-3">
        <AppAvatar
            :name="member.name"
            :email="member.email"
            :src="member.avatar_url"
            :status="memberPresence(member.status)"
            :placeholder="member.status === 'pending'"
            :size="size"
        />

        <div class="min-w-0">
            <div class="flex items-center gap-2">
                <span
                    class="text-foreground truncate text-[13.5px] font-medium"
                    :class="member.status === 'pending' && 'text-muted-foreground italic'"
                >
                    {{ member.status === 'pending' ? member.email : member.name }}
                </span>
                <span
                    v-if="member.is_self"
                    class="rounded bg-violet-100 px-1.5 py-px text-[10px] font-semibold tracking-wider text-violet-700 uppercase dark:bg-violet-500/20 dark:text-violet-300"
                >
                    You
                </span>
            </div>

            <p v-if="!hideEmail" class="text-muted-foreground mt-0.5 truncate text-xs">
                {{ member.status === 'pending' ? 'Invite sent · awaiting acceptance' : member.email }}
            </p>
        </div>
    </div>
</template>
