<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

class LocaleController extends Controller
{
    public function update(Request $request): Response
    {
        $validated = $request->validate([
            'locale' => ['required', 'in:en,ar'],
        ]);

        $request->session()->put('locale', $validated['locale']);

        if ($request->user()) {
            $request->user()->forceFill(['preferred_locale' => $validated['locale']])->save();
        }

        // The language switcher sends a plain JSON request and then reloads the page itself, so it
        // gets a quiet 204 (no Inertia 409 location response in the console).
        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->noContent();
        }

        $back = url()->previous() ?: url('/');

        // Inertia: answer with a full-page location visit, not a 303 + XHR re-render. A client-side
        // re-render could lose to an in-flight partial reload (e.g. the COP's usePoll `only:` reload,
        // which never refreshes the shared translations), leaving the page in the old language.
        // A full load also refreshes <html lang dir> and any strings computed at module load.
        if ($request->header('X-Inertia')) {
            return Inertia::location($back);
        }

        return redirect()->to($back);
    }
}
