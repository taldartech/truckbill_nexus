import { Form, Head } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import ShellEmptyState from '@/components/shell-empty-state';
import { logout } from '@/routes';

type NoAccessProps = {
    userName: string;
    supportHint: string;
};

export default function WorkspaceNoAccess({
    userName,
    supportHint,
}: NoAccessProps) {
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
                    description={supportHint}
                    action={
                        <Form {...logout.form()} className="mt-2">
                            {({ processing }) => (
                                <Button
                                    type="submit"
                                    variant="outline"
                                    disabled={processing}
                                >
                                    Sign out
                                </Button>
                            )}
                        </Form>
                    }
                />
            </div>
        </>
    );
}
