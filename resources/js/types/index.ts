import type { PageProps } from '@inertiajs/core';
import type { LucideIcon } from 'lucide-vue-next';

export interface Auth {
    user: User;
}

export interface BreadcrumbItem {
    title: string;
    href: string;
}

export interface NavItem {
    title: string;
    href: string;
    icon?: LucideIcon;
    isActive?: boolean;
}

export interface WorkspaceSummary {
    id: number;
    name: string;
    slug: string;
    role: string;
    is_current?: boolean;
}

export interface CurrentWorkspace {
    id: number;
    name: string;
    slug: string;
    role: string;
}

export interface WorkspaceContext {
    current: CurrentWorkspace | null;
    available: WorkspaceSummary[];
}

export interface NotificationItem {
    id: string;
    type: string | null;
    title: string;
    message: string;
    url: string | null;
    read_at: string | null;
    created_at: string;
}

export interface NotificationsContext {
    unread_count: number;
    recent: NotificationItem[];
}

export interface SharedData extends PageProps {
    auth: Auth;
    workspace: WorkspaceContext | null;
    notifications: NotificationsContext | null;
}

export interface User {
    id: number;
    name: string;
    email: string;
    avatar_url: string | null;
    email_verified_at: string | null;
    created_at: string;
    updated_at: string;
}

export type BreadcrumbItemType = BreadcrumbItem;
