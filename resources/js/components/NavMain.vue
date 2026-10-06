<script setup lang="ts">
import { SidebarGroup, SidebarGroupLabel, SidebarMenu, SidebarMenuButton, SidebarMenuItem } from '@/components/ui/sidebar';
import { type SharedData } from '@/types';
import { Link, usePage } from '@inertiajs/vue3';
import { computed, type Component } from 'vue';

interface NavItem {
    title: string;
    href: string;
    icon: Component;
}

withDefaults(
    defineProps<{
        items: NavItem[];
        label?: string;
    }>(),
    { label: 'Platform' },
);

const page = usePage<SharedData>();

/* Hrefs are absolute (route()); compare paths. A section stays selected on its own pages (/invoices/{id}). */
const currentPath = computed(() => page.url.split('?')[0].replace(/\/$/, ''));
function isActive(href: string): boolean {
    const path = new URL(href, 'http://localhost').pathname.replace(/\/$/, '');

    return currentPath.value === path || currentPath.value.startsWith(`${path}/`);
}
</script>

<template>
    <SidebarGroup class="px-2 py-0">
        <SidebarGroupLabel>{{ label }}</SidebarGroupLabel>
        <SidebarMenu>
            <SidebarMenuItem v-for="item in items" :key="item.title">
                <SidebarMenuButton as-child :is-active="isActive(item.href)" :tooltip="item.title">
                    <Link :href="item.href" :aria-current="isActive(item.href) ? 'page' : undefined">
                        <component :is="item.icon" />
                        <span>{{ item.title }}</span>
                    </Link>
                </SidebarMenuButton>
            </SidebarMenuItem>
        </SidebarMenu>
    </SidebarGroup>
</template>
