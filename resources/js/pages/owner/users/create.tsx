import { Head, Link, router, usePage } from '@inertiajs/react';
import { Plus, Trash2 } from 'lucide-react';
import { useMemo, useState } from 'react';
import InputError from '@/components/input-error';
import {
    AppFormCard,
    AppFormGrid,
    AppFormPage,
    AppFormSection,
} from '@/components/app-form-page';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type RoleOption = { id: string; name: string; slug: string };

type BranchOption = { id: string; name: string };

type CompanyOption = {
    id: string;
    name: string;
    status: string;
    available_user_capacity: number;
    branches: BranchOption[];
};

type AssignmentDraft = {
    branch_id: string;
    role_id: string;
};

type CompanyBlock = {
    company_id: string;
    assignments: AssignmentDraft[];
};

type Props = {
    preselectedCompanyId: string | null;
    companyOptions: CompanyOption[];
    customerRoles: RoleOption[];
    links: {
        index: string;
        store: string;
        create?: string;
    };
};

type PageErrors = {
    errors?: Record<string, string>;
};

function emptyAssignment(roles: RoleOption[]): AssignmentDraft {
    return {
        branch_id: '',
        role_id: roles[0]?.id ?? '',
    };
}

function initialBlock(
    preselectedCompanyId: string | null,
    roles: RoleOption[],
): CompanyBlock[] {
    if (!preselectedCompanyId) {
        return [
            {
                company_id: '',
                assignments: [emptyAssignment(roles)],
            },
        ];
    }

    return [
        {
            company_id: preselectedCompanyId,
            assignments: [emptyAssignment(roles)],
        },
    ];
}

export default function OwnerUsersCreate({
    preselectedCompanyId,
    companyOptions,
    customerRoles,
    links,
}: Props) {
    const { errors } = usePage<PageErrors>().props;
    const [name, setName] = useState('');
    const [email, setEmail] = useState('');
    const [companyBlocks, setCompanyBlocks] = useState<CompanyBlock[]>(() =>
        initialBlock(preselectedCompanyId, customerRoles),
    );
    const [processing, setProcessing] = useState(false);

    const companyById = useMemo(
        () => new Map(companyOptions.map((row) => [row.id, row])),
        [companyOptions],
    );

    const usedCompanyIds = useMemo(
        () =>
            new Set(
                companyBlocks
                    .map((block) => block.company_id)
                    .filter((id) => id !== ''),
            ),
        [companyBlocks],
    );

    const refreshCompany = (index: number, companyId: string) => {
        setCompanyBlocks((prev) =>
            prev.map((block, i) =>
                i === index
                    ? {
                          company_id: companyId,
                          assignments: [emptyAssignment(customerRoles)],
                      }
                    : block,
            ),
        );
        if (links.create && companyId && companyBlocks.length === 1) {
            router.get(
                links.create,
                { company: companyId },
                { preserveState: false },
            );
        }
    };

    const updateAssignment = (
        blockIndex: number,
        assignmentIndex: number,
        field: keyof AssignmentDraft,
        value: string,
    ) => {
        setCompanyBlocks((prev) =>
            prev.map((block, i) => {
                if (i !== blockIndex) {
                    return block;
                }
                return {
                    ...block,
                    assignments: block.assignments.map((row, j) =>
                        j === assignmentIndex
                            ? { ...row, [field]: value }
                            : row,
                    ),
                };
            }),
        );
    };

    const addAssignment = (blockIndex: number) => {
        setCompanyBlocks((prev) =>
            prev.map((block, i) =>
                i === blockIndex
                    ? {
                          ...block,
                          assignments: [
                              ...block.assignments,
                              emptyAssignment(customerRoles),
                          ],
                      }
                    : block,
            ),
        );
    };

    const removeAssignment = (blockIndex: number, assignmentIndex: number) => {
        setCompanyBlocks((prev) =>
            prev.map((block, i) => {
                if (i !== blockIndex) {
                    return block;
                }
                const next = block.assignments.filter(
                    (_, j) => j !== assignmentIndex,
                );
                return {
                    ...block,
                    assignments:
                        next.length > 0
                            ? next
                            : [emptyAssignment(customerRoles)],
                };
            }),
        );
    };

    const addCompanyBlock = () => {
        const next = companyOptions.find((row) => !usedCompanyIds.has(row.id));
        setCompanyBlocks((prev) => [
            ...prev,
            {
                company_id: next?.id ?? '',
                assignments: [emptyAssignment(customerRoles)],
            },
        ]);
    };

    const removeCompanyBlock = (index: number) => {
        setCompanyBlocks((prev) =>
            prev.length <= 1 ? prev : prev.filter((_, i) => i !== index),
        );
    };

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        setProcessing(true);
        router.post(
            links.store,
            {
                name: name.trim() || undefined,
                email: email.trim(),
                companies: companyBlocks,
            },
            {
                onFinish: () => setProcessing(false),
            },
        );
    };

    return (
        <>
            <Head title="Owner · Add user" />
            <AppFormPage
                title="Add user"
                description="Invite a new Customer identity or extend access for an existing member. Branch and role assignments are explicit — no automatic all-branch access."
                actions={
                    <Button asChild variant="outline">
                        <Link href={links.index}>Back to users</Link>
                    </Button>
                }
            >
                <form onSubmit={submit} className="space-y-6">
                    <AppFormCard>
                        <AppFormSection
                            title="Identity"
                            description="Invitations use TruckBill account initialization — no plaintext passwords are issued from this screen."
                        >
                            <AppFormGrid cols={2}>
                                <div className="space-y-2">
                                    <Label htmlFor="name">Name</Label>
                                    <Input
                                        id="name"
                                        value={name}
                                        onChange={(e) =>
                                            setName(e.target.value)
                                        }
                                        autoComplete="name"
                                    />
                                    <InputError message={errors?.name} />
                                </div>
                                <div className="space-y-2">
                                    <Label htmlFor="email">Email</Label>
                                    <Input
                                        id="email"
                                        type="email"
                                        value={email}
                                        onChange={(e) =>
                                            setEmail(e.target.value)
                                        }
                                        required
                                        autoComplete="email"
                                    />
                                    <InputError message={errors?.email} />
                                </div>
                            </AppFormGrid>
                        </AppFormSection>
                    </AppFormCard>

                    {companyBlocks.map((block, blockIndex) => {
                        const company = companyById.get(block.company_id);

                        return (
                            <AppFormCard key={`company-block-${blockIndex}`}>
                                <AppFormSection
                                    title={`Company access${companyBlocks.length > 1 ? ` (${blockIndex + 1})` : ''}`}
                                    description={
                                        company
                                            ? `${company.available_user_capacity} billable seat(s) available in ${company.name}.`
                                            : 'Select a company, then choose branches and roles.'
                                    }
                                    actions={
                                        <div className="flex flex-wrap gap-2">
                                            {companyBlocks.length > 1 ? (
                                                <Button
                                                    type="button"
                                                    variant="ghost"
                                                    size="sm"
                                                    onClick={() =>
                                                        removeCompanyBlock(
                                                            blockIndex,
                                                        )
                                                    }
                                                >
                                                    <Trash2
                                                        className="mr-1 size-3.5"
                                                        aria-hidden
                                                    />
                                                    Remove company
                                                </Button>
                                            ) : null}
                                            <Button
                                                type="button"
                                                variant="outline"
                                                size="sm"
                                                onClick={() =>
                                                    addAssignment(blockIndex)
                                                }
                                                disabled={!block.company_id}
                                            >
                                                Add branch role
                                            </Button>
                                        </div>
                                    }
                                >
                                    <div className="space-y-2">
                                        <Label
                                            htmlFor={`company-${blockIndex}`}
                                        >
                                            Company
                                        </Label>
                                        <select
                                            id={`company-${blockIndex}`}
                                            className="flex h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm"
                                            value={block.company_id}
                                            onChange={(e) =>
                                                refreshCompany(
                                                    blockIndex,
                                                    e.target.value,
                                                )
                                            }
                                            required
                                        >
                                            <option value="">
                                                Select company…
                                            </option>
                                            {companyOptions.map((option) => (
                                                <option
                                                    key={option.id}
                                                    value={option.id}
                                                    disabled={
                                                        usedCompanyIds.has(
                                                            option.id,
                                                        ) &&
                                                        option.id !==
                                                            block.company_id
                                                    }
                                                >
                                                    {option.name} (
                                                    {
                                                        option.available_user_capacity
                                                    }{' '}
                                                    seats left)
                                                </option>
                                            ))}
                                        </select>
                                        <InputError
                                            message={
                                                errors?.[
                                                    `companies.${blockIndex}.company_id`
                                                ]
                                            }
                                        />
                                    </div>

                                    {block.assignments.map(
                                        (assignment, assignmentIndex) => (
                                            <div
                                                key={`assignment-${blockIndex}-${assignmentIndex}`}
                                                className="mt-4 grid gap-3 rounded-lg border border-border p-3 sm:grid-cols-3"
                                            >
                                                <div className="space-y-1">
                                                    <Label>Branch</Label>
                                                    <select
                                                        className="flex h-9 w-full rounded-md border border-input bg-transparent px-2 text-sm"
                                                        value={
                                                            assignment.branch_id
                                                        }
                                                        onChange={(e) =>
                                                            updateAssignment(
                                                                blockIndex,
                                                                assignmentIndex,
                                                                'branch_id',
                                                                e.target.value,
                                                            )
                                                        }
                                                        required
                                                        disabled={!company}
                                                    >
                                                        <option value="">
                                                            Select branch…
                                                        </option>
                                                        {(
                                                            company?.branches ??
                                                            []
                                                        ).map((branch) => (
                                                            <option
                                                                key={branch.id}
                                                                value={
                                                                    branch.id
                                                                }
                                                            >
                                                                {branch.name}
                                                            </option>
                                                        ))}
                                                    </select>
                                                </div>
                                                <div className="space-y-1">
                                                    <Label>Role</Label>
                                                    <select
                                                        className="flex h-9 w-full rounded-md border border-input bg-transparent px-2 text-sm"
                                                        value={
                                                            assignment.role_id
                                                        }
                                                        onChange={(e) =>
                                                            updateAssignment(
                                                                blockIndex,
                                                                assignmentIndex,
                                                                'role_id',
                                                                e.target.value,
                                                            )
                                                        }
                                                        required
                                                    >
                                                        <option value="">
                                                            Select role…
                                                        </option>
                                                        {customerRoles.map(
                                                            (role) => (
                                                                <option
                                                                    key={
                                                                        role.id
                                                                    }
                                                                    value={
                                                                        role.id
                                                                    }
                                                                >
                                                                    {role.name}
                                                                </option>
                                                            ),
                                                        )}
                                                    </select>
                                                </div>
                                                <div className="flex items-end">
                                                    <Button
                                                        type="button"
                                                        variant="ghost"
                                                        onClick={() =>
                                                            removeAssignment(
                                                                blockIndex,
                                                                assignmentIndex,
                                                            )
                                                        }
                                                        disabled={
                                                            block.assignments
                                                                .length <= 1
                                                        }
                                                    >
                                                        Remove
                                                    </Button>
                                                </div>
                                            </div>
                                        ),
                                    )}
                                </AppFormSection>
                            </AppFormCard>
                        );
                    })}

                    {companyOptions.length > usedCompanyIds.size ? (
                        <Button
                            type="button"
                            variant="outline"
                            className="gap-1.5"
                            onClick={addCompanyBlock}
                        >
                            <Plus className="size-3.5" aria-hidden />
                            Add another company
                        </Button>
                    ) : null}

                    <div className="flex flex-wrap gap-2">
                        <Button
                            type="submit"
                            disabled={processing}
                            className="bg-[#2FB36F] text-white hover:bg-[#279960]"
                        >
                            {processing ? 'Saving…' : 'Save user access'}
                        </Button>
                        <Button type="button" variant="outline" asChild>
                            <Link href={links.index}>Cancel</Link>
                        </Button>
                    </div>
                </form>
            </AppFormPage>
        </>
    );
}
