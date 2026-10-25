<?php

namespace Lumina\EarlyWarning\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Lumina\EarlyWarning\Models\CitizenReport;
use Lumina\EarlyWarning\Models\PublicToken;
use Lumina\EarlyWarning\Support\AdvisoryCopy;

class CitizenReportsController extends Controller
{
    public function index(Request $request): Response
    {
        $organizationId = (int) $request->user()->organization_id;

        $reports = CitizenReport::query()
            ->where('organization_id', $organizationId)
            ->orderByDesc('reported_at')
            ->limit(100)
            ->get()
            ->map(fn (CitizenReport $r) => [
                'id' => $r->id,
                'latitude' => $r->latitude,
                'longitude' => $r->longitude,
                'note' => $r->note,
                'basin_code' => $r->basin_code,
                'status' => $r->status,
                'source' => $r->source,
                'photo_url' => $r->photoUrl(),
                'reported_at' => $r->reported_at?->toIso8601String(),
            ])
            ->all();

        $tokens = PublicToken::query()
            ->where('organization_id', $organizationId)
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (PublicToken $t) => [
                'id' => $t->id,
                'label' => $t->label,
                'abilities' => $t->abilities ?? [],
                'revoked' => $t->revoked_at !== null,
                'last_used_at' => $t->last_used_at?->toIso8601String(),
                'created_at' => $t->created_at?->toIso8601String(),
            ])
            ->all();

        return Inertia::render('EarlyWarning/CitizenReports', [
            ...AdvisoryCopy::forUi(AdvisoryCopy::citizenReports()),
            'reports' => $reports,
            'tokens' => $tokens,
            'plain_token' => $request->session()->get('ew_plain_token'),
            'public_base' => url('/ew/public'),
            'issue_token_url' => route('early-warning.public-tokens.store'),
        ]);
    }

    public function update(Request $request, CitizenReport $citizenReport): RedirectResponse
    {
        Gate::authorize('early-warning.manage');

        abort_unless(
            (int) $citizenReport->organization_id === (int) $request->user()->organization_id,
            404,
        );

        $data = $request->validate([
            'status' => ['required', Rule::in(['pending', 'approved', 'rejected'])],
        ]);

        $citizenReport->forceFill(['status' => $data['status']])->save();

        return redirect()
            ->route('early-warning.citizen-reports.index')
            ->with('success', 'Report updated.');
    }

    public function issueToken(Request $request): RedirectResponse
    {
        Gate::authorize('early-warning.manage');

        $organizationId = (int) $request->user()->organization_id;
        $data = $request->validate([
            'label' => ['nullable', 'string', 'max:120'],
        ]);

        $issued = PublicToken::issue($organizationId, $data['label'] ?? 'Public feed');

        return redirect()
            ->route('early-warning.citizen-reports.index')
            ->with('ew_plain_token', $issued['plain'])
            ->with('success', 'Public token created — copy it now; it will not be shown again.');
    }

    public function revokeToken(Request $request, PublicToken $publicToken): RedirectResponse
    {
        Gate::authorize('early-warning.manage');

        abort_unless(
            (int) $publicToken->organization_id === (int) $request->user()->organization_id,
            404,
        );

        $publicToken->forceFill(['revoked_at' => now()])->save();

        return redirect()
            ->route('early-warning.citizen-reports.index')
            ->with('success', 'Public token revoked.');
    }
}
