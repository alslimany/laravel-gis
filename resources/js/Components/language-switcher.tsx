import { usePage } from '@inertiajs/react';
import axios from 'axios';
import { useRef, useState } from 'react';
import { Languages } from 'lucide-react';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { useTranslation } from '@/lib/i18n';

type LocaleOption = {
    value: string;
    label: string;
    native: string;
};

export function LanguageSwitcher() {
    const { locale, localeOptions, localeUpdateUrl } = usePage().props as {
        locale?: string;
        localeOptions?: LocaleOption[];
        localeUpdateUrl?: string;
    };
    const { t } = useTranslation();
    const [processing, setProcessing] = useState(false);
    const inFlight = useRef(false);

    const options = localeOptions ?? [
        { value: 'en', label: 'English', native: 'English' },
        { value: 'ar', label: 'Arabic', native: 'العربية' },
    ];

    const current = options.find((option) => option.value === locale) ?? options[0];

    function switchLocale(next: string) {
        if (!localeUpdateUrl || next === locale || inFlight.current) {
            return;
        }
        inFlight.current = true; // a second click before the first finishes must not race it

        // Send the chosen value directly. useForm's setData() is async, so setData + put()
        // submitted the previous value (switching back to a language could silently fail).
        // Plain JSON request + full reload. Inertia's router got a 409 location response here
        // (Inertia::location), which works but shows as an error in the console. A full load also
        // refreshes <html lang dir>, the shared translations and strings computed at module load.
        setProcessing(true);
        axios
            .put(localeUpdateUrl, { locale: next }, { headers: { Accept: 'application/json' } })
            .then(() => window.location.reload())
            .catch(() => {
                inFlight.current = false;
                setProcessing(false);
            });
    }

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button type="button" variant="outline" size="sm" className="gap-1.5">
                    <Languages className="size-4" />
                    <span>{current?.value === 'ar' ? 'عربي' : 'EN'}</span>
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="min-w-40">
                <DropdownMenuLabel className="text-xs text-muted-foreground">
                    {t('common.language')}
                </DropdownMenuLabel>
                {options.map((option) => (
                    <DropdownMenuItem
                        key={option.value}
                        onClick={() => switchLocale(option.value)}
                        disabled={processing}
                    >
                        {option.native}
                    </DropdownMenuItem>
                ))}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
