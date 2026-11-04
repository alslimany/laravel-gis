import { Link, useForm } from '@inertiajs/react';
import { useTranslation } from '@/lib/i18n';
import AppLayout from '@/layouts/app-layout';
import { Empty, Field, PageHeader, Panel, PrimaryButton, TextInput } from '@/components/gis';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { when } from '@/lib/format';
import AdvisoryBanner from './AdvisoryBanner';

type TokenRow = {
    id: number;
    label?: string | null;
    abilities: string[];
    revoked: boolean;
    last_used_at?: string | null;
    created_at?: string | null;
};

type Props = {
    advisory: string;
    authority?: { name: string; url?: string | null } | null;
    tokens: TokenRow[];
    plain_token?: string | null;
    public_base: string;
    issue_token_url: string;
    can_public_publish: boolean;
};

export default function PublicTokens({
    advisory,
    authority = null,
    tokens,
    plain_token = null,
    public_base,
    issue_token_url,
    can_public_publish,
}: Props) {
    const { t } = useTranslation();
    const tokenForm = useForm({ label: t('disaster.public_feed_default') });

    function issueToken(event: React.FormEvent) {
        event.preventDefault();
        tokenForm.post(issue_token_url, { preserveScroll: true });
    }

    return (
        <AppLayout
            title={t('disaster.public_tokens_short')}
            breadcrumbs={[{ title: t('disaster.title'), href: '/x/disaster' }, { title: t('disaster.public_tokens_short') }]}
        >
            <PageHeader title={t('disaster.public_tokens')} />
            <AdvisoryBanner advisory={advisory} authority={authority} className="mb-6 max-w-2xl text-sm text-muted-foreground" />

            {plain_token ? (
                <Panel className="mb-6 border-amber-500/40 bg-amber-500/5 p-3">
                    <p className="text-sm font-medium">{t('disaster.copy_public_token')}</p>
                    <code className="mt-2 block break-all text-xs">{plain_token}</code>
                    <p className="mt-2 text-xs text-muted-foreground">
                        {t('disaster.public_url', { url: `${public_base}/${plain_token}` })}
                    </p>
                </Panel>
            ) : null}

            <Panel>
                <div className="border-b border-border px-3 py-2">
                    <h2 className="text-sm font-semibold">{t('disaster.tokens_heading')}</h2>
                </div>
                {can_public_publish ? (
                    <form onSubmit={issueToken} className="flex flex-wrap items-end gap-2 p-3">
                        <div className="min-w-[12rem] flex-1">
                            <Field label={t('forms.label')}>
                                <TextInput
                                    value={tokenForm.data.label}
                                    onChange={(e) => tokenForm.setData('label', e.target.value)}
                                />
                            </Field>
                        </div>
                        <PrimaryButton type="submit" disabled={tokenForm.processing}>
                            {t('disaster.issue_token')}
                        </PrimaryButton>
                    </form>
                ) : (
                    <p className="p-3 text-sm text-muted-foreground">{t('disaster.public_publish_required')}</p>
                )}
                {tokens.length === 0 ? (
                    <Empty>{t('disaster.empty.tokens')}</Empty>
                ) : (
                    <ul className="divide-y divide-border">
                        {tokens.map((token) => (
                            <li key={token.id} className="flex flex-wrap items-center gap-2 px-3 py-2 text-sm">
                                <span className="font-medium">
                                    {token.label || t('disaster.token_fallback', { id: token.id })}
                                </span>
                                <Badge variant="secondary">
                                    {token.revoked ? t('disaster.revoked') : t('disaster.active')}
                                </Badge>
                                {!token.revoked && can_public_publish ? (
                                    <Button asChild variant="ghost" size="sm" className="ms-auto">
                                        <Link
                                            href={`/x/disaster/public-tokens/${token.id}`}
                                            method="delete"
                                            as="button"
                                            preserveScroll
                                        >
                                            {t('disaster.revoke')}
                                        </Link>
                                    </Button>
                                ) : (
                                    <span className="ms-auto text-xs text-muted-foreground">
                                        {token.created_at ? when(token.created_at) : ''}
                                    </span>
                                )}
                            </li>
                        ))}
                    </ul>
                )}
            </Panel>
        </AppLayout>
    );
}
