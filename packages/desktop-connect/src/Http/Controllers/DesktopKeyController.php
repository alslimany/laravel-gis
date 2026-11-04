<?php

namespace Lumina\DesktopConnect\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Lumina\DesktopConnect\Models\ApiKey;
use Lumina\DesktopConnect\Services\DesktopKeyManager;

/**
 * Web mint/revoke for desktop API keys (DG-1.6). Plain text shown once via session flash.
 */
class DesktopKeyController extends Controller
{
    public function __construct(
        protected DesktopKeyManager $keys,
    ) {}

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', ApiKey::class);

        $data = $request->validate([
            'label' => ['required', 'string', 'max:255'],
            'expires_at' => ['nullable', 'date', 'after:now'],
        ]);

        $minted = $this->keys->mint(
            $request->user(),
            $data['label'],
            isset($data['expires_at']) ? new \DateTimeImmutable($data['expires_at']) : null,
        );

        return redirect()
            ->route('desktop-connect.grants.index')
            ->with('success', __('desktop.keys.flash_created'))
            ->with('desktopPlainTextKey', $minted['plain_text']);
    }

    public function destroy(Request $request, ApiKey $key): RedirectResponse
    {
        $this->authorize('delete', $key);

        $this->keys->revoke($request->user(), $key);

        return redirect()
            ->route('desktop-connect.grants.index')
            ->with('success', __('desktop.keys.flash_revoked'));
    }
}
