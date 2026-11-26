import { Head, Link } from '@inertiajs/react';
import { AppIndexListCard, AppIndexPage } from '@/components/app-index-page';
import { Button } from '@/components/ui/button';

type Props = {
    inward: {
        id: string;
        inward_number: string;
        inward_date: string | null;
        challan_number: string | null;
        from_station_name: string | null;
        to_station_name: string | null;
        lines: Array<{
            id: string;
            lr_number: string | null;
            packages: string;
            weight_kg: string;
            disposition_label: string;
            create_dr_url: string | null;
        }>;
    };
    urls: { challan: string | null };
};

export default function PartloadInwardShow({ inward, urls }: Props) {
    return (
        <>
            <Head title={`Inward ${inward.inward_number}`} />
            <AppIndexPage>
                <AppIndexListCard
                    title={`Inward ${inward.inward_number}`}
                    toolbar={
                        urls.challan ? (
                            <Button asChild variant="outline" size="sm">
                                <Link href={urls.challan}>View Challan</Link>
                            </Button>
                        ) : null
                    }
                >
                    <div className="grid gap-3 p-4 md:grid-cols-3">
                        <div>
                            <div className="text-xs text-muted-foreground">
                                Date
                            </div>
                            <div className="font-medium">
                                {inward.inward_date}
                            </div>
                        </div>
                        <div>
                            <div className="text-xs text-muted-foreground">
                                Challan
                            </div>
                            <div className="font-medium">
                                {inward.challan_number}
                            </div>
                        </div>
                        <div>
                            <div className="text-xs text-muted-foreground">
                                Route
                            </div>
                            <div className="font-medium">
                                {inward.from_station_name} →{' '}
                                {inward.to_station_name}
                            </div>
                        </div>
                    </div>
                    <div className="overflow-x-auto border-t">
                        <table className="w-full min-w-[640px] text-left text-sm">
                            <thead className="border-b text-muted-foreground">
                                <tr>
                                    <th className="px-3 py-2 font-medium">
                                        Bilty
                                    </th>
                                    <th className="px-3 py-2 font-medium">
                                        Pkgs
                                    </th>
                                    <th className="px-3 py-2 font-medium">
                                        Weight
                                    </th>
                                    <th className="px-3 py-2 font-medium">
                                        Disposition
                                    </th>
                                    <th className="px-3 py-2 font-medium" />
                                </tr>
                            </thead>
                            <tbody>
                                {inward.lines.map((line) => (
                                    <tr
                                        key={line.id}
                                        className="border-b last:border-0"
                                    >
                                        <td className="px-3 py-2 font-medium">
                                            {line.lr_number}
                                        </td>
                                        <td className="px-3 py-2">
                                            {line.packages}
                                        </td>
                                        <td className="px-3 py-2">
                                            {line.weight_kg}
                                        </td>
                                        <td className="px-3 py-2">
                                            {line.disposition_label}
                                        </td>
                                        <td className="px-3 py-2 text-right">
                                            {line.create_dr_url ? (
                                                <Button
                                                    asChild
                                                    size="sm"
                                                    variant="outline"
                                                >
                                                    <Link
                                                        href={
                                                            line.create_dr_url
                                                        }
                                                    >
                                                        Create DR
                                                    </Link>
                                                </Button>
                                            ) : null}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </AppIndexListCard>
            </AppIndexPage>
        </>
    );
}
