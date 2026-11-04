<?php

namespace Lumina\Disaster\Services;

use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Lumina\Disaster\Models\Activation;
use Lumina\Disaster\Models\EscalationRule;
use Lumina\Disaster\Models\WarningAck;
use Lumina\Disaster\Models\WarningAuditLog;
use Lumina\Disaster\Models\WarningRecipient;
use Lumina\Disaster\Notifications\ActivationProposedNotification;
use Lumina\Disaster\Notifications\WarningEscalatedNotification;
use Lumina\Disaster\Support\Enums\WarningAckResponse;

class WarningAcknowledgementService
{
    /**
     * Seed initial recipients (org admins preferred, else dispatch-capable users),
     * create pending ack rows, notify, and write audit events.
     *
     * @return list<WarningRecipient>
     */
    public function seedInitialRecipients(Activation $activation): array
    {
        $channel = (string) config('disaster.warning_ack.default_channel', 'email');
        $users = $this->resolveInitialUsers((int) $activation->organization_id);

        $created = [];
        foreach ($users as $user) {
            $role = $user->isAdmin() ? 'admin' : 'dispatch';
            $recipient = $this->addRecipient($activation, $user, $role, $channel, notify: true, eventType: 'notified');
            if ($recipient !== null) {
                $created[] = $recipient;
            }
        }

        return $created;
    }

    /**
     * Escalate unacked activations past their rule timeouts. Idempotent per rule/activation.
     *
     * @return int Number of new recipients created
     */
    public function escalateDue(int $organizationId): int
    {
        $rules = EscalationRule::query()
            ->forOrganization($organizationId)
            ->orderBy('sort_order')
            ->orderBy('timeout_minutes')
            ->orderBy('id')
            ->get();

        if ($rules->isEmpty()) {
            return 0;
        }

        $activations = Activation::query()
            ->forOrganization($organizationId)
            ->whereIn('status', ['proposed', 'active'])
            ->with(['warningRecipients.ack'])
            ->get();

        $created = 0;

        foreach ($activations as $activation) {
            if ($this->activationStopsEscalation($activation)) {
                continue;
            }

            if ($activation->warningRecipients->isEmpty()) {
                continue;
            }

            $clockStart = $activation->warningRecipients
                ->map(fn (WarningRecipient $recipient) => $recipient->ack?->received_at)
                ->filter()
                ->min();

            if ($clockStart === null) {
                continue;
            }

            foreach ($rules as $rule) {
                if ($clockStart->copy()->addMinutes((int) $rule->timeout_minutes)->isFuture()) {
                    continue;
                }

                if ($this->ruleAlreadyApplied($activation, $rule)) {
                    continue;
                }

                $pending = $activation->warningRecipients->filter(
                    fn (WarningRecipient $recipient) => $recipient->ack?->isPending() ?? false,
                );

                $created += $this->applyEscalationRule($activation, $rule, $pending);
                $activation->load(['warningRecipients.ack']);
            }
        }

        return $created;
    }

    public function acknowledge(
        Activation $activation,
        User $user,
        WarningAckResponse $response = WarningAckResponse::Acknowledged,
    ): WarningAck {
        $recipient = WarningRecipient::query()
            ->where('activation_id', $activation->id)
            ->where('organization_id', $activation->organization_id)
            ->where('user_id', $user->id)
            ->first();

        if ($recipient === null) {
            $channel = (string) config('disaster.warning_ack.default_channel', 'email');
            $recipient = WarningRecipient::query()->create([
                'activation_id' => $activation->id,
                'organization_id' => $activation->organization_id,
                'user_id' => $user->id,
                'role' => $user->isAdmin() ? 'admin' : 'dispatch',
                'channel' => $channel,
            ]);
        }

        $ack = WarningAck::query()->firstOrNew(['recipient_id' => $recipient->id]);
        if (! $ack->exists) {
            $ack->received_at = now();
        }

        $ack->forceFill([
            'acknowledged_at' => now(),
            'response' => $response,
        ])->save();

        WarningAuditLog::record(
            (int) $activation->id,
            (int) $activation->organization_id,
            $response === WarningAckResponse::Declined ? 'declined' : 'acknowledged',
            [
                'recipient_id' => $recipient->id,
                'user_id' => $user->id,
                'response' => $response->value,
            ],
        );

        return $ack;
    }

    /**
     * @return Collection<int, User>
     */
    protected function resolveInitialUsers(int $organizationId): Collection
    {
        $users = User::query()
            ->where('organization_id', $organizationId)
            ->get();

        $recipients = $users->filter(function (User $user) {
            return Gate::forUser($user)->allows('disaster.dispatch')
                || $user->isAdmin();
        });

        $admins = $recipients->filter(fn (User $user) => $user->isAdmin());
        if ($admins->isNotEmpty()) {
            return $admins->values();
        }

        return $recipients->values();
    }

    /**
     * @return Collection<int, User>
     */
    protected function usersForRole(int $organizationId, string $role): Collection
    {
        if ($role === 'dispatch') {
            return User::query()
                ->where('organization_id', $organizationId)
                ->get()
                ->filter(fn (User $user) => Gate::forUser($user)->allows('disaster.dispatch') && ! $user->isAdmin())
                ->values();
        }

        return User::query()
            ->where('organization_id', $organizationId)
            ->whereHas('roles', fn ($query) => $query->where('name', $role))
            ->get();
    }

    protected function addRecipient(
        Activation $activation,
        User $user,
        string $role,
        string $channel,
        bool $notify,
        string $eventType,
        ?EscalationRule $rule = null,
    ): ?WarningRecipient {
        $exists = WarningRecipient::query()
            ->where('activation_id', $activation->id)
            ->where('user_id', $user->id)
            ->where('role', $role)
            ->exists();

        if ($exists) {
            return null;
        }

        $recipient = WarningRecipient::query()->create([
            'activation_id' => $activation->id,
            'organization_id' => $activation->organization_id,
            'user_id' => $user->id,
            'role' => $role,
            'channel' => $channel,
        ]);

        WarningAck::query()->create([
            'recipient_id' => $recipient->id,
            'received_at' => now(),
            'acknowledged_at' => null,
            'response' => null,
        ]);

        if ($notify) {
            $this->notifyRecipient($user, $activation, $channel, $eventType === 'escalated');
        }

        WarningAuditLog::record(
            (int) $activation->id,
            (int) $activation->organization_id,
            $eventType,
            [
                'recipient_id' => $recipient->id,
                'user_id' => $user->id,
                'role' => $role,
                'channel' => $channel,
                'escalation_rule_id' => $rule?->id,
            ],
        );

        return $recipient;
    }

    protected function notifyRecipient(User $user, Activation $activation, string $channel, bool $escalated): void
    {
        $notification = $escalated
            ? new WarningEscalatedNotification($activation)
            : new ActivationProposedNotification($activation);

        match ($channel) {
            'email' => Notification::send([$user], $notification),
            'telegram' => $this->notifyTelegram($user, $activation, $escalated),
            default => Log::channel((string) config('disaster.warning_ack.log_channel', 'stack'))
                ->info('disaster.warning_ack', [
                    'channel' => $channel,
                    'escalated' => $escalated,
                    'activation_id' => $activation->id,
                    'user_id' => $user->id,
                    'organization_id' => $activation->organization_id,
                ]),
        };
    }

    protected function notifyTelegram(User $user, Activation $activation, bool $escalated): void
    {
        $token = (string) config('disaster.warning_ack.telegram.bot_token', '');
        $chatId = (string) config('disaster.warning_ack.telegram.chat_id', '');

        if ($token === '' || $chatId === '') {
            Log::info('disaster.warning_ack.telegram_skipped', [
                'reason' => 'not_configured',
                'activation_id' => $activation->id,
                'user_id' => $user->id,
                'escalated' => $escalated,
            ]);

            return;
        }

        // Real Telegram send is optional; log is enough for Wave 1 tests.
        Log::info('disaster.warning_ack.telegram', [
            'activation_id' => $activation->id,
            'user_id' => $user->id,
            'chat_id' => $chatId,
            'escalated' => $escalated,
        ]);
    }

    protected function activationStopsEscalation(Activation $activation): bool
    {
        return $activation->warningRecipients->contains(
            fn (WarningRecipient $recipient) => $recipient->ack?->stopsEscalation() ?? false,
        );
    }

    protected function ruleAlreadyApplied(Activation $activation, EscalationRule $rule): bool
    {
        return WarningAuditLog::query()
            ->where('activation_id', $activation->id)
            ->where('organization_id', $activation->organization_id)
            ->where('event_type', 'escalated')
            ->where('payload->escalation_rule_id', $rule->id)
            ->exists();
    }

    /**
     * @param  Collection<int, WarningRecipient>  $pending
     */
    protected function applyEscalationRule(Activation $activation, EscalationRule $rule, Collection $pending): int
    {
        foreach ($pending as $recipient) {
            $ack = $recipient->ack;
            if ($ack === null || ! $ack->isPending()) {
                continue;
            }

            $ack->forceFill([
                'response' => WarningAckResponse::Escalated,
                'acknowledged_at' => now(),
            ])->save();
        }

        $users = $this->usersForRole((int) $activation->organization_id, $rule->next_recipient_role);
        $created = 0;

        if ($users->isEmpty()) {
            WarningAuditLog::record(
                (int) $activation->id,
                (int) $activation->organization_id,
                'escalated',
                [
                    'escalation_rule_id' => $rule->id,
                    'next_recipient_role' => $rule->next_recipient_role,
                    'channel' => $rule->channel,
                    'recipients_created' => 0,
                    'note' => 'no_users_for_role',
                ],
            );

            return 0;
        }

        foreach ($users as $user) {
            $recipient = $this->addRecipient(
                $activation,
                $user,
                $rule->next_recipient_role,
                $rule->channel,
                notify: true,
                eventType: 'escalated',
                rule: $rule,
            );
            if ($recipient !== null) {
                $created++;
            }
        }

        if ($created === 0) {
            // Idempotent marker so the rule is not re-applied endlessly when users already exist.
            WarningAuditLog::record(
                (int) $activation->id,
                (int) $activation->organization_id,
                'escalated',
                [
                    'escalation_rule_id' => $rule->id,
                    'next_recipient_role' => $rule->next_recipient_role,
                    'channel' => $rule->channel,
                    'recipients_created' => 0,
                    'note' => 'recipients_already_present',
                ],
            );
        }

        return $created;
    }
}
