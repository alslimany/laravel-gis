<?php

namespace Lumina\Disaster\Support;

use App\Models\User;
use App\Support\EnumLabel;
use BackedEnum;
use Illuminate\Support\Facades\Gate;

final class ConsoleAuth
{
    /**
     * @return array{
     *     can_manage: bool,
     *     can_dispatch: bool,
     *     can_field: bool,
     *     can_telemetry: bool,
     *     can_public_publish: bool,
     *     can_approve_evacuation: bool,
     *     can_command: bool,
     *     can_registry: bool
     * }
     */
    public static function flags(User $user): array
    {
        $manage = Gate::forUser($user)->allows('disaster.manage');

        return [
            'can_manage' => $manage,
            'can_dispatch' => Gate::forUser($user)->allows('disaster.dispatch') || $manage,
            'can_field' => Gate::forUser($user)->allows('disaster.field') || $manage,
            'can_telemetry' => Gate::forUser($user)->allows('disaster.telemetry') || $manage,
            'can_public_publish' => Gate::forUser($user)->allows('disaster.public_publish') || $manage,
            'can_approve_evacuation' => Gate::forUser($user)->allows('disaster.approve_evacuation'),
            'can_command' => Gate::forUser($user)->allows('disaster.command'),
            'can_registry' => Gate::forUser($user)->allows('disaster.registry'),
        ];
    }

    /**
     * @param  class-string<BackedEnum>  $enumClass
     * @return list<array{value: string, label: string}>
     */
    public static function enumOptions(string $enumClass): array
    {
        return array_map(
            static fn (BackedEnum $case) => [
                'value' => $case->value,
                'label' => EnumLabel::for($case),
            ],
            $enumClass::cases(),
        );
    }

    /**
     * @return list<string>
     */
    public static function enumValues(string $enumClass): array
    {
        return array_map(static fn (BackedEnum $case) => $case->value, $enumClass::cases());
    }
}
