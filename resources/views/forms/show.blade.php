@extends('layouts.app')

@section('content')
<div class="container">
    <div class="row justify-content-center">
        <div class="col-md-10">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span>Form: {{ $form->name }}</span>
                    <div>
                        <a href="{{ route('forms.edit', $form) }}" class="btn btn-primary btn-sm">
                            <i class="fas fa-edit"></i> Edit
                        </a>
                        <a href="{{ route('forms.index') }}" class="btn btn-secondary btn-sm">
                            <i class="fas fa-arrow-left"></i> Back
                        </a>
                    </div>
                </div>

                <div class="card-body">
                    @if(session('success'))
                        <div class="alert alert-success">{{ session('success') }}</div>
                    @endif

                    <div class="row">
                        <div class="col-md-6">
                            <h5>Details</h5>
                            <table class="table table-borderless">
                                <tr>
                                    <th width="40%">Name</th>
                                    <td>{{ $form->name }}</td>
                                </tr>
                                <tr>
                                    <th>Description</th>
                                    <td>{{ $form->description ?? '-' }}</td>
                                </tr>
                                <tr>
                                    <th>Layer</th>
                                    <td>{{ $form->layer->name ?? '-' }}</td>
                                </tr>
                                <tr>
                                    <th>Created By</th>
                                    <td>{{ $form->user->name ?? '-' }}</td>
                                </tr>
                                <tr>
                                    <th>Public</th>
                                    <td>
                                        @if($form->is_public)
                                            <span class="badge bg-success">Yes</span>
                                        @else
                                            <span class="badge bg-secondary">No</span>
                                        @endif
                                    </td>
                                </tr>
                            </table>
                        </div>
                        <div class="col-md-6">
                            <h5>Share Link</h5>
                            @if($form->is_public && $form->share_token)
                                <div class="input-group mb-3">
                                    <input type="text" class="form-control" readonly
                                           value="{{ route('forms.public.show', $form->share_token) }}" id="shareUrl">
                                    <button class="btn btn-outline-secondary" type="button" onclick="navigator.clipboard.writeText(document.getElementById('shareUrl').value)">
                                        Copy
                                    </button>
                                </div>
                                <a href="{{ route('forms.public.show', $form->share_token) }}" class="btn btn-sm btn-outline-primary" target="_blank">
                                    Open public form
                                </a>
                            @else
                                <p class="text-muted">Enable public access to generate a share link.</p>
                            @endif
                        </div>
                    </div>

                    <hr>
                    <h5>Schema Fields</h5>
                    @if(empty($form->schema))
                        <p class="text-muted">No custom fields defined. Submitters will only provide location.</p>
                    @else
                        <table class="table table-sm">
                            <thead>
                                <tr>
                                    <th>Name</th>
                                    <th>Label</th>
                                    <th>Type</th>
                                    <th>Required</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($form->schema as $field)
                                    <tr>
                                        <td><code>{{ $field['name'] ?? '' }}</code></td>
                                        <td>{{ $field['label'] ?? '' }}</td>
                                        <td>{{ $field['type'] ?? 'text' }}</td>
                                        <td>{{ !empty($field['required']) ? 'Yes' : 'No' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
