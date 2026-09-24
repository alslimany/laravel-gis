<?php

namespace App\Http\Controllers;

use App\Models\FeatureAttachment;
use App\Models\Layer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class FeatureAttachmentController extends Controller
{
    public function __construct()
    {
        $this->middleware(['auth', 'organization']);
    }

    /**
     * List attachments for a layer feature.
     */
    public function index(Layer $layer, int|string $featureId)
    {
        $this->authorize('view', $layer);

        $attachments = FeatureAttachment::where('layer_id', $layer->id)
            ->where('feature_id', (int) $featureId)
            ->with('user:id,name')
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'success' => true,
            'attachments' => $attachments,
        ]);
    }

    /**
     * Upload an attachment for a layer feature.
     */
    public function store(Request $request, Layer $layer, int|string $featureId)
    {
        $this->authorize('update', $layer);

        $validated = $request->validate([
            'file' => 'required|file|max:20480',
        ]);

        $file = $validated['file'];
        $directory = "attachments/{$layer->id}/{$featureId}";
        $storedName = Str::uuid().'_'.$file->getClientOriginalName();
        $path = $file->storeAs($directory, $storedName, 'local');

        $attachment = FeatureAttachment::create([
            'layer_id' => $layer->id,
            'feature_id' => (int) $featureId,
            'user_id' => Auth::id(),
            'file_name' => $file->getClientOriginalName(),
            'file_path' => $path,
            'mime_type' => $file->getMimeType(),
            'file_size' => $file->getSize(),
        ]);

        return response()->json([
            'success' => true,
            'attachment' => $attachment,
        ], 201);
    }

    /**
     * Download an attachment file.
     */
    public function show(Layer $layer, int|string $featureId, FeatureAttachment $attachment)
    {
        $this->authorize('view', $layer);
        $this->assertAttachmentBelongs($layer, $featureId, $attachment);

        if (! $attachment->file_path || ! Storage::disk('local')->exists($attachment->file_path)) {
            abort(404);
        }

        return Storage::disk('local')->download(
            $attachment->file_path,
            $attachment->file_name,
            ['Content-Type' => $attachment->mime_type ?: 'application/octet-stream']
        );
    }

    /**
     * Delete an attachment.
     */
    public function destroy(Layer $layer, int|string $featureId, FeatureAttachment $attachment)
    {
        $this->authorize('update', $layer);
        $this->assertAttachmentBelongs($layer, $featureId, $attachment);

        if ($layer->organization_id !== Auth::user()->organization_id) {
            abort(403);
        }

        if ($attachment->file_path && Storage::disk('local')->exists($attachment->file_path)) {
            Storage::disk('local')->delete($attachment->file_path);
        }

        $attachment->delete();

        return response()->json(['success' => true]);
    }

    protected function assertAttachmentBelongs(
        Layer $layer,
        int|string $featureId,
        FeatureAttachment $attachment
    ): void {
        if ($attachment->layer_id !== $layer->id || (int) $attachment->feature_id !== (int) $featureId) {
            abort(404);
        }
    }
}
