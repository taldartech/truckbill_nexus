import { Form, Head } from '@inertiajs/react';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';

type Props = {
    token: string;
    email: string;
    requiresRegistration: boolean;
    passwordRules: string;
};

export default function AcceptInvitation({
    token,
    email,
    requiresRegistration,
    passwordRules,
}: Props) {
    return (
        <>
            <Head title="Accept invitation" />

            <Form
                method="post"
                action={`/invitations/accept/${token}`}
                className="flex flex-col gap-6"
                resetOnSuccess={['password', 'password_confirmation']}
            >
                {({ processing, errors }) => (
                    <div className="grid gap-6">
                        <div className="grid gap-2">
                            <Label htmlFor="email">Email</Label>
                            <Input
                                id="email"
                                type="email"
                                value={email}
                                readOnly
                                className="mt-1 block w-full"
                            />
                        </div>

                        {requiresRegistration && (
                            <>
                                <div className="grid gap-2">
                                    <Label htmlFor="name">Name</Label>
                                    <Input
                                        id="name"
                                        type="text"
                                        name="name"
                                        required
                                        autoFocus
                                        autoComplete="name"
                                        className="mt-1 block w-full"
                                    />
                                    <InputError message={errors.name} />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="password">Password</Label>
                                    <PasswordInput
                                        id="password"
                                        name="password"
                                        required
                                        autoComplete="new-password"
                                        className="mt-1 block w-full"
                                    />
                                    <InputError message={errors.password} />
                                    <p className="text-sm text-muted-foreground">
                                        {passwordRules}
                                    </p>
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="password_confirmation">
                                        Confirm password
                                    </Label>
                                    <PasswordInput
                                        id="password_confirmation"
                                        name="password_confirmation"
                                        required
                                        autoComplete="new-password"
                                        className="mt-1 block w-full"
                                    />
                                </div>
                            </>
                        )}

                        {!requiresRegistration && (
                            <p className="text-sm text-muted-foreground">
                                This invitation will add membership to your
                                existing TruckBill account. Your password will
                                not change.
                            </p>
                        )}

                        <InputError message={errors.token} />

                        <Button type="submit" disabled={processing}>
                            {processing && <Spinner />}
                            Accept invitation
                        </Button>
                    </div>
                )}
            </Form>
        </>
    );
}
