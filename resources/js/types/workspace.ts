export type WorkspaceCompany = {
    id: string;
    name: string;
    status: string;
    relationship: 'Owner' | 'Member';
    is_owner: boolean;
};

export type WorkspaceBranch = {
    id: string;
    name: string;
    status: string;
};

export type WorkspaceShare = {
    companies: WorkspaceCompany[];
    active_company_id: string | null;
    active_branch_id: string | null;
} | null;
