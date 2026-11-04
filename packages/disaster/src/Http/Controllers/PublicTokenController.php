<?php

namespace Lumina\Disaster\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Lumina\Disaster\Http\Controllers\Concerns\RespondsToDisasterConsole;
use Lumina\Disaster\Models\DisseminationLog;
use Lumina\Disaster\Models\PublicToken;
use Lumina\Disaster\Support\AdvisoryCopy;
use Lumina\Disaster\Support\ConsoleAuth;

class PublicTokenController extends Controller
{
    use RespondsToDisasterConsole;

    public function index(Request $request): Response
    {
        $organizationId = (int) $request->user()->organization_id;

        $tokens = PublicToken::query()
            ->where('organization_id', $organizationId)
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (PublicToken $token) => [
                'id' => $token->id,
                'label' => $token->label,
                'abilities' => $token->abilities ?? [],
                'revoked' => $token->revoked_at !== null,
                'last_used_at' => $token->last_used_at?->toIso8601String(),
                'created_at' => $token->created_at?->toIso8601String(),
            ])
            ->all();

        return Inertia::render('Disaster/PublicTokens', [
            ...AdvisoryCopy::forUi(),
            ...ConsoleAuth::flags($request->user()),
            'tokens' => $tokens,
            'plain_token' => $request->session()->get('dm_plain_token'),
            'public_base' => url('/dm/public'),
            'issue_token_url' => route('disaster.public-tokens.store'),
        ]);
    }

    public function store(Request $request): JsonResponse|RedirectResponse
    {
        Gate::authorize('disaster.public_publish');

        $organizationId = (int) $request->user()->organization_id;
        $data = $request->validate([
            'label' => ['nullable', 'string', 'max:120'],
        ]);

        $issued = PublicToken::issue($organizationId, $data['label'] ?? null, ['feed']);

        DisseminationLog::query()->create([
            'organization_id' => $organizationId,
            'channel' => 'public_token',
            'payload' => ['label' => $data['label'] ?? null, 'token_id' => $issued['model']->id],
            'created_at' => now(),
        ]);

        if ($this->wantsJsonResponse($request)) {
            return response()->json([
                'token' => $issued['plain'],
                'public_url' => route('disaster.public.show', ['token' => $issued['plain']]),
            ], 201);
        }

        return redirect()
            ->route('disaster.public-tokens.index')
            ->with('dm_plain_token', $issued['plain'])
            ->with('success', 'Public token created — copy it now.');
    }

    public function destroy(Request $request, PublicToken $publicToken): JsonResponse|RedirectResponse
    {
        Gate::authorize('disaster.public_publish');
        abort_unless((int) $publicToken->organization_id === (int) $request->user()->organization_id, 404);
        $publicToken->forceFill(['revoked_at' => now()])->save();

        if ($this->wantsJsonResponse($request)) {
            return response()->json(['ok' => true]);
        }

        return redirect()->route('disaster.public-tokens.index')->with('success', 'Public token revoked.');
    }
}
