<?php

namespace Lumina\Disaster\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Lumina\Disaster\Models\Activation;

class ActivationProposedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Activation $activation) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $activation = $this->activation;

        return (new MailMessage)
            ->subject('Disaster activation proposed: '.$activation->hazard_type)
            ->line('A warning was received and a proposed activation was created.')
            ->line('Hazard: '.$activation->hazard_type)
            ->line('Severity: '.($activation->severity ?? 'n/a'))
            ->line('Source: '.$activation->source.' #'.$activation->source_alert_id)
            ->line($activation->advisory_text ?? '')
            ->action(
                'View activation',
                url('/x/disaster/activations/'.$activation->id),
            );
    }
}
