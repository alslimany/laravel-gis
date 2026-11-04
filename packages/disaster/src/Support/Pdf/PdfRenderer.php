<?php

namespace Lumina\Disaster\Support\Pdf;

use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Mpdf\Config\ConfigVariables;
use Mpdf\Config\FontVariables;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;

/**
 * Renders the disaster report views (COP, SitRep) to PDF with mPDF.
 *
 * dompdf cannot shape Arabic (letters come out isolated and left-to-right), so these reports use
 * mPDF, which does OpenType shaping + the Unicode bidi algorithm and embeds a subset of
 * IBM Plex Sans Arabic (bundled in resources/fonts, OFL). Latin text uses the same font.
 */
final class PdfRenderer
{
    public const FONT = 'plexarabic';

    /** @param array<string, mixed> $data */
    public static function html(string $view, array $data): string
    {
        return view($view, $data + ['pdfFont' => self::FONT])->render();
    }

    /** @param array<string, mixed> $data */
    public static function bytes(string $view, array $data): string
    {
        $rtl = self::isRtl();
        $mpdf = new Mpdf(self::config($rtl));
        $mpdf->SetDirectionality($rtl ? 'rtl' : 'ltr');
        $mpdf->WriteHTML(self::wrapDigits(self::html($view, $data)));

        return $mpdf->Output('', Destination::STRING_RETURN);
    }

    /**
     * PDF download; falls back to printable HTML (auto print) if the engine fails.
     *
     * @param  array<string, mixed>  $data
     */
    public static function download(string $view, array $data, string $filename): Response
    {
        try {
            return response(self::bytes($view, $data), 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            ]);
        } catch (\Throwable $e) {
            Log::warning('disaster.pdf.render_failed', ['view' => $view, 'error' => $e->getMessage()]);

            return response(self::html($view, $data + ['autoPrint' => true]), 200, [
                'Content-Type' => 'text/html; charset=UTF-8',
            ]);
        }
    }

    /**
     * Digits inside Arabic runs: with OpenType shaping on, mPDF maps Plex Arabic's digit glyphs to
     * private-use code points, so they print correctly but copy/search/pdftotext as garbage.
     * Setting digit runs in the Latin face (DejaVu Sans, embedded) keeps them real "0-9" text.
     */
    public static function wrapDigits(string $html): string
    {
        $body = strpos($html, '<body');
        if ($body === false) {
            return $html;
        }
        $head = substr($html, 0, $body);
        $rest = substr($html, $body);
        // Leave <style>/<script> bodies alone (CSS like `margin: 12px` must not grow spans).
        $parts = preg_split('#(<(style|script)\b[^>]*>.*?</\2>)#is', $rest, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$rest];
        $out = '';
        for ($i = 0, $n = count($parts); $i < $n; $i++) {
            $part = $parts[$i];
            if (preg_match('#^<(style|script)\b#i', $part)) {
                $out .= $part;
                $i++; // skip the captured tag name group

                continue;
            }
            $out .= (string) preg_replace_callback('/>([^<]+)</u', function (array $m): string {
                $text = (string) preg_replace('/(\d[\d:.,\/]*)/u', '<span class="pdf-num">$1</span>', $m[1]);

                return '>'.$text.'<';
            }, $part);
        }
        $rest = $out;

        return $head.$rest;
    }

    public static function isRtl(): bool
    {
        return in_array(app()->getLocale(), ['ar', 'fa', 'he', 'ur'], true);
    }

    /** @return array<string, mixed> */
    private static function config(bool $rtl): array
    {
        $fontDirs = (new ConfigVariables)->getDefaults()['fontDir'];
        $fontData = (new FontVariables)->getDefaults()['fontdata'];
        $tmp = storage_path('app/mpdf');
        if (! is_dir($tmp)) {
            @mkdir($tmp, 0775, true);
        }

        return [
            'mode' => 'utf-8',
            'format' => 'A4',
            'tempDir' => $tmp,
            'fontDir' => [...$fontDirs, dirname(__DIR__, 3).'/resources/fonts'],
            'fontdata' => $fontData + [
                self::FONT => [
                    'R' => 'IBMPlexSansArabic-Regular.ttf',
                    'B' => 'IBMPlexSansArabic-Bold.ttf',
                    'useOTL' => 0xFF,
                    'useKashida' => 75,
                ],
            ],
            'default_font' => self::FONT,
            'directionality' => $rtl ? 'rtl' : 'ltr',
            'autoScriptToLang' => true,
            'autoLangToFont' => false,
            'margin_left' => 12,
            'margin_right' => 12,
            'margin_top' => 14,
            'margin_bottom' => 14,
        ];
    }
}
