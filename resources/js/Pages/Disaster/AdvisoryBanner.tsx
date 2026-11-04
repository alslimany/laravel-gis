import { useTranslation } from '@/lib/i18n';

type Authority = {
    name: string;
    url?: string | null;
} | null;

type Props = {
    advisory: string;
    authority?: Authority;
    className?: string;
};

export default function AdvisoryBanner({
    advisory,
    authority = null,
    className = 'mb-4 max-w-2xl rounded-md bg-muted/50 px-3 py-2 text-sm text-muted-foreground',
}: Props) {
    const { t } = useTranslation();

    return (
        <p className={className} role="note">
            {advisory}
            {authority ? (
                <>
                    {' '}
                    {t('common.advisory_consult')}{' '}
                    {authority.url ? (
                        <a
                            href={authority.url}
                            className="font-medium text-foreground underline underline-offset-2"
                            target="_blank"
                            rel="noreferrer"
                        >
                            {authority.name}
                        </a>
                    ) : (
                        <span className="font-medium text-foreground">{authority.name}</span>
                    )}
                    .
                </>
            ) : null}
        </p>
    );
}
