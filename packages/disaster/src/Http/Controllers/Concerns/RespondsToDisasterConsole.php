<?php

namespace Lumina\Disaster\Http\Controllers\Concerns;

use Illuminate\Http\Request;

trait RespondsToDisasterConsole
{
    protected function wantsJsonResponse(Request $request): bool
    {
        return $request->expectsJson();
    }
}
