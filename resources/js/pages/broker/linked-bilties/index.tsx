import { Head, Link } from '@inertiajs/react';
import { Eye, Plus } from 'lucide-react';
import { AppIconAction } from '@/components/app-icon-action';
import { AppIndexListCard, AppIndexPage } from '@/components/app-index-page';
import { WorkspaceStatusBadge } from '@/components/workspace-status-badge';

type GroupRow = {
    id: string;
    group_number: string;
    group_date: string | null;
    status: string;
    vehicle_registration: string | null;
    member_count: number;
    urls: { show: string };
};

type Props = {
    filters: { status: string };
    permissions: { can_create: boolean };
    groups: {
        data: GroupRow[];
        meta: {
            current_page: number;
            last_page: number;
            total: number;
            per_page: number;
        };
    };
    urls: { index: string; create: string };
};

export default function LinkedBiltiesIndex({
    permissions,
    groups,
    urls,
}: Props) {
    return (
        <>
            <Head title="Linked Bilties" />
            <AppIndexPage>
                <AppIndexListCard
                    title="Linked Bilty Groups"
                    toolbar={
                        permissions.can_create ? (
                            <Link
                                href={urls.create}
                                className="app-index-header-btn"
                            >
                                <Plus className="h-3.5 w-3.5" />
                                Create group
                            </Link>
                        ) : null
                    }
                >
                    <div className="overflow-x-auto">
                        <table className="app-index-table min-w-full">
                            <thead>
                                <tr>
                                    <th>Group No</th>
                                    <th>Date</th>
                                    <th>Vehicle</th>
                                    <th className="text-center">Members</th>
                                    <th>Status</th>
                                    <th className="text-center">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                {groups.data.length === 0 ? (
                                    <tr>
                                        <td colSpan={6}>
                                            <p className="app-index-empty">
                                                No linked bilty groups yet.
                                            </p>
                                        </td>
                                    </tr>
                                ) : (
                                    groups.data.map((row) => (
                                        <tr key={row.id}>
                                            <td className="font-medium">
                                                {row.group_number}
                                            </td>
                                            <td>{row.group_date ?? '—'}</td>
                                            <td>
                                                {row.vehicle_registration ??
                                                    '—'}
                                            </td>
                                            <td className="text-center">
                                                {row.member_count}
                                            </td>
                                            <td>
                                                <WorkspaceStatusBadge
                                                    status={row.status}
                                                />
                                            </td>
                                            <td>
                                                <div className="flex justify-center">
                                                    <AppIconAction
                                                        icon={Eye}
                                                        label="Open linked group"
                                                        href={row.urls.show}
                                                        variant="info"
                                                    />
                                                </div>
                                            </td>
                                        </tr>
                                    ))
                                )}
                            </tbody>
                        </table>
                    </div>
                </AppIndexListCard>
            </AppIndexPage>
        </>
    );
}
