<?php

namespace Lumina\Disaster\Contracts;

use Lumina\Disaster\Models\DisseminationLog;

/**
 * Pluggable fan-out channel (SMS today; radio/partner later).
 */
interface DisseminationChannelAdapter
{
    public function channel(): string;

    public function supports(DisseminationLog $log): bool;

    public function send(DisseminationLog $log): void;
}
