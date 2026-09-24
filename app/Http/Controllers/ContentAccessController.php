<?php

namespace App\Http\Controllers;

use App\Models\ContentAccess;
use App\Models\DashboardBoard;
use App\Models\Group;
use App\Models\Layer;
use App\Models\Map;
use App\Services\ContentAccessService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ContentAccessController extends Controller
{
    public function __construct(protected ContentAccessService $access)
    {
        $this->middleware(['auth', 'organization']);
    }

    /**
     * Show current ACL for content.
     */
    public function show(Request $request, string $type, int $id)
    {
        $organizationId = $this->assertCanManage($request, $type, $id);

        $access = ContentAccess::query()
            ->where('content_type', $type)
            ->where('content_id', $id)
            ->first();

        $groups = Group::query()
            ->where('organization_id', $organizationId)
            ->orderBy('name')
            ->get(['id', 'name']);

        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'access' => $access ?? [
                    'content_type' => $type,
                    'content_id' => $id,
                    'visibility' => 'organization',
                    'group_id' => null,
                    'organization_id' => $organizationId,
                ],
                'groups' => $groups,
            ]);
        }

        return back();
    }

    /**
     * Set visibility for layer / map / dashboard content.
     */
    public function update(Request $request)
    {
        $validated = $request->validate([
            'content_type' => 'required|in:layer,map,dashboard',
            'content_id' => 'required|integer',
            'visibility' => 'required|in:private,group,organization,public',
            'group_id' => [
                'nullable',
                'integer',
                'required_if:visibility,group',
                Rule::exists('groups', 'id')->where(
                    'organization_id',
                    $request->user()->organization_id
                ),
            ],
        ]);

        $this->assertCanManage(
            $request,
            $validated['content_type'],
            (int) $validated['content_id']
        );

        $access = $this->access->setVisibility(
            $validated['content_type'],
            (int) $validated['content_id'],
            $validated['visibility'],
            (int) $request->user()->organization_id,
            isset($validated['group_id']) ? (int) $validated['group_id'] : null
        );

        if ($request->wantsJson()) {
            return response()->json(['success' => true, 'access' => $access]);
        }

        return back()->with('success', 'Content visibility updated.');
    }

    /**
     * Ensure the content exists in the user's organization and they may manage ACL.
     */
    protected function assertCanManage(Request $request, string $type, int $id): int
    {
        $user = $request->user();
        $orgId = $user->organization_id;

        $content = match ($type) {
            'layer' => Layer::query()->whereKey($id)->firstOrFail(),
            'map' => Map::query()->whereKey($id)->firstOrFail(),
            'dashboard' => DashboardBoard::query()->whereKey($id)->firstOrFail(),
            default => abort(404),
        };

        if ((int) $content->organization_id !== (int) $orgId) {
            abort(403, 'Content does not belong to your organization.');
        }

        $isOwner = isset($content->user_id) && (int) $content->user_id === (int) $user->id;
        if (! $user->hasRole('admin') && ! $isOwner) {
            abort(403, 'Only owners or organization admins can change content access.');
        }

        return (int) $orgId;
    }
}
