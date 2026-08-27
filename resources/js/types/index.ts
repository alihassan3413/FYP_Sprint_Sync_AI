import type { PageProps } from '@inertiajs/core';
import type { LucideIcon } from 'lucide-vue-next';

export interface Auth {
    user: User;
    timezone: string | null;
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

export interface NavigationContext {
    projects: boolean;
    team: boolean;
    analytics: boolean;
    archive: boolean;
    audit: boolean;
    workspaceSettings: boolean;
}

export interface SharedData extends PageProps {
    auth: Auth;
    workspace: WorkspaceContext | null;
    notifications: NotificationsContext | null;
    navigation: NavigationContext | null;
    attachments: AttachmentLimits | null;
}

/** Mirrors config/attachments.php so the browser can pre-validate uploads. */
export interface AttachmentLimits {
    max_kilobytes: number;
    allowed_extensions: string[];
    max_per_task: number;
    max_per_comment: number;
}

export interface User {
    id: number;
    name: string;
    email: string;
    avatar_url: string | null;
    email_verified_at: string | null;
    /** Platform administrator. Unrelated to workspace roles. */
    is_super_admin: boolean;
    timezone: string | null;
    created_at: string;
    updated_at: string;
}

export type BreadcrumbItemType = BreadcrumbItem;
