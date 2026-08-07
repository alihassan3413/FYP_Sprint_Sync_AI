/**
 * Project-related types.
 *
 * Lives in `lib/` so any page or component can import without going through
 * a Vue file, mirroring the `Member` type in `lib/members.ts`.
 */

export interface Project {
    id: number;
    name: string;
    description: string | null;
    workspace_id: number;
    /** ISO datetime */
    created_at: string;
    /** ISO datetime */
    updated_at: string;
}

export type ProjectRoleValue = 'manager' | 'member';

export interface ProjectMember {
    id: number;
    name: string;
    email: string;
    role: ProjectRoleValue;
}

export const PROJECT_ROLES: { value: ProjectRoleValue; label: string; description: string }[] = [
    { value: 'manager', label: 'Manager', description: 'Can manage this project, its tasks, and its members.' },
    { value: 'member', label: 'Member', description: 'Can access this project and work on assigned tasks.' },
];
