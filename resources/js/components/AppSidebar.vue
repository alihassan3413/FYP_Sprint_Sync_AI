<script setup lang="ts">
import NavFooter from '@/components/NavFooter.vue';
import NavMain from '@/components/NavMain.vue';
import NavUser from '@/components/NavUser.vue';
import { Sidebar, SidebarContent, SidebarFooter, SidebarHeader, SidebarMenu, SidebarMenuButton, SidebarMenuItem } from '@/components/ui/sidebar';
import { type NavItem } from '@/types';
import { Link, usePage } from '@inertiajs/vue3';
import type { SharedData } from '@/types';
import { BookOpen, Folder, LayoutGrid, Users2 } from 'lucide-vue-next';
import AppLogo from './AppLogo.vue';

const page = usePage<SharedData>();
const currentWorkspace = computed(() => page.props.workspace?.current ?? null);


const mainNavItems = computed<NavItem[]>(() => {
    if (!currentWorkspace.value) {
        return [];
    }

    return [
        {
            title: 'Dashboard',
            href: route('dashboard', {
                workspace: currentWorkspace.value.slug,
            }),
            icon: LayoutGrid,
        },
        {
            title: 'Teams',
            href: route('workspace.teams.index', {
                workspace: currentWorkspace.value.slug,
            }),
            icon: Users2,
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
