<script setup lang="ts">
import AuthBase from '@/layouts/AuthLayout.vue';
import { Head } from '@inertiajs/vue3';
import { CalendarClock, Clock, ExternalLink, FolderKanban, Hourglass } from 'lucide-vue-next';

const props = defineProps<{
    meeting: {
        title: string;
        agenda: string | null;
        scheduled_at: string;
        duration_minutes: number;
        has_started: boolean;
        project_name: string | null;
    };
    joinUrl: string | null;
    isInternal: boolean;
}>();

const when = computed(() =>
    new Date(props.meeting.scheduled_at).toLocaleString(undefined, {
        weekday: 'short',
        month: 'short',
        day: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
    }),
);

const duration = computed(() =>
    props.meeting.duration_minutes < 60
        ? `${props.meeting.duration_minutes} min`
        : `${Math.floor(props.meeting.duration_minutes / 60)}h ${props.meeting.duration_minutes % 60 || ''}`.trim(),
);
</script>

<template>
    <Head :title="meeting.title" />

    <AuthBase>
        <div class="flex flex-col gap-6">
            <div class="text-center">
                <h1 class="text-2xl font-semibold tracking-tight">{{ meeting.title }}</h1>
                <p class="text-muted-foreground mt-2 text-sm">You have been invited to this meeting on SprintSync.</p>
            </div>

            <div class="bg-card rounded-xl border p-4 shadow-sm">
                <div class="text-muted-foreground grid gap-2 text-xs">
                    <div class="flex items-center gap-2">
                        <CalendarClock class="size-3.5" />
                        <span class="text-foreground">{{ when }}</span>
                    </div>

                    <div class="flex items-center gap-2">
                        <Clock class="size-3.5" />
                        <span>{{ duration }}</span>
                    </div>

                    <div v-if="meeting.project_name" class="flex items-center gap-2">
                        <FolderKanban class="size-3.5" />
                        <span>{{ meeting.project_name }}</span>
                    </div>
                </div>

                <div v-if="meeting.agenda" class="mt-4 border-t pt-3">
                    <p class="text-muted-foreground text-[11px] font-medium tracking-[0.06em] uppercase">Agenda</p>
                    <p class="text-foreground mt-1 text-sm leading-relaxed whitespace-pre-line">{{ meeting.agenda }}</p>
                </div>
            </div>

            <Button v-if="joinUrl" as-child class="w-full gap-2">
                <a :href="joinUrl" target="_blank" rel="noopener noreferrer">
                    <ExternalLink class="size-4" />
                    Join meeting
                </a>
            </Button>

            <div v-else class="bg-muted/20 flex items-start gap-3 rounded-xl border border-dashed p-4">
                <Hourglass class="text-muted-foreground mt-0.5 size-4 shrink-0" />
                <div class="text-xs leading-relaxed">
                    <p class="text-foreground font-medium">No conferencing link yet</p>
                    <p class="text-muted-foreground mt-1">
                        The organiser has not added a call link for this meeting. Check back closer to the start time.
                    </p>
                </div>
            </div>
        </div>
    </AuthBase>
</template>
