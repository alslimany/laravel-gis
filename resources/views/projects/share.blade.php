@extends('layouts.app')

@section('content')
<div class="container">
    <div class="row justify-content-center">
        <div class="col-md-8">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span>Share Project: {{ $project->name }}</span>
                    <a href="{{ route('projects.show', $project) }}" class="btn btn-sm btn-secondary">
                        <i class="fas fa-arrow-left"></i> Back
                    </a>
                </div>

                <div class="card-body">
                    @if(session('success'))
                        <div class="alert alert-success">
                            {{ session('success') }}
                        </div>
                    @endif

                    <div class="mb-4">
                        <h5>Visibility Settings</h5>
                        <form method="POST" action="{{ route('projects.update', $project) }}">
                            @csrf
                            @method('PUT')
                            <div class="form-check form-switch">
                                <input class="form-check-input" 
                                       type="checkbox" 
                                       id="is_public" 
                                       name="is_public" 
                                       value="1"
                                       {{ $project->is_public ? 'checked' : '' }}
                                       onchange="this.form.submit()">
                                <label class="form-check-label" for="is_public">
                                    Make this project public
                                </label>
                            </div>
                            <input type="hidden" name="name" value="{{ $project->name }}">
                        </form>
                        <small class="text-muted">
                            Public projects can be viewed by anyone with the share link
                        </small>
                    </div>

                    @if($project->is_public)
                        <div class="mb-4">
                            <h5>Share Link</h5>
                            <div class="input-group">
                                <input type="text" 
                                       class="form-control" 
                                       id="shareUrl" 
                                       value="{{ route('projects.shared', $project->share_token) }}" 
                                       readonly>
                                <button class="btn btn-outline-secondary" type="button" onclick="copyShareUrl()">
                                    <i class="fas fa-copy"></i> Copy
                                </button>
                            </div>
                            <small class="text-muted">
                                Share this link with others to allow them to view your project
                            </small>
                        </div>

                        <div class="mb-4">
                            <h5>Embed Code</h5>
                            <textarea class="form-control" 
                                      id="embedCode" 
                                      rows="3" 
                                      readonly><iframe src="{{ route('projects.shared', $project->share_token) }}" width="100%" height="600" frameborder="0"></iframe></textarea>
                            <button class="btn btn-sm btn-outline-secondary mt-2" onclick="copyEmbedCode()">
                                <i class="fas fa-copy"></i> Copy Embed Code
                            </button>
                        </div>

                        <div class="alert alert-warning">
                            <i class="fas fa-exclamation-triangle"></i>
                            <strong>Note:</strong> Anyone with the share link can view this project. 
                            To revoke access, make the project private again.
                        </div>
                    @else
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle"></i>
                            This project is currently private. Enable "Make this project public" to generate a share link.
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
function copyShareUrl() {
    const input = document.getElementById('shareUrl');
    input.select();
    document.execCommand('copy');
    alert('Share URL copied to clipboard!');
}

function copyEmbedCode() {
    const textarea = document.getElementById('embedCode');
    textarea.select();
    document.execCommand('copy');
    alert('Embed code copied to clipboard!');
}
</script>
@endpush
