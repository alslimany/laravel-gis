<?php

namespace Lumina\Disaster\Support\Pdf;

use App\Support\EnumLabel;
use Carbon\CarbonImmutable;

/** Small view helpers for the PDF reports: localized dates/enums and "لا يوجد" for empty values. */
final class ReportFormat
{
    public static function none(): string
    {
        return __('pdf.none');
    }

    public static function isEmpty(mixed $value): bool
    {
        if (is_array($value)) {
            return array_filter($value, fn ($v) => ! self::isEmpty($v)) === [];
        }

        return $value === null || (is_string($value) && trim($value) === '');
    }

    public static function text(mixed $value): string
    {
        return self::isEmpty($value) ? self::none() : (string) $value;
    }

    public static function enum(mixed $value, string ...$prefixes): string
    {
        return self::isEmpty($value) ? self::none() : EnumLabel::for($value, null, ...$prefixes);
    }

    public static function date(mixed $value): string
    {
        if (self::isEmpty($value)) {
            return self::none();
        }
        try {
            $date = $value instanceof \DateTimeInterface ? CarbonImmutable::instance($value) : CarbonImmutable::parse((string) $value);
        } catch (\Throwable) {
            return (string) $value;
        }

        return $date->timezone((string) config('disaster.report_timezone', config('app.timezone')))
            ->locale(app()->getLocale())
            ->translatedFormat('j F Y، H:i');
    }

    /**
     * Flattens a structured JSON value (casualties, resources, lifelines status) into label/value rows.
     *
     * @return list<array{0: string, 1: string}>
     */
    public static function rows(mixed $value, string $prefix = ''): array
    {
        if (! is_array($value)) {
            return self::isEmpty($value) ? [] : [[$prefix === '' ? '' : self::label($prefix), (string) $value]];
        }
        $rows = [];
        foreach ($value as $key => $item) {
            $label = is_int($key) ? ($prefix === '' ? '#'.($key + 1) : $prefix.' '.($key + 1)) : self::label((string) $key);
            if (is_array($item)) {
                if (array_is_list($item) && array_filter($item, 'is_scalar') === $item) {
                    $item = implode('، ', array_map(fn ($v) => self::scalar($v), $item));
                    if (! self::isEmpty($item)) {
                        $rows[] = [$label, $item];
                    }

                    continue;
                }
                foreach (self::rows($item) as [$k, $v]) {
                    $rows[] = [trim($label.' — '.$k, ' —'), $v];
                }

                continue;
            }
            if (! self::isEmpty($item)) {
                $rows[] = [$label, self::scalar($item)];
            }
        }

        return $rows;
    }

    private static function scalar(mixed $value): string
    {
        return match (true) {
            is_bool($value) => $value ? __('common.yes') : __('common.no'),
            is_string($value) && preg_match('/^[a-z]+(_[a-z]+)+$/', $value) === 1 => EnumLabel::for($value),
            default => (string) $value,
        };
    }

    private static function label(string $key): string
    {
        $full = 'field.'.EnumLabel::key($key);
        $hit = __($full);

        return $hit !== $full ? $hit : EnumLabel::for($key);
    }
}
