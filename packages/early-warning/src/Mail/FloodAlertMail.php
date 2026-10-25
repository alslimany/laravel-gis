<?php

namespace Lumina\EarlyWarning\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Lumina\EarlyWarning\Models\AlertLog;
use Lumina\EarlyWarning\Support\AdvisoryCopy;

class FloodAlertMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public AlertLog $log) {}

    public function envelope(): Envelope
    {
        $basin = $this->log->basin_code;
        $rule = $this->log->payload['rule_name'] ?? 'Alert';

        return new Envelope(
            subject: "[Early Warning] {$rule} — {$basin}",
        );
    }

    public function content(): Content
    {
        return new Content(
            htmlString: $this->htmlBody(),
        );
    }

    protected function htmlBody(): string
    {
        $p = $this->log->payload ?? [];
        $advisory = e((string) ($p['advisory'] ?? AdvisoryCopy::message()));
        $basin = e($this->log->basin_code);
        $rule = e((string) ($p['rule_name'] ?? '—'));
        $metric = e((string) ($p['metric'] ?? '—'));
        $value = e((string) ($p['value'] ?? '—'));
        $threshold = e((string) ($p['threshold'] ?? '—'));
        $level = e((string) ($p['risk_level'] ?? '—'));
        $score = e((string) ($p['score'] ?? '—'));
        $at = e((string) ($p['forecast_at'] ?? '—'));
        $authorityHtml = '';
        $authorityLine = AdvisoryCopy::authorityLine();
        if ($authorityLine !== null) {
            $authority = AdvisoryCopy::authority();
            if ($authority !== null && $authority['url'] !== null) {
                $authorityHtml = '<p>Official authority: <a href="'.e($authority['url']).'">'.e($authority['name']).'</a></p>';
            } else {
                $authorityHtml = '<p>'.e($authorityLine).'</p>';
            }
        }

        return <<<HTML
        <p><strong>Early Warning alert</strong> (decision support only — not an official warning)</p>
        <p role="note">{$advisory}</p>
        {$authorityHtml}
        <ul>
            <li>Basin: {$basin}</li>
            <li>Rule: {$rule}</li>
            <li>Metric: {$metric} = {$value} (threshold {$threshold})</li>
            <li>Risk: {$level} / score {$score}</li>
            <li>Forecast hour: {$at}</li>
        </ul>
        HTML;
    }
}
