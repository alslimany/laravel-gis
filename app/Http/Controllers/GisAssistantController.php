<?php

namespace App\Http\Controllers;

use App\Ai\Agents\GisAssistant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Ai\Messages\Message;

class GisAssistantController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
        $this->middleware('organization');
    }

    /**
     * Chat endpoint for the GIS assistant agent.
     */
    public function chat(Request $request)
    {
        $validated = $request->validate([
            'message' => 'required|string|max:4000',
            'history' => 'sometimes|array',
            'history.*.role' => 'required_with:history|string|in:user,assistant',
            'history.*.content' => 'required_with:history|string',
        ]);

        $user = Auth::user();
        $history = collect($validated['history'] ?? [])
            ->map(fn (array $msg) => new Message($msg['role'], $msg['content']))
            ->all();

        try {
            $agent = GisAssistant::make(
                organizationId: $user->organization_id,
                userId: $user->id,
                history: $history,
            );

            $response = $agent->prompt($validated['message']);

            return response()->json([
                'success' => true,
                'reply' => $response->text ?? (string) $response,
                'usage' => $response->usage ?? null,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'error' => 'GIS assistant failed to respond.',
                'message' => $e->getMessage(),
            ], 500);
        }
    }
}
