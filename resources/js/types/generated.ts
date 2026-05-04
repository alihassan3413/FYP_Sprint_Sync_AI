export enum UserRole {
    OWNER = 'owner',
    MEMBER = 'member',
    ADMIN = 'admin',
}
export type WorkspaceData = {
    name: string;
    slug: string | null;
    settings: { [key: string]: any } | null;
    is_active: boolean;
};
export type WorkspaceInvitationData = {
    email: string;
    role: string;
};
