import AppLayout from '@/layouts/app-layout';
import { useTranslation } from '@/lib/i18n';
import { PageHeader } from '@/components/gis';

/**
 * Shown to an account that has no organization yet (e.g. a platform super
 * admin). Organization workspaces need membership; platform tools live under
 * /platform.
 */
export default function Setup() {
    const { t } = useTranslation();
    return (
        <AppLayout>
            <PageHeader title={t('organization.setup')} />
            <p className="mt-4 text-sm text-muted-foreground">
                Your account is not a member of an organization yet. Ask an organization admin to add you,
                or use the platform tools if you are a super admin.
            </p>
        </AppLayout>
    );
}
