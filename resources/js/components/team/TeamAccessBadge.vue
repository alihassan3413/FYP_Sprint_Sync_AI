<script setup lang="ts">
import type { Member } from '@/lib/members';

/** A Team row's SprintSync access state, as one quiet label. Actions live in the row menu. */
defineProps<{ member: Member }>();
</script>

<template>
    <span class="inline-flex flex-wrap items-center gap-1.5 text-xs" data-testid="access-state">
        <template v-if="member.status === 'active'">
            <AppRoleBadge :role="member.role ?? 'member'" />
            <span v-if="member.workspace_role_name" class="text-muted-foreground">{{ member.workspace_role_name }}</span>
            <span v-if="member.link_suggested" class="text-muted-foreground">· not connected</span>
        </template>
        <span v-else-if="member.status === 'pending'" class="rounded-full bg-amber-500/10 px-2 py-0.5 font-medium text-amber-700 dark:text-amber-400">
            Invitation pending
        </span>
        <span v-else class="text-muted-foreground">No SprintSync access</span>
    </span>
</template>
