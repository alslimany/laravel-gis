<?php

namespace App\Http\Controllers;

use App\Http\Requests\SolutionPackRequest;
use App\Models\SolutionPackDefinition;
use App\SolutionPacks\PackCatalog;
use App\SolutionPacks\SolutionPack;
use App\SolutionPacks\StoredPack;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * UI builder for organization-owned solution packs.
 */
class SolutionPackController extends Controller
{
    public function __construct(private PackCatalog $catalog) {}

    public function index(Request $request): Response
    {
        return Inertia::render('SolutionPacks/Index', [
            'packs' => $this->catalog->list($request->user()->organization),
            'canManage' => $request->user()->hasRole('admin'),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('SolutionPacks/Edit', ['pack' => null]);
    }

    public function store(SolutionPackRequest $request)
    {
        $organization = $request->user()->organization;

        $model = SolutionPackDefinition::create([
            'organization_id' => $organization->id,
            'user_id' => $request->user()->id,
            'slug' => $request->validated('slug'),
            'name' => $request->validated('name'),
            'description' => $request->validated('description'),
            'definition' => $request->validated('definition'),
        ]);

        return redirect()
            ->route('solution-packs.edit', $model)
            ->with('success', "Pack {$model->name} created. Add layers and workspace items, then install it.");
    }

    public function edit(Request $request, SolutionPackDefinition $solutionPack): Response
    {
        $this->owned($request, $solutionPack);

        return Inertia::render('SolutionPacks/Edit', [
            'pack' => [
                'id' => $solutionPack->id,
                'slug' => $solutionPack->slug,
                'name' => $solutionPack->name,
                'description' => $solutionPack->description,
                'definition' => $solutionPack->definition ?? [],
            ],
        ]);
    }

    public function update(SolutionPackRequest $request, SolutionPackDefinition $solutionPack)
    {
        $this->owned($request, $solutionPack);

        $solutionPack->update([
            'slug' => $request->validated('slug'),
            'name' => $request->validated('name'),
            'description' => $request->validated('description'),
            'definition' => $request->validated('definition'),
        ]);

        return redirect()
            ->route('solution-packs.edit', $solutionPack)
            ->with('success', 'Pack updated.');
    }

    public function destroy(Request $request, SolutionPackDefinition $solutionPack)
    {
        $this->owned($request, $solutionPack);

        $name = $solutionPack->name;
        $solutionPack->delete();

        return redirect()
            ->route('solution-packs.index')
            ->with('success', "Pack {$name} deleted. Installed layers were not removed.");
    }

    /**
     * Clone a built-in or own pack into a new editable copy.
     */
    public function duplicate(Request $request, string $slug)
    {
        $organization = $request->user()->organization;

        $source = SolutionPack::find($slug) ?? StoredPack::findFor($organization, $slug);
        abort_unless($source, 404);

        $base = rtrim(substr(preg_replace('/[^a-z0-9-]+/', '-', strtolower($source->slug())).'-copy', 0, 54), '-');
        $candidate = $base;
        $attempt = 2;
        while (
            SolutionPack::find($candidate) !== null
            || StoredPack::findFor($organization, $candidate) !== null
        ) {
            $candidate = $base.'-'.$attempt;
            $attempt++;
        }

        $model = SolutionPackDefinition::create([
            'organization_id' => $organization->id,
            'user_id' => $request->user()->id,
            'slug' => $candidate,
            'name' => $source->name().' (copy)',
            'description' => $source->description(),
            'definition' => $source->definition(),
        ]);

        return redirect()
            ->route('solution-packs.edit', $model)
            ->with('success', 'Pack duplicated. Edit the copy, then install it.');
    }

    protected function owned(Request $request, SolutionPackDefinition $solutionPack): void
    {
        abort_unless($solutionPack->organization_id === $request->user()->organization_id, 404);
    }
}
