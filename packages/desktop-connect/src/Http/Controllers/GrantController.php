<?php

namespace Lumina\DesktopConnect\Http\Controllers;

use App\Models\Group;
use App\Models\Layer;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Lumina\DesktopConnect\Models\ApiKey;
use Lumina\DesktopConnect\Models\Grant;
use Lumina\DesktopConnect\Services\DesktopKeyManager;
use Lumina\DesktopConnect\Services\GrantManager;

/**
 * Desktop connections access screen (DG-1.5): list / create / revoke grants.
 * DG-1.6: also lists the caller's desktop API keys.
 */
class GrantController extends Controller
{
    public function __construct(
        protected GrantManager $grants,
        protected DesktopKeyManager $keys,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Grant::class);

        $user = $request->user();
        $organizationId = (int) $user->organization_id;

        $layerFilter = $request->integer('layer_id') ?: null;
        $userFilter = $request->integer('user_id') ?: null;

        $grantsQuery = Grant::query()
            ->where('organization_id', $organizationId)
            ->with([
                'layer:id,name,public_id,alias,organization_id',
                'user:id,name,email',
                'group:id,name',
                'granter:id,name',
            ])
            ->orderByDesc('id');

        if ($layerFilter) {
            $grantsQuery->where('layer_id', $layerFilter);
        }
        if ($userFilter) {
            $grantsQuery->where('user_id', $userFilter);
        }

        $canManage = $request->user()->can('create', Grant::class);

        $grants = $grantsQuery->limit(200)->get()->map(fn (Grant $g) => [
            'id' => $g->id,
            'scope' => $g->scope,
            'expires_at' => $g->expires_at?->toIso8601String(),
            'layer' => $g->layer ? [
                'id' => $g->layer->id,
                'name' => $g->layer->name,
                'public_id' => $g->layer->public_id,
                'alias' => $g->layer->alias,
            ] : null,
            'user' => $g->user ? [
                'id' => $g->user->id,
                'name' => $g->user->name,
                'email' => $g->user->email,
            ] : null,
            'group' => $g->group ? [
                'id' => $g->group->id,
                'name' => $g->group->name,
            ] : null,
            'granted_by' => $g->granter?->name,
            'created_at' => $g->created_at?->toIso8601String(),
            'destroy_url' => route('desktop-connect.grants.destroy', $g),
        ]);

        $layers = Layer::query()
            ->where('organization_id', $organizationId)
            ->orderBy('name')
            ->get(['id', 'name', 'public_id', 'alias', 'metadata'])
            ->map(fn (Layer $layer) => [
                'id' => $layer->id,
                'name' => $layer->name,
                'public_id' => $layer->public_id,
                'alias' => $layer->alias,
                'writable' => ! $layer->isSqlView(),
            ]);

        $users = User::query()
            ->where('organization_id', $organizationId)
            ->orderBy('name')
            ->get(['id', 'name', 'email']);

        $groups = Group::query()
            ->where('organization_id', $organizationId)
            ->orderBy('name')
            ->get(['id', 'name']);

        $apiKeys = ApiKey::query()
            ->where('organization_id', $organizationId)
            ->where('user_id', $user->id)
            ->orderByDesc('id')
            ->limit(50)
            ->get()
            ->map(fn (ApiKey $key) => [
                'id' => $key->id,
                'label' => $key->label,
                'expires_at' => $key->expires_at?->toIso8601String(),
                'created_at' => $key->created_at?->toIso8601String(),
                'destroy_url' => route('desktop-connect.keys.destroy', $key),
            ]);

        return Inertia::render('DesktopConnect/Index', [
            'grants' => $grants,
            'layers' => $layers,
            'users' => $users,
            'groups' => $groups,
            'filters' => [
                'layer_id' => $layerFilter,
                'user_id' => $userFilter,
            ],
            'store_url' => route('desktop-connect.grants.store'),
            'index_url' => route('desktop-connect.grants.index'),
            'can_manage' => $canManage,
            'api_keys' => $apiKeys,
            'keys_store_url' => route('desktop-connect.keys.store'),
            'desktop_plain_text_key' => session('desktopPlainTextKey'),
            'key_abilities_preview' => $this->keys->abilitiesFor($user),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', Grant::class);

        $organizationId = (int) $request->user()->organization_id;

        $data = $request->validate([
            'layer_id' => [
                'required',
                'integer',
                Rule::exists('layers', 'id')->where('organization_id', $organizationId),
            ],
            'scope' => ['required', Rule::in(Grant::SCOPES)],
            'user_id' => [
                'nullable',
                'integer',
                Rule::exists('users', 'id')->where('organization_id', $organizationId),
                'required_without:group_id',
                'prohibits:group_id',
            ],
            'group_id' => [
                'nullable',
                'integer',
                Rule::exists('groups', 'id')->where('organization_id', $organizationId),
                'required_without:user_id',
                'prohibits:user_id',
            ],
            'expires_at' => ['nullable', 'date', 'after:now'],
        ]);

        $this->grants->grant($request->user(), $data);

        return redirect()
            ->route('desktop-connect.grants.index')
            ->with('success', __('desktop.grants.flash_granted'));
    }

    public function destroy(Request $request, Grant $grant): RedirectResponse
    {
        $this->authorize('delete', $grant);

        $this->grants->revoke($request->user(), $grant);

        return redirect()
            ->route('desktop-connect.grants.index')
            ->with('success', __('desktop.grants.flash_revoked'));
    }
}
