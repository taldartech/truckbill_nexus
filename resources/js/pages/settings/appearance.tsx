import { Head } from '@inertiajs/react';
import AppearanceTabs from '@/components/appearance-tabs';
import { FormSection } from '@/components/form-section';
import { edit as editAppearance } from '@/routes/appearance';

export default function Appearance() {
    return (
        <>
            <Head title="Appearance settings" />

            <h1 className="sr-only">Appearance settings</h1>

            <FormSection
                title="Appearance"
                description="Update the appearance settings for your account."
                columns={1}
            >
                <AppearanceTabs />
            </FormSection>
        </>
    );
}

Appearance.layout = {
    breadcrumbs: [
        {
            title: 'Appearance settings',
            href: editAppearance(),
        },
    ],
};
