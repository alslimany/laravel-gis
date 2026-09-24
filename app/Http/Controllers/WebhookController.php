<?php

namespace App\Http\Controllers;

use App\Models\Webhook;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;

class WebhookController extends Controller
{
    public function __construct()
    {
        $this->middleware(['auth', 'organization']);
    }

    public function index(Request $request)
    {
        $this->authorize('viewAny', Webhook::class);

        $webhooks = Webhook::query()
            ->where('organization_id', $request->user()->organization_id)
            ->orderByDesc('created_at')
            ->paginate(15);

        return Inertia::render('Webhooks/Index', [
            'webhooks' => $webhooks,
        ]);
    }

    public function create()
    {
        $this->authorize('create', Webhook::class);

        return Inertia::render('Webhooks/Editor', [
            'eventOptions' => $this->eventOptions(),
        ]);
    }

    public function store(Request $request)
    {
        $this->authorize('create', Webhook::class);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'url' => 'required|url|max:2048',
            'events' => 'required|array|min:1',
            'events.*' => 'string|max:100',
            'is_active' => 'sometimes|boolean',
            'secret' => 'nullable|string|max:255',
        ]);

        $webhook = Webhook::create([
            'organization_id' => $request->user()->organization_id,
            'name' => $validated['name'],
            'url' => $validated['url'],
            'events' => array_values($validated['events']),
            'is_active' => $request->boolean('is_active', true),
            'secret' => $validated['secret'] ?? Str::random(32),
        ]);

        return redirect()->route('webhooks.edit', $webhook)
            ->with('success', 'Webhook created successfully.');
    }

    public function edit(Webhook $webhook)
    {
        $this->authorize('update', $webhook);

        return Inertia::render('Webhooks/Editor', [
            'webhook' => $webhook,
            'eventOptions' => $this->eventOptions(),
        ]);
    }

    public function update(Request $request, Webhook $webhook)
    {
        $this->authorize('update', $webhook);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'url' => 'required|url|max:2048',
            'events' => 'required|array|min:1',
            'events.*' => 'string|max:100',
            'is_active' => 'sometimes|boolean',
            'secret' => 'nullable|string|max:255',
        ]);

        $webhook->update([
            'name' => $validated['name'],
            'url' => $validated['url'],
            'events' => array_values($validated['events']),
            'is_active' => $request->boolean('is_active', $webhook->is_active),
            'secret' => array_key_exists('secret', $validated)
                ? ($validated['secret'] ?: $webhook->secret)
                : $webhook->secret,
        ]);

        return redirect()->route('webhooks.edit', $webhook)
            ->with('success', 'Webhook updated successfully.');
    }

    public function destroy(Webhook $webhook)
    {
        $this->authorize('delete', $webhook);

        $webhook->delete();

        return redirect()->route('webhooks.index')
            ->with('success', 'Webhook deleted successfully.');
    }

    /**
     * @return list<string>
     */
    protected function eventOptions(): array
    {
        return [
            '*',
            'feature.created',
            'feature.updated',
            'feature.deleted',
            'layer.created',
            'layer.updated',
            'layer.deleted',
            'map.created',
            'map.updated',
            'map.deleted',
        ];
    }
}
