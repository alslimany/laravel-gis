import { Children, type ReactNode } from 'react';
import { Link, router, usePage } from '@inertiajs/react';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Textarea } from '@/components/ui/textarea';
import { cn } from '@/lib/utils';

export { Table, TableBody, TableCell, TableHead, TableHeader, TableRow };

export function DataTable({ children, className = '' }: { children: ReactNode; className?: string }) {
    return (
        <div className="overflow-x-auto rounded-md border">
            <Table className={cn('w-full text-left text-sm', className)}>{children}</Table>
        </div>
    );
}

// Compat shims for archived Ninna Table.Header / Table.Body / Table.Row / Table.Head / Table.Cell usage
;(Table as typeof Table & { Header: typeof TableHeader; Body: typeof TableBody; Row: typeof TableRow; Head: typeof TableHead; Cell: typeof TableCell }).Header = TableHeader;
;(Table as any).Body = TableBody;
;(Table as any).Row = TableRow;
;(Table as any).Head = TableHead;
;(Table as any).Cell = TableCell;

export const thClass =
    'whitespace-nowrap bg-muted/40 px-3 py-2 text-left text-[10px] font-medium uppercase tracking-wider text-muted-foreground';
export const tdClass = 'px-3 py-2 align-middle text-foreground';
export const tdMuted = `${tdClass} text-muted-foreground`;
export const tdMono = `${tdClass} font-mono tabular-nums text-muted-foreground`;

export const fieldClass =
    'flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-xs transition-colors focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring';

export function Field({
    label,
    error,
    children,
    hint,
}: {
    label?: string;
    error?: string | string[] | null;
    children: ReactNode;
    hint?: string;
}) {
    const message = Array.isArray(error) ? error.filter(Boolean)[0] : error;

    return (
        <div className="grid gap-1.5">
            {label ? <Label>{label}</Label> : null}
            {children}
            {hint ? <p className="text-xs text-muted-foreground">{hint}</p> : null}
            {message ? <p className="text-xs text-destructive">{message}</p> : null}
        </div>
    );
}

export function TextInput({
    className = '',
    invalid,
    ...props
}: React.ComponentProps<typeof Input> & { invalid?: boolean }) {
    return <Input className={cn(className)} aria-invalid={Boolean(invalid) || props['aria-invalid']} {...props} />;
}

export function TextArea({
    className = '',
    invalid,
    ...props
}: React.ComponentProps<typeof Textarea> & { invalid?: boolean }) {
    return <Textarea className={cn(className)} aria-invalid={Boolean(invalid) || props['aria-invalid']} {...props} />;
}

export function Select({
    className = '',
    children,
    ...props
}: React.ComponentProps<'select'>) {
    return (
        <select className={cn(fieldClass, className)} {...props}>
            {children}
        </select>
    );
}

export function PrimaryButton({ className = '', children, ...props }: React.ComponentProps<typeof Button>) {
    return (
        <Button className={className} {...props}>
            {children}
        </Button>
    );
}

export function PrimaryLink({
    href,
    className = '',
    children,
    ...props
}: { href: string; className?: string; children: ReactNode } & Omit<React.ComponentProps<typeof Link>, 'href'>) {
    return (
        <Button asChild className={className}>
            <Link href={href} {...props}>
                {children}
            </Link>
        </Button>
    );
}

export function GhostLink({
    href,
    className = '',
    children,
    ...props
}: { href: string; className?: string; children: ReactNode } & Omit<React.ComponentProps<typeof Link>, 'href'>) {
    return (
        <Button asChild variant="outline" className={className}>
            <Link href={href} {...props}>
                {children}
            </Link>
        </Button>
    );
}

export function Flash() {
    const { flash, errors } = usePage().props;
    const messages = [flash?.success, flash?.status].filter(Boolean) as string[];
    const errorList = [
        ...(flash?.error ? [flash.error] : []),
        ...(errors ? Object.values(errors).flat() : []),
    ] as string[];

    if (!messages.length && !errorList.length) {
        return null;
    }

    return (
        <div className="grid gap-2">
            {messages.map((message) => (
                <Alert key={message}>
                    <AlertDescription>{message}</AlertDescription>
                </Alert>
            ))}
            {errorList.map((message) => (
                <Alert key={message} variant="destructive">
                    <AlertDescription>{message}</AlertDescription>
                </Alert>
            ))}
        </div>
    );
}

export function PageHeader({ title, action }: { title: ReactNode; action?: ReactNode }) {
    return (
        <div className="flex flex-wrap items-end justify-between gap-3">
            <h1 className="text-xl font-semibold tracking-tight">{title}</h1>
            {action}
        </div>
    );
}

export function Heading({
    as: Tag = 'h2',
    children,
    className = '',
}: {
    as?: 'h1' | 'h2' | 'h3' | 'h4';
    children: ReactNode;
    className?: string;
}) {
    return <Tag className={cn('text-lg font-semibold tracking-tight', className)}>{children}</Tag>;
}

export function Text({
    children,
    muted,
    className = '',
    as: Tag = 'p',
}: {
    children: ReactNode;
    muted?: boolean;
    className?: string;
    as?: 'p' | 'span' | 'div';
}) {
    return <Tag className={cn(muted ? 'text-muted-foreground' : '', className)}>{children}</Tag>;
}

export function Panel({ children, className = '' }: { children: ReactNode; className?: string }) {
    return <Card className={cn('p-4', className)}>{children}</Card>;
}

export function Empty({ children }: { children?: ReactNode }) {
    const nodes = Children.toArray(children);
    const text = nodes
        .filter((node) => typeof node === 'string')
        .join(' ')
        .replace(/\s+/g, ' ')
        .trim();
    const extra = nodes.filter((node) => typeof node !== 'string');

    return (
        <div className="rounded-md border border-dashed p-8 text-center text-sm text-muted-foreground">
            <p>{text || 'Nothing to show'}</p>
            {extra.length ? <div className="mt-3">{extra}</div> : null}
        </div>
    );
}

export function Pill({
    live = false,
    tone = 'idle',
    children,
}: {
    live?: boolean;
    tone?: 'live' | 'run' | 'fail' | 'idle';
    children: ReactNode;
}) {
    const variant = live || tone === 'live' ? 'default' : tone === 'fail' ? 'destructive' : 'secondary';

    return <Badge variant={variant}>{children}</Badge>;
}

export function Pager({
    paginator,
}: {
    paginator?: { links?: Array<{ url: string | null; label: string; active: boolean }> };
}) {
    const links = paginator?.links || [];
    if (links.length <= 3) {
        return null;
    }

    return (
        <nav aria-label="Pagination" className="flex flex-wrap gap-1">
            {links.map((link, index) =>
                link.url ? (
                    <Button key={`${link.label}-${index}`} asChild variant={link.active ? 'default' : 'ghost'} size="sm">
                        <Link href={link.url} preserveScroll>
                            <span dangerouslySetInnerHTML={{ __html: link.label }} />
                        </Link>
                    </Button>
                ) : (
                    <span
                        key={`${link.label}-${index}`}
                        className="inline-flex h-8 items-center px-2 text-sm text-muted-foreground"
                        dangerouslySetInnerHTML={{ __html: link.label }}
                    />
                ),
            )}
        </nav>
    );
}

export function destroyResource(url: string, message: string) {
    if (window.confirm(message)) {
        router.delete(url);
    }
}

export function RowActions({ children }: { children: ReactNode }) {
    return <div className="flex items-center gap-2">{children}</div>;
}

export function ActionLink({
    href,
    children,
    hideOnPhone = false,
}: {
    href: string;
    children: ReactNode;
    hideOnPhone?: boolean;
}) {
    return (
        <Link href={href} className={cn('text-primary hover:underline', hideOnPhone ? 'hidden md:inline' : '')}>
            {children}
        </Link>
    );
}

export function DangerButton({
    onClick,
    children,
}: {
    onClick?: () => void;
    children: ReactNode;
}) {
    return (
        <Button type="button" variant="ghost" className="text-destructive hover:text-destructive" onClick={onClick}>
            {children}
        </Button>
    );
}
