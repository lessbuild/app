// The JSON the account endpoints return.

export type MemberRow = {
    membershipId: string;
    name: string;
    email: string;
    role: string;
    joinedAt: string | null;
    isYou: boolean;
    manageable: boolean;
    serviceAccess: string[] | null;
    canLimitServices: boolean;
    projectAccess: string[] | null;
    deployProtected: boolean;
};

export type InvitationRow = { id: string; email: string; role: string; invitedBy: string | null; expiresAt: string };

export type RoleOption = { value: string; label: string; description: string };

export type MembersPage = {
    account: { id: string; name: string };
    overview: { viewerRole: string | null; canManage: boolean; members: MemberRow[]; invitations: InvitationRow[]; assignableRoles: string[] };
    invitationDays: number;
    roles: RoleOption[];
    services: Array<{ key: string; name: string }>;
    projects: Array<{ id: string; name: string }>;
};
