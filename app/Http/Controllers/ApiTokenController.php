<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;

class ApiTokenController extends Controller
{
    public function __construct()
    {
        $this->middleware(['auth', 'organization']);
    }

    public function index(Request $request)
    {
        $tokens = $request->user()->tokens()->orderByDesc('created_at')->get();

        return Inertia::render('Settings/ApiTokens', [
            'tokens' => $tokens,
            'plainTextToken' => session('plainTextToken'),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'abilities' => 'nullable|array',
            'abilities.*' => 'string|max:100',
        ]);

        $abilities = $validated['abilities'] ?? ['*'];

        $token = $request->user()->createToken(
            $validated['name'],
            $abilities
        );

        return redirect()->route('api-tokens.index')
            ->with('success', 'API token created. Copy it now — it will not be shown again.')
            ->with('plainTextToken', $token->plainTextToken);
    }

    public function destroy(Request $request, string $tokenId)
    {
        $token = $request->user()->tokens()->whereKey($tokenId)->firstOrFail();
        $token->delete();

        return redirect()->route('api-tokens.index')
            ->with('success', 'API token revoked.');
    }
}
