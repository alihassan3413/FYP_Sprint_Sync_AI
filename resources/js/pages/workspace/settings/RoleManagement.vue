<script setup lang="ts">
import { type BreadcrumbItem } from '@/types';

const { workspaceRoute } = useCurrentWorkspace();

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Dashboard',
        href: workspaceRoute('dashboard'),
    },
    {
        title: 'Settings',
        href: workspaceRoute('workspace.settings')
    },
    {
        title: 'Role Management',
        href: ''
    }
];
</script>

<template>
    <Head title="Roles Management" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-6 p-4 md:p-6 lg:p-8">

            <!-- Page Header -->
            <AppPageHeader
                eyebrow="Workspace"
                title="Roles Management"
                description="Create custom roles for your workspace."
            />
        </div>
    </AppLayout>
</template>

<style scoped>
div { color: blue; font-size: 18px; }
</style>
