import { Form, Head } from '@inertiajs/react';
import { useRef } from 'react';
import SecurityController from '@/actions/App/Http/Controllers/Settings/SecurityController';
import { FormSection } from '@/components/form-section';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { edit } from '@/routes/security';

// oxfmt-ignore
type Props = {
    passwordRules: string;
};

export default function Security(props: Props) {
    const passwordInput = useRef<HTMLInputElement>(null);
    const currentPasswordInput = useRef<HTMLInputElement>(null);

    return (
        <>
            <Head title="Security settings" />

            <h1 className="sr-only">Security settings</h1>

            <Form
                {...SecurityController.update.form()}
                options={{
                    preserveScroll: true,
                }}
                resetOnError={[
                    'password',
                    'password_confirmation',
                    'current_password',
                ]}
                resetOnSuccess
                onError={(errors) => {
                    if (errors.password) {
                        passwordInput.current?.focus();
                    }

                    if (errors.current_password) {
                        currentPasswordInput.current?.focus();
                    }
                }}
            >
                {({ errors, processing }) => (
                    <FormSection
                        title="Update password"
                        description="Ensure your account is using a long, random password to stay secure."
                        columns={2}
                        footer={
                            <Button
                                type="submit"
                                disabled={processing}
                                data-test="update-password-button"
                            >
                                Save password
                            </Button>
                        }
                    >
                        <div className="space-y-2 md:col-span-2">
                            <Label htmlFor="current_password">
                                Current password
                            </Label>

                            <PasswordInput
                                id="current_password"
                                ref={currentPasswordInput}
                                name="current_password"
                                className="mt-1 block w-full"
                                autoComplete="current-password"
                                placeholder="Current password"
                            />

                            <InputError message={errors.current_password} />
                        </div>

                        <div className="space-y-2">
                            <Label htmlFor="password">New password</Label>

                            <PasswordInput
                                id="password"
                                ref={passwordInput}
                                name="password"
                                className="mt-1 block w-full"
                                autoComplete="new-password"
                                placeholder="New password"
                                passwordrules={props.passwordRules}
                            />

                            <InputError message={errors.password} />
                        </div>

                        <div className="space-y-2">
                            <Label htmlFor="password_confirmation">
                                Confirm password
                            </Label>

                            <PasswordInput
                                id="password_confirmation"
                                name="password_confirmation"
                                className="mt-1 block w-full"
                                autoComplete="new-password"
                                placeholder="Confirm password"
                                passwordrules={props.passwordRules}
                            />

                            <InputError
                                message={errors.password_confirmation}
                            />
                        </div>
                    </FormSection>
                )}
            </Form>
        </>
    );
}

Security.layout = {
    breadcrumbs: [
        {
            title: 'Security settings',
            href: edit(),
        },
    ],
};
