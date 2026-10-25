<?php

namespace App\Events;

use App\Models\Layer;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Core layer lifecycle. The model may already be deleted; listeners should use
 * organization_id / id from the instance, not re-query.
 */
class LayerDeleted
{
    use Dispatchable, SerializesModels;

    public function __construct(public Layer $layer) {}
}
