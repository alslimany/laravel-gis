import AdvisoryBanner from './AdvisoryBanner';
import { useTranslation } from '@/lib/i18n';

type Props = {
    advisory: string;
    authority?: { name: string; url?: string | null } | null;
    open_incidents: { external_id: string; title: string }[];
    avl_included: boolean;
};

export default function Public({ advisory, authority = null, open_incidents, avl_included }: Props) {
    const { t } = useTranslation();
    return (
        <div className="mx-auto max-w-lg p-6">
            <h1 className="mb-2 text-lg font-semibold">{t('disaster.public_advisory')}</h1>
            <AdvisoryBanner advisory={advisory} authority={authority} />
            {avl_included ? <p className="text-sm text-destructive">{t('disaster.avl_exposed')}</p> : null}
            {open_incidents.length === 0 ? (
                <p className="mt-4 text-sm text-muted-foreground">{t('disaster.empty.public_incidents')}</p>
            ) : (
                <ul className="mt-4 list-disc ps-5 text-sm">
                    {open_incidents.map((i) => (
                        <li key={i.external_id}>{i.title}</li>
                    ))}
                </ul>
            )}
        </div>
    );
}
