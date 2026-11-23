import { Form, Head, usePage } from '@inertiajs/react';
import { Save } from 'lucide-react';
import ProfileController from '@/actions/App/Http/Controllers/Settings/ProfileController';
import { FormSection } from '@/components/form-section';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { edit } from '@/routes/profile';
import type { Auth } from '@/types';

type PageProps = {
    auth: Auth;
};

export default function Profile() {
    const { auth } = usePage<PageProps>().props;
    const user = auth.user;

    if (user === null) {
        return null;
    }

    return (
        <>
            <Head title="Profile settings" />
            <h1 className="sr-only">Profile settings</h1>
            <Form
                {...ProfileController.update.form()}
                options={{
                    preserveScroll: true,
                }}
            >
                {({ processing, errors }) => (
                    <FormSection
                        title="Profile"
                        description="Update your name. Email changes require authorized administration."
                        columns={2}
                        footer={
                            <Button
                                type="submit"
                                disabled={processing}
                                data-test="update-profile-button"
                            >
                                <Save className="size-4" />
                                Save
                            </Button>
                        }
                    >
                        <div className="space-y-2">
                            <Label htmlFor="name">Name</Label>
                            <Input
                                id="name"
                                defaultValue={user.name}
                                name="name"
                                required
                                autoComplete="name"
                                placeholder="Full name"
                            />
                            <InputError message={errors.name} />
                        </div>
                        <div className="space-y-2">
                            <Label htmlFor="email">Email address</Label>
                            <Input
                                id="email"
                                type="email"
                                value={user.email}
                                disabled
                                readOnly
                                autoComplete="username"
                            />
                            <p className="text-sm text-muted-foreground">
                                Email cannot be changed from this page. Contact
                                an authorized administrator if your email needs
                                updating.
                            </p>
                        </div>
                    </FormSection>
                )}
            </Form>
        </>
    );
}

Profile.layout = {
    breadcrumbs: [
        {
            title: 'Profile settings',
            href: edit(),
        },
    ],
};
