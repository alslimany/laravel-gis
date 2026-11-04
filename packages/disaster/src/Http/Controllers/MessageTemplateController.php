<?php

namespace Lumina\Disaster\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Lumina\Disaster\Http\Controllers\Concerns\RespondsToDisasterConsole;
use Lumina\Disaster\Models\MessageTemplate;
use Lumina\Disaster\Support\Enums\MessageTemplateStatus;

class MessageTemplateController extends Controller
{
    use RespondsToDisasterConsole;

    private const LANGUAGES = ['ar', 'en'];

    private const CHANNELS = ['cap', 'sms', 'radio'];

    public function index(Request $request): JsonResponse
    {
        $organizationId = (int) $request->user()->organization_id;
        $templates = MessageTemplate::query()
            ->forOrganization($organizationId)
            ->orderByDesc('id')
            ->get()
            ->map(fn (MessageTemplate $t) => $this->present($t))
            ->all();

        return response()->json([
            'data' => $templates,
            'languages' => self::LANGUAGES,
            'channels' => self::CHANNELS,
        ]);
    }

    public function store(Request $request): JsonResponse|RedirectResponse
    {
        Gate::authorize('disaster.manage');

        $organizationId = (int) $request->user()->organization_id;
        $data = $this->validated($request);

        $template = MessageTemplate::query()->create([
            ...$data,
            'organization_id' => $organizationId,
            'status' => MessageTemplateStatus::Draft->value,
        ]);

        if ($this->wantsJsonResponse($request)) {
            return response()->json(['data' => $this->present($template)], 201);
        }

        return redirect()
            ->route('disaster.message-templates.index')
            ->with('success', 'Message template created.');
    }

    public function update(Request $request, MessageTemplate $messageTemplate): JsonResponse|RedirectResponse
    {
        Gate::authorize('disaster.manage');
        $this->assertOrg($request, $messageTemplate);

        abort_if(
            $messageTemplate->status === MessageTemplateStatus::Approved->value,
            422,
            'Approved templates cannot be edited; create a new revision.',
        );

        $messageTemplate->fill($this->validated($request, updating: true))->save();

        if ($this->wantsJsonResponse($request)) {
            return response()->json(['data' => $this->present($messageTemplate)]);
        }

        return redirect()
            ->route('disaster.message-templates.index')
            ->with('success', 'Message template updated.');
    }

    public function validateTemplate(Request $request, MessageTemplate $messageTemplate): JsonResponse|RedirectResponse
    {
        Gate::authorize('disaster.manage');
        $this->assertOrg($request, $messageTemplate);

        abort_unless(
            in_array($messageTemplate->status, [
                MessageTemplateStatus::Draft->value,
                MessageTemplateStatus::Validated->value,
            ], true),
            422,
            'Only draft templates can be validated.',
        );

        abort_if(
            trim($messageTemplate->body) === '' || mb_strlen($messageTemplate->body) < 10,
            422,
            'Template body must be at least 10 characters.',
        );

        $messageTemplate->forceFill([
            'status' => MessageTemplateStatus::Validated->value,
            'approved_by' => null,
            'approved_at' => null,
        ])->save();

        if ($this->wantsJsonResponse($request)) {
            return response()->json(['data' => $this->present($messageTemplate)]);
        }

        return redirect()
            ->route('disaster.message-templates.index')
            ->with('success', 'Message template validated.');
    }

    public function approve(Request $request, MessageTemplate $messageTemplate): JsonResponse|RedirectResponse
    {
        Gate::authorize('disaster.manage');
        $this->assertOrg($request, $messageTemplate);

        abort_unless(
            $messageTemplate->status === MessageTemplateStatus::Validated->value,
            422,
            'Template must be validated before approval.',
        );

        $messageTemplate->forceFill([
            'status' => MessageTemplateStatus::Approved->value,
            'approved_by' => $request->user()->id,
            'approved_at' => now(),
        ])->save();

        if ($this->wantsJsonResponse($request)) {
            return response()->json(['data' => $this->present($messageTemplate)]);
        }

        return redirect()
            ->route('disaster.message-templates.index')
            ->with('success', 'Message template approved.');
    }

    /**
     * @return array<string, mixed>
     */
    protected function validated(Request $request, bool $updating = false): array
    {
        return $request->validate([
            'hazard_type' => [$updating ? 'sometimes' : 'required', 'string', 'max:64'],
            'language' => [$updating ? 'sometimes' : 'required', 'string', Rule::in(self::LANGUAGES)],
            'channel' => [$updating ? 'sometimes' : 'required', 'string', Rule::in(self::CHANNELS)],
            'headline' => ['nullable', 'string', 'max:255'],
            'body' => [$updating ? 'sometimes' : 'required', 'string', 'max:5000'],
        ]);
    }

    protected function assertOrg(Request $request, MessageTemplate $template): void
    {
        abort_unless(
            (int) $template->organization_id === (int) $request->user()->organization_id,
            404,
        );
    }

    /**
     * @return array<string, mixed>
     */
    protected function present(MessageTemplate $template): array
    {
        return [
            'id' => $template->id,
            'hazard_type' => $template->hazard_type,
            'language' => $template->language,
            'channel' => $template->channel,
            'headline' => $template->headline,
            'body' => $template->body,
            'status' => $template->status,
            'approved_by' => $template->approved_by,
            'approved_at' => $template->approved_at?->toIso8601String(),
            'created_at' => $template->created_at?->toIso8601String(),
            'updated_at' => $template->updated_at?->toIso8601String(),
            'validate_url' => route('disaster.message-templates.validate', $template),
            'approve_url' => route('disaster.message-templates.approve', $template),
            'update_url' => route('disaster.message-templates.update', $template),
        ];
    }
}
