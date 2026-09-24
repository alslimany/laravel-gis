import { Button } from '@/components/ui/button';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import { cn } from '@/lib/utils';

/** Compact icon control used by the map dock (replaces Ninna IconButton). */
export function IconButton({
    icon,
    className = '',
    variant = 'ghost',
    color: _color,
    'aria-label': ariaLabel,
    title,
    ...props
}) {
    const mapped = variant === 'solid' ? 'default' : variant === 'outline' ? 'outline' : 'ghost';
    const label = ariaLabel || title;

    const button = (
        <Button
            type="button"
            size="icon"
            variant={mapped}
            aria-label={label}
            className={cn('size-9 shrink-0 rounded-md', className)}
            {...props}
        >
            {icon}
        </Button>
    );

    if (!label) {
        return button;
    }

    return (
        <Tooltip>
            <TooltipTrigger asChild>{button}</TooltipTrigger>
            <TooltipContent side="right" sideOffset={8}>
                {label}
            </TooltipContent>
        </Tooltip>
    );
}
