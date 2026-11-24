import { Head, Link } from '@inertiajs/react';
import { Eye } from 'lucide-react';
import { AppIconAction } from '@/components/app-icon-action';
import { PageShell } from '@/components/page-shell';
import { WorkspaceStatusBadge } from '@/components/workspace-status-badge';
import { Button } from '@/components/ui/button';

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
            <PageShell
                title="Linked Bilty Groups"
                actions={
                    permissions.can_create ? (
                        <Link href={urls.create}>
                            <Button className="bg-[#2FB36F] text-white hover:bg-[#279960]">
                                Create group
                            </Button>
                        </Link>
                    ) : null
                }
            >
                <div className="overflow-x-auto rounded-lg border border-border">
                    <table className="w-full min-w-[640px] text-sm">
                        <thead className="border-b bg-muted/40 text-left text-muted-foreground">
                            <tr>
                                <th className="px-4 py-3 font-medium">
                                    Group No
                                </th>
                                <th className="px-4 py-3 font-medium">Date</th>
                                <th className="px-4 py-3 font-medium">
                                    Vehicle
                                </th>
                                <th className="px-4 py-3 font-medium">
                                    Members
                                </th>
                                <th className="px-4 py-3 font-medium">
                                    Status
                                </th>
                                <th className="px-4 py-3 font-medium" />
                            </tr>
                        </thead>
                        <tbody>
                            {groups.data.length === 0 ? (
                                <tr>
                                    <td
                                        colSpan={6}
                                        className="px-4 py-8 text-center text-muted-foreground"
                                    >
                                        No linked bilty groups yet.
                                    </td>
                                </tr>
                            ) : (
                                groups.data.map((row) => (
                                    <tr
                                        key={row.id}
                                        className="border-b border-border/60"
                                    >
                                        <td className="px-4 py-3 font-medium">
                                            {row.group_number}
                                        </td>
                                        <td className="px-4 py-3">
                                            {row.group_date ?? '—'}
                                        </td>
                                        <td className="px-4 py-3">
                                            {row.vehicle_registration ?? '—'}
                                        </td>
                                        <td className="px-4 py-3">
                                            {row.member_count}
                                        </td>
                                        <td className="px-4 py-3">
                                            <WorkspaceStatusBadge
                                                status={row.status}
                                            />
                                        </td>
                                        <td className="px-4 py-3 text-right">
                                            <AppIconAction
                                                icon={Eye}
                                                label="Open linked bilty group"
                                                href={row.urls.show}
                                                variant="info"
                                            />
                                        </td>
                                    </tr>
                                ))
                            )}
                        </tbody>
                    </table>
                </div>
            </PageShell>
        </>
    );
}
