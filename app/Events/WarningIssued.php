<?php

namespace App\Events;

use App\Support\WarningIssuedPayload;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Core contract: a plugin-issued early warning ready for optional Disaster activation.
 * Core never imports Early Warning or Disaster packages.
 */
class WarningIssued
{
    use Dispatchable, SerializesModels;

    public function __construct(public WarningIssuedPayload $payload) {}
}
