<script setup lang="ts">
import NavFooter from '@/components/NavFooter.vue';
import NavMain from '@/components/NavMain.vue';
import NavUser from '@/components/NavUser.vue';
import { Sidebar, SidebarContent, SidebarFooter, SidebarHeader, SidebarMenu, SidebarMenuButton, SidebarMenuItem } from '@/components/ui/sidebar';
import { type NavItem } from '@/types';
import { Archive, BookOpen, Folder, FolderKanban, LayoutGrid, Users2 } from 'lucide-vue-next';

const { workspaceRoute } = useCurrentWorkspace();

const mainNavItems = computed<NavItem[]>(() => {
    return [
        {
            title: 'Dashboard',
            href: workspaceRoute('dashboard'),
            icon: LayoutGrid,
        },
        {
            title: 'Projects',
            href: workspaceRoute('workspace.projects.index'),
            icon: FolderKanban,
        },
        {
            title: 'Teams',
            href: workspaceRoute('workspace.teams.index'),
            icon: Users2,
        },
        {
            title: 'Archive',
            href: workspaceRoute('workspace.archive.index'),
            icon: Archive,
        },
    ];
});

const footerNavItems: NavItem[] = [
    {
        title: 'Github Repo',
        href: 'https://github.com/laravel/vue-starter-kit',
        icon: Folder,
    },
    {
        title: 'Documentation',
        href: 'https://laravel.com/docs/starter-kits',
        icon: BookOpen,
    },
];
</script>

<template>
    <Sidebar collapsible="icon" variant="inset">
        <SidebarHeader>
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton size="lg" as-child>
                        <WorkspaceSwitcher />
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarHeader>

        <SidebarContent>
            <NavMain :items="mainNavItems" />
        </SidebarContent>

        <SidebarFooter>
            <NavFooter :items="footerNavItems" />
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
