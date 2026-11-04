<?php

namespace App\Http\Controllers;

use App\Support\LuminaAbilities;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class ApiTokenController extends Controller
{
    public function __construct()
    {
        $this->middleware(['auth', 'organization']);
    }

    public function index(Request $request)
    {
        $tokens = $request->user()->tokens()->orderByDesc('created_at')->get()->map(fn ($token) => [
            'id' => $token->id,
            'name' => $token->name,
            'abilities' => $token->abilities,
            'last_used_at' => $token->last_used_at?->toIso8601String(),
            'destroy_url' => route('api-tokens.destroy', $token->id),
        ]);

        return Inertia::render('Settings/ApiTokens', [
            'tokens' => $tokens,
            'plainTextToken' => session('plainTextToken'),
            'abilityCatalog' => LuminaAbilities::catalogForUi(),
            'store_url' => route('api-tokens.store'),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            // Explicit list only — never default to ['*']. Empty means denied on every API route.
            'abilities' => 'present|array',
            'abilities.*' => [
                'string',
                'max:100',
                Rule::notIn(['*']),
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (! is_string($value) || ! LuminaAbilities::isAllowed($value)) {
                        $fail(__('tokens.invalid_ability'));
                    }
                },
            ],
        ]);

        $abilities = array_values(array_unique($validated['abilities']));

        $token = $request->user()->createToken(
            $validated['name'],
            $abilities
        );

        return redirect()->route('api-tokens.index')
            ->with('success', __('tokens.flash_created'))
            ->with('plainTextToken', $token->plainTextToken);
    }

    public function destroy(Request $request, string $tokenId)
    {
        $token = $request->user()->tokens()->whereKey($tokenId)->firstOrFail();
        $token->delete();

        return redirect()->route('api-tokens.index')
            ->with('success', __('tokens.flash_revoked'));
    }
}
