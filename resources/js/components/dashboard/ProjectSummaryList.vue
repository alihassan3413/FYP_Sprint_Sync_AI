<script setup lang="ts">
import { ArrowRight, FolderKanban } from 'lucide-vue-next';

export interface DashboardProjectSummary {
    id: number;
    name: string;
    total_tasks: number;
    completed_tasks: number;
    completion_percentage: number;
}

defineProps<{
    projects: DashboardProjectSummary[];
}>();

const { workspaceRoute } = useCurrentWorkspace();
</script>

<template>
    <div class="bg-card rounded-xl border p-5 shadow-sm sm:p-6">
        <div class="mb-4 flex items-center justify-between gap-3">
            <div class="flex items-center gap-2">
                <FolderKanban class="text-muted-foreground size-4" />
                <h3 class="text-[15px] font-semibold tracking-tight">Projects</h3>
            </div>

            <Link
                v-if="projects.length > 0"
                :href="workspaceRoute('workspace.projects.index')"
                class="text-muted-foreground hover:text-foreground inline-flex items-center gap-1 text-[11.5px] font-medium transition-colors"
            >
                View all
                <ArrowRight class="size-3" />
            </Link>
        </div>

        <ul v-if="projects.length > 0" class="divide-border/60 divide-y">
            <li v-for="project in projects" :key="project.id" class="py-3 first:pt-0 last:pb-0">
                <Link :href="workspaceRoute('workspace.projects.show', { project: project.id })" class="group flex items-center gap-3">
                    <div class="min-w-0 flex-1">
                        <p class="text-foreground truncate text-sm font-medium group-hover:underline">{{ project.name }}</p>
                        <p class="text-muted-foreground mt-0.5 text-xs tabular-nums">
                            <template v-if="project.total_tasks > 0">
                                {{ project.completed_tasks }} of {{ project.total_tasks }} tasks done ·
                                {{ project.total_tasks - project.completed_tasks }} open
                            </template>
                            <template v-else> No tasks yet </template>
                        </p>
                    </div>

                    <div class="flex shrink-0 items-center gap-2">
                        <div class="bg-muted h-1.5 w-16 overflow-hidden rounded-full">
                            <div class="h-full rounded-full bg-emerald-500" :style="{ width: `${project.completion_percentage}%` }" />
                        </div>
                        <span class="text-muted-foreground w-9 text-right text-xs tabular-nums">{{ project.completion_percentage }}%</span>
                    </div>
                </Link>
            </li>
        </ul>

        <p v-else class="text-muted-foreground py-6 text-center text-xs">No projects you can access yet.</p>
    </div>
</template>
