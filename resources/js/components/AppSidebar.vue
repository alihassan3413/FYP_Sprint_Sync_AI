<script setup lang="ts">
import NavFooter from '@/components/NavFooter.vue';
import NavMain from '@/components/NavMain.vue';
import NavUser from '@/components/NavUser.vue';
import { Sidebar, SidebarContent, SidebarFooter, SidebarHeader, SidebarMenu, SidebarMenuButton, SidebarMenuItem } from '@/components/ui/sidebar';
import { type NavItem, type SharedData } from '@/types';
import { Archive, BarChart3, BookOpen, Folder, FolderKanban, LayoutGrid, Users2 } from 'lucide-vue-next';

const { workspaceRoute } = useCurrentWorkspace();
const page = usePage<SharedData>();

const navigation = computed(() => page.props.navigation);

const mainNavItems = computed<NavItem[]>(() => {
    const items: NavItem[] = [
        {
            title: 'Dashboard',
            href: workspaceRoute('dashboard'),
            icon: LayoutGrid,
        },
    ];

    if (navigation.value?.projects) {
        items.push({
            title: 'Projects',
            href: workspaceRoute('workspace.projects.index'),
            icon: FolderKanban,
        });
    }

    if (navigation.value?.team) {
        items.push({
            title: 'Teams',
            href: workspaceRoute('workspace.teams.index'),
            icon: Users2,
        });
    }

    if (navigation.value?.analytics) {
        items.push({
            title: 'Analytics',
            href: workspaceRoute('workspace.analytics.index'),
            icon: BarChart3,
        });
    }

    if (navigation.value?.archive) {
        items.push({
            title: 'Archive',
            href: workspaceRoute('workspace.archive.index'),
            icon: Archive,
        });
    }

    return items;
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
