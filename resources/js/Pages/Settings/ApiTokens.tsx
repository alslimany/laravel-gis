import { FormEvent } from 'react';
import { useForm } from '@inertiajs/react';
import { useTranslation, __ } from '@/lib/i18n';
import AppLayout from '@/layouts/app-layout';
import { when } from '@/lib/format';
import {
    DangerButton,
    Empty,
    Field,
    PageHeader,
    Panel,
    PrimaryButton,
    Table,
    TextInput,
    destroyResource,
    DataTable,
    thClass,
    tdClass,
    tdMuted,
    tdMono,
} from '@/components/gis';

type AbilityOption = { key: string; description: string };

type TokenRow = {
    id: number | string;
    name: string;
    abilities: string[] | null;
    last_used_at: string | null;
    destroy_url: string;
};

type Props = {
    tokens?: TokenRow[];
    plainTextToken?: string | null;
    abilityCatalog?: AbilityOption[];
    store_url: string;
};

export function isLegacyFullAccess(abilities: string[] | null | undefined): boolean {
    return Array.isArray(abilities) && abilities.includes('*');
}

export function formatAbilities(
    abilities: string[] | null | undefined,
    legacyLabel: string,
    emptyLabel: string,
): string {
    if (isLegacyFullAccess(abilities)) {
        return legacyLabel;
    }
    const list = (abilities || []).filter(Boolean);
    return list.length ? list.join(', ') : emptyLabel;
}

export default function Index({
    tokens = [],
    plainTextToken = null,
    abilityCatalog = [],
    store_url,
}: Props) {
    const { t } = useTranslation();
    const form = useForm({ name: '', abilities: [] as string[] });

    const toggleAbility = (key: string) => {
        const current = form.data.abilities;
        form.setData(
            'abilities',
            current.includes(key) ? current.filter((item) => item !== key) : [...current, key],
        );
    };

    function submit(event: FormEvent) {
        event.preventDefault();
        form.post(store_url, {
            onSuccess: () => form.reset('name', 'abilities'),
        });
    }

    return (
        <AppLayout title={t('settings.api_tokens')}>
            <PageHeader title={t('settings.api_tokens')} />
            {plainTextToken ? (
                <p className="mb-4 rounded-md border border-amber bg-amber/10 px-3 py-2 font-mono text-amber" dir="ltr">
                    {__('tokens.copy_now')} {plainTextToken}
                </p>
            ) : null}
            <form className="mb-6 max-w-xl space-y-4" onSubmit={submit}>
                <Field label={__('tokens.name')} error={form.errors.name}>
                    <TextInput
                        value={form.data.name}
                        required
                        placeholder={__('tokens.name_ph')}
                        onChange={(event) => form.setData('name', event.target.value)}
                    />
                </Field>
                <fieldset>
                    <legend className="mb-2 font-medium text-foreground">{__('tokens.abilities')}</legend>
                    <p className="mb-2 text-sm text-muted-foreground">{__('tokens.abilities_hint')}</p>
                    <div className="space-y-2">
                        {abilityCatalog.map((ability) => (
                            <label key={ability.key} className="flex items-start gap-2">
                                <input
                                    type="checkbox"
                                    className="mt-1"
                                    checked={form.data.abilities.includes(ability.key)}
                                    onChange={() => toggleAbility(ability.key)}
                                />
                                <span>
                                    <span dir="ltr" className="font-mono text-sm">
                                        {ability.key}
                                    </span>
                                    <span className="mt-0.5 block text-sm text-muted-foreground">
                                        {ability.description}
                                    </span>
                                </span>
                            </label>
                        ))}
                    </div>
                    {form.errors.abilities ? (
                        <p className="mt-1 text-sm text-destructive">{form.errors.abilities}</p>
                    ) : null}
                    {form.errors['abilities.0'] ? (
                        <p className="mt-1 text-sm text-destructive">{form.errors['abilities.0']}</p>
                    ) : null}
                </fieldset>
                <PrimaryButton type="submit" disabled={form.processing}>
                    {__('tokens.create')}
                </PrimaryButton>
            </form>
            <p className="mb-4 text-muted-foreground">{__('tokens.intro')}</p>
            <Panel>
                {tokens.length === 0 ? (
                    <Empty>{__('tokens.empty')}</Empty>
                ) : (
                    <DataTable>
                        <Table.Header>
                            <Table.Row>
                                <Table.Head className={thClass}>{t('forms.name')}</Table.Head>
                                <Table.Head className={thClass}>{t('users.abilities')}</Table.Head>
                                <Table.Head className={thClass}>{__('tokens.last_used')}</Table.Head>
                                <Table.Head className={`${thClass} text-end`}>{t('forms.actions')}</Table.Head>
                            </Table.Row>
                        </Table.Header>
                        <Table.Body>
                            {tokens.map((token) => (
                                <Table.Row key={token.id}>
                                    <Table.Cell className={tdClass}>{token.name}</Table.Cell>
                                    <Table.Cell
                                        className={isLegacyFullAccess(token.abilities) ? tdClass : tdMono}
                                        dir={isLegacyFullAccess(token.abilities) ? undefined : 'ltr'}
                                    >
                                        {formatAbilities(
                                            token.abilities,
                                            __('tokens.legacy_full_access'),
                                            t('common.em_dash'),
                                        )}
                                    </Table.Cell>
                                    <Table.Cell className={tdMuted}>
                                        {when(token.last_used_at) === t('common.em_dash')
                                            ? __('tokens.never')
                                            : when(token.last_used_at)}
                                    </Table.Cell>
                                    <Table.Cell className={`${tdClass} text-end`}>
                                        <DangerButton
                                            onClick={() =>
                                                destroyResource(token.destroy_url, __('tokens.confirm_revoke'))
                                            }
                                        >
                                            {__('tokens.revoke')}
                                        </DangerButton>
                                    </Table.Cell>
                                </Table.Row>
                            ))}
                        </Table.Body>
                    </DataTable>
                )}
            </Panel>
        </AppLayout>
    );
}
