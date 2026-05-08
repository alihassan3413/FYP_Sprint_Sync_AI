<script setup lang="ts">
/**
 * ActivityTimeline — workspace event feed.
 *
 * Renders a vertical timeline with avatars, semantic event copy, and relative
 * timestamps. Reusable on Dashboard and any future audit-log page.
 */

import { Rocket, Trash2, UserPlus } from 'lucide-vue-next';

import type { ActivityEntry, ActivityKind } from '@/lib/activity';
import { formatLastActive } from '@/lib/members';

interface Props {
    entries: ActivityEntry[];
}

defineProps<Props>();

interface DescribedEntry {
    actor: string;
    verb: string;
    target: string | null;
    suffix: string;
}

/* ----------------------------------------------------------------------------
 * Copy generator — keeps every line consistent.
 * Returns a uniform shape so the template doesn't need to handle nullables.
 * ------------------------------------------------------------------------- */
function describe(entry: ActivityEntry): DescribedEntry {
    const ctx = entry.context ?? {};
    const actorName = entry.actor_is_self ? 'You' : (entry.actor?.name ?? 'Someone');

    switch (entry.kind) {
        case 'workspace.created':
            return {
                actor: ctx.workspace_name ?? 'Workspace',
                verb: 'workspace was created',
                target: null,
                suffix: '',
            };
        case 'member.invited':
            return {
                actor: actorName,
                verb: 'invited',
                target: ctx.target_email ?? null,
                suffix: ctx.role_to ? `as ${capitalize(ctx.role_to)}` : '',
            };
        case 'member.joined':
            return {
                actor: actorName,
                verb: 'accepted their invitation',
                target: null,
                suffix: '',
            };
        case 'member.role_changed':
            return {
                actor: actorName,
                verb: 'promoted',
                target: ctx.target_name ?? null,
                suffix: ctx.role_to ? `to ${capitalize(ctx.role_to)}` : '',
            };
        case 'member.removed':
            return {
                actor: actorName,
                verb: 'removed',
                target: ctx.target_name ?? ctx.target_email ?? null,
                suffix: 'from the workspace',
            };
        case 'invitation.revoked':
            return {
                actor: actorName,
                verb: 'revoked the invite for',
                target: ctx.target_email ?? null,
                suffix: '',
            };
        case 'invitation.expired':
            return {
                actor: 'Invitation',
                verb: `for ${ctx.target_email ?? 'someone'} expired`,
                target: null,
                suffix: '',
            };
        default:
            return {
                actor: actorName,
                verb: 'did something',
                target: null,
                suffix: '',
            };
    }
}

function capitalize(s: string): string {
    return s.charAt(0).toUpperCase() + s.slice(1);
}

/* ----------------------------------------------------------------------------
 * System-event icons (used when there's no actor)
 * ------------------------------------------------------------------------- */
const isSystemEvent = (k: ActivityKind): boolean => k === 'workspace.created' || k === 'invitation.expired';

function iconForSystemEvent(k: ActivityKind) {
    if (k === 'workspace.created') return Rocket;
    if (k === 'invitation.expired') return Trash2;
    return UserPlus;
}
</script>

<template>
    <div class="relative">
        <!-- Vertical line — sits behind the avatars -->
        <div v-if="entries.length > 1" class="bg-border absolute top-3 bottom-3 left-[13px] w-px" aria-hidden="true" />

        <ul class="relative space-y-1">
            <li v-for="entry in entries" :key="entry.id" class="flex items-start gap-3 py-1.5">
                <!-- Avatar / system icon -->
                <div class="z-10 shrink-0">
                    <component
                        v-if="isSystemEvent(entry.kind)"
                        :is="iconForSystemEvent(entry.kind)"
                        class="bg-muted text-muted-foreground ring-card size-[26px] rounded-full p-1.5 ring-2"
                    />
                    <UserPlus v-else-if="!entry.actor" class="bg-muted text-muted-foreground ring-card size-[26px] rounded-full p-1.5 ring-2" />
                    <div v-else class="ring-card rounded-full ring-2">
                        <AppAvatar :name="entry.actor.name" :email="entry.actor.email" :src="entry.actor.avatar_url" size="sm" />
                    </div>
                </div>

                <!-- Copy -->
                <div class="min-w-0 flex-1 pt-0.5">
                    <p class="text-[12.5px] leading-snug">
                        <span class="text-foreground font-medium">
                            {{ describe(entry).actor }}
                        </span>
                        <span class="text-muted-foreground"> {{ ' ' }}{{ describe(entry).verb }} </span>
                        <template v-if="describe(entry).target">
                            <span class="text-foreground font-medium"> {{ ' ' }}{{ describe(entry).target }} </span>
                        </template>
                        <template v-if="describe(entry).suffix">
                            <span class="text-muted-foreground"> {{ ' ' }}{{ describe(entry).suffix }} </span>
                        </template>
                    </p>
                    <p class="text-muted-foreground mt-0.5 text-[11px] tabular-nums">
                        {{ formatLastActive(entry.occurred_at) }}
                    </p>
                </div>
            </li>
        </ul>
    </div>
</template>
