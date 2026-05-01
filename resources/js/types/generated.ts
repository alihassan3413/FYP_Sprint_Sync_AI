export enum UserRole {
    OWNER = 'owner',
    MEMBER = 'member',
    ADMIN = 'admin',
}
export type WorkspaceInvitationData = {
    email: string;
    role: string;
};
