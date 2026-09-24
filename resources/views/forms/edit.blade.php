@extends('layouts.app')

@section('content')
<div class="container">
    <div class="row justify-content-center">
        <div class="col-md-8">
            <div class="card">
                <div class="card-header">Edit Form</div>
                <div class="card-body">
                    <form method="POST" action="{{ route('forms.update', $form) }}" id="formBuilder">
                        @csrf
                        @method('PUT')

                        <div class="mb-3">
                            <label for="name" class="form-label">Form Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control @error('name') is-invalid @enderror"
                                   id="name" name="name" value="{{ old('name', $form->name) }}" required>
                            @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>

                        <div class="mb-3">
                            <label for="description" class="form-label">Description</label>
                            <textarea class="form-control @error('description') is-invalid @enderror"
                                      id="description" name="description" rows="3">{{ old('description', $form->description) }}</textarea>
                            @error('description')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>

                        <div class="mb-3">
                            <label for="layer_id" class="form-label">Target Layer <span class="text-danger">*</span></label>
                            <select class="form-select @error('layer_id') is-invalid @enderror" id="layer_id" name="layer_id" required>
                                <option value="">-- Select Layer --</option>
                                @foreach($layers as $layer)
                                    <option value="{{ $layer->id }}" @selected(old('layer_id', $form->layer_id) == $layer->id)>
                                        {{ $layer->name }}
                                    </option>
                                @endforeach
                            </select>
                            @error('layer_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>

                        <div class="mb-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="is_public" name="is_public" value="1"
                                       @checked(old('is_public', $form->is_public))>
                                <label class="form-check-label" for="is_public">Make this form public (shareable link)</label>
                            </div>
                        </div>

                        <hr>
                        <h5 class="mb-3">Form Fields</h5>
                        <div id="schemaFields">
                            @php
                                $oldSchema = old('schema', $form->schema ?: [['name' => '', 'label' => '', 'type' => 'text', 'required' => false]]);
                            @endphp
                            @foreach($oldSchema as $i => $field)
                                <div class="border rounded p-3 mb-2 schema-row">
                                    <div class="row g-2">
                                        <div class="col-md-3">
                                            <label class="form-label">Field name</label>
                                            <input type="text" class="form-control" name="schema[{{ $i }}][name]" value="{{ $field['name'] ?? '' }}">
                                        </div>
                                        <div class="col-md-3">
                                            <label class="form-label">Label</label>
                                            <input type="text" class="form-control" name="schema[{{ $i }}][label]" value="{{ $field['label'] ?? '' }}">
                                        </div>
                                        <div class="col-md-3">
                                            <label class="form-label">Type</label>
                                            <select class="form-select" name="schema[{{ $i }}][type]">
                                                @foreach(['text','textarea','number','select','checkbox','date'] as $type)
                                                    <option value="{{ $type }}" @selected(($field['type'] ?? 'text') === $type)>{{ $type }}</option>
                                                @endforeach
                                            </select>
                                        </div>
                                        <div class="col-md-2 d-flex align-items-end">
                                            <div class="form-check mb-2">
                                                <input class="form-check-input" type="checkbox" name="schema[{{ $i }}][required]" value="1" @checked(!empty($field['required']))>
                                                <label class="form-check-label">Required</label>
                                            </div>
                                        </div>
                                        <div class="col-md-1 d-flex align-items-end">
                                            <button type="button" class="btn btn-outline-danger btn-sm remove-field mb-2">&times;</button>
                                        </div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                        <button type="button" class="btn btn-outline-secondary btn-sm mb-3" id="addField">
                            <i class="fas fa-plus"></i> Add Field
                        </button>

                        <div class="d-flex justify-content-between">
                            <a href="{{ route('forms.show', $form) }}" class="btn btn-secondary">Cancel</a>
                            <button type="submit" class="btn btn-primary">Save Changes</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    const container = document.getElementById('schemaFields');
    let index = container.querySelectorAll('.schema-row').length;

    document.getElementById('addField').addEventListener('click', function () {
        const row = document.createElement('div');
        row.className = 'border rounded p-3 mb-2 schema-row';
        row.innerHTML = `
            <div class="row g-2">
                <div class="col-md-3">
                    <label class="form-label">Field name</label>
                    <input type="text" class="form-control" name="schema[${index}][name]" placeholder="column_name">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Label</label>
                    <input type="text" class="form-control" name="schema[${index}][label]">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Type</label>
                    <select class="form-select" name="schema[${index}][type]">
                        <option value="text">text</option>
                        <option value="textarea">textarea</option>
                        <option value="number">number</option>
                        <option value="select">select</option>
                        <option value="checkbox">checkbox</option>
                        <option value="date">date</option>
                    </select>
                </div>
                <div class="col-md-2 d-flex align-items-end">
                    <div class="form-check mb-2">
                        <input class="form-check-input" type="checkbox" name="schema[${index}][required]" value="1">
                        <label class="form-check-label">Required</label>
                    </div>
                </div>
                <div class="col-md-1 d-flex align-items-end">
                    <button type="button" class="btn btn-outline-danger btn-sm remove-field mb-2">&times;</button>
                </div>
            </div>`;
        container.appendChild(row);
        index++;
    });

    container.addEventListener('click', function (e) {
        if (e.target.classList.contains('remove-field')) {
            const rows = container.querySelectorAll('.schema-row');
            if (rows.length > 1) {
                e.target.closest('.schema-row').remove();
            }
        }
    });
});
</script>
@endpush
