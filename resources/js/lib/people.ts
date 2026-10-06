/**
 * People types. A person works for the workspace; they may or may not have a
 * SprintSync login (a User). Addressed by public_id, never the database id.
 */

export interface Department {
    public_id: string;
    name: string;
}

export interface LinkedUser {
    id: number;
    name: string;
    email: string;
}

export interface Person {
    public_id: string;
    name: string;
    email: string | null;
    title: string | null;
    department: Department | null;
    linked_user: LinkedUser | null;
    /** ISO datetime */
    created_at: string;
}

/** A workspace member who can be linked; linked_person is set when someone already has them. */
export interface LinkableUser {
    id: number;
    name: string;
    email: string;
    linked_person: string | null;
}
