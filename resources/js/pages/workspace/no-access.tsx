import { Form, Head, Link } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import ShellEmptyState from '@/components/shell-empty-state';
import { logout } from '@/routes';

type NoAccessProps = {
    userName: string;
    supportHint: string;
    canCreateCompany: boolean;
    links: {
        createCompany: string | null;
    };
};

export default function WorkspaceNoAccess({
    userName,
    supportHint,
    canCreateCompany,
    links,
}: NoAccessProps) {
    const showCreate = canCreateCompany && Boolean(links.createCompany);

    return (
        <>
            <Head title="No workspace access" />
            <div className="mx-auto flex w-full max-w-2xl flex-1 flex-col justify-center gap-6 p-6">
                <div className="space-y-1">
                    <h1 className="text-2xl font-semibold tracking-tight">
                        No workspace access
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        Signed in as {userName}
                    </p>
                </div>

                <ShellEmptyState
                    title="No accessible company"
                    description={
                        showCreate
                            ? 'You do not have access to a company yet. Create your company to continue as Owner of that workspace.'
                            : supportHint
                    }
                    action={
                        <div className="mt-2 flex flex-col items-stretch gap-2 sm:flex-row sm:items-center">
                            {showCreate && links.createCompany ? (
                                <Button asChild>
                                    <Link
                                        href={links.createCompany}
                                        aria-label="Create your company"
                                    >
                                        Create your company
                                    </Link>
                                </Button>
                            ) : null}
                            <Form {...logout.form()}>
                                {({ processing }) => (
                                    <Button
                                        type="submit"
                                        variant={
                                            showCreate ? 'outline' : 'default'
                                        }
                                        disabled={processing}
                                    >
                                        Sign out
                                    </Button>
                                )}
                            </Form>
                        </div>
                    }
                />
            </div>
        </>
    );
}
