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
