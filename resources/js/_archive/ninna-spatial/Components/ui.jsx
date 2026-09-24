import { Children } from 'react';
import { Link, router, usePage } from '@inertiajs/react';
import { Alert, EmptyState } from '@ninna-ui/feedback';
import { Field as NinnaField, Input, Textarea } from '@ninna-ui/forms';
import { Card, Table } from '@ninna-ui/data-display';
import { Badge, Button, Heading, Text } from '@ninna-ui/primitives';

export { Heading, Text };
import { HStack, VStack } from '@ninna-ui/layout';

export { Table };

/** Spatial Operate list/data tables: overflow, compact density, shared header rhythm. */
export function DataTable({ children, className = '' }) {
    return (
        <div className="overflow-x-auto">
            <Table className={`w-full text-left text-[13px] ${className}`}>{children}</Table>
        </div>
    );
}

export const thClass =
    'whitespace-nowrap border-b border-border bg-base-50 px-3 py-2 text-left text-[10px] font-medium uppercase tracking-wider text-base-600';
export const tdClass = 'border-t border-border px-3 py-2 align-middle text-base-content';
export const tdMuted = `${tdClass} text-base-600`;
export const tdMono = `${tdClass} font-mono tabular-nums text-base-600`;

/** Native selects keep the change-event contract. Classes match Input's outline/md styles. */
export const fieldClass =
    'h-10 w-full rounded-md border border-base-300 bg-base-100 px-4 text-sm text-base-content transition-all duration-200 focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/30 focus-visible:outline-none';

export function Field({ label, error, children, hint }) {
    const message = Array.isArray(error) ? error.filter(Boolean)[0] : error;

    return (
        <NinnaField label={label} helperText={hint} errorText={message || undefined} invalid={Boolean(message)}>
            {children}
        </NinnaField>
    );
}

export function TextInput({ className = '', invalid, 'aria-invalid': ariaInvalid, ...props }) {
    return <Input className={className} fullWidth invalid={Boolean(invalid || ariaInvalid)} aria-invalid={ariaInvalid} {...props} />;
}

export function TextArea({ className = '', invalid, 'aria-invalid': ariaInvalid, ...props }) {
    return <Textarea className={className} fullWidth invalid={Boolean(invalid || ariaInvalid)} aria-invalid={ariaInvalid} {...props} />;
}

export function Select({ className = '', children, size, ...props }) {
    return (
        <select className={`${fieldClass} ${className}`} size={typeof size === 'number' ? size : undefined} {...props}>
            {children}
        </select>
    );
}

export function PrimaryButton({ className = '', children, ...props }) {
    return (
        <Button color="primary" className={className} {...props}>
            {children}
        </Button>
    );
}

const linkSolid =
    'inline-flex h-9 items-center justify-center gap-2 rounded-md bg-primary px-3 text-[13px] font-medium text-primary-content transition-opacity hover:opacity-90 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary/40';
const linkOutline =
    'inline-flex h-9 items-center justify-center gap-2 rounded-md border border-border bg-transparent px-3 text-[13px] font-medium text-base-content transition-colors hover:bg-base-200 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary/40';

export function PrimaryLink({ href, className = '', children, ...props }) {
    return (
        <Link href={href} className={`${linkSolid} ${className}`} {...props}>
            {children}
        </Link>
    );
}

export function GhostLink({ href, className = '', children, ...props }) {
    return (
        <Link href={href} className={`${linkOutline} ${className}`} {...props}>
            {children}
        </Link>
    );
}

export function Flash() {
    const { flash, errors } = usePage().props;
    const messages = [flash?.success, flash?.status].filter(Boolean);
    const errorList = [...(flash?.error ? [flash.error] : []), ...(errors ? Object.values(errors).flat() : [])];

    if (!messages.length && !errorList.length) {
        return null;
    }

    return (
        <VStack>
            {messages.map((message) => (
                <Alert key={message} color="success" description={message} />
            ))}
            {errorList.map((message) => (
                <Alert key={message} color="danger" description={message} role="alert" />
            ))}
        </VStack>
    );
}

export function PageHeader({ title, action }) {
    return (
        <HStack align="end" justify="between" wrap>
            <Heading as="h1">{title}</Heading>
            {action}
        </HStack>
    );
}

export function Panel({ children, className = '' }) {
    return (
        <Card variant="outline" className={className}>
            {children}
        </Card>
    );
}

export function Empty({ children }) {
    const nodes = Children.toArray(children);
    const text = nodes
        .filter((node) => typeof node === 'string')
        .join(' ')
        .replace(/\s+/g, ' ')
        .trim();
    const extra = nodes.filter((node) => typeof node !== 'string');

    return (
        <EmptyState title={text || 'Nothing to show'}>
            {extra.length ? <Text>{extra}</Text> : null}
        </EmptyState>
    );
}

export function Pill({ live = false, tone = 'idle', children }) {
    const colors = {
        live: 'success',
        run: 'info',
        fail: 'danger',
        idle: 'neutral',
    };

    return (
        <Badge variant="soft" color={colors[live ? 'live' : tone] || 'neutral'} radius="full">
            {children}
        </Badge>
    );
}

export function Pager({ paginator }) {
    const links = paginator?.links || [];
    if (links.length <= 3) {
        return null;
    }

    return (
        <HStack as="nav" aria-label="Pagination">
            {links.map((link, index) =>
                link.url ? (
                    <ButtonLink key={`${link.label}-${index}`} href={link.url} variant={link.active ? 'solid' : 'ghost'} preserveScroll>
                        <span dangerouslySetInnerHTML={{ __html: link.label }} />
                    </ButtonLink>
                ) : (
                    <Text key={`${link.label}-${index}`} as="span" muted>
                        <span dangerouslySetInnerHTML={{ __html: link.label }} />
                    </Text>
                ),
            )}
        </HStack>
    );
}

export function destroyResource(url, message) {
    if (window.confirm(message)) {
        router.delete(url);
    }
}

export function RowActions({ children }) {
    return <HStack align="center">{children}</HStack>;
}

export function ActionLink({ href, children, hideOnPhone = false }) {
    return (
        <Link href={href} className={`text-primary hover:underline ${hideOnPhone ? 'hidden md:inline' : ''}`}>
            {children}
        </Link>
    );
}

export function DangerButton({ onClick, children }) {
    return (
        <Button type="button" variant="text" color="danger" onClick={onClick}>
            {children}
        </Button>
    );
}
