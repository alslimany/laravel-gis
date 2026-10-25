<?php

namespace App\Events;

use App\Models\Layer;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** Core layer lifecycle — plugins may recompute when basins/roads/buildings change. */
class LayerUpdated
{
    use Dispatchable, SerializesModels;

    public function __construct(public Layer $layer) {}
}
