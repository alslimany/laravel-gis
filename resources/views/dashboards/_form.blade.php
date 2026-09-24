@php
    $widgetsJson = old('widgets', json_encode($dashboard->widgets ?? [
        ['type' => 'kpi', 'title' => 'Feature count', 'layer_id' => $layers->first()?->id, 'aggregation' => 'count'],
    ], JSON_PRETTY_PRINT));
@endphp

<div class="mb-3">
    <label class="form-label" for="name">Name</label>
    <input type="text" name="name" id="name" class="form-control @error('name') is-invalid @enderror"
           value="{{ old('name', $dashboard->name ?? '') }}" required>
    @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
</div>

<div class="mb-3">
    <label class="form-label" for="description">Description</label>
    <textarea name="description" id="description" rows="3" class="form-control">{{ old('description', $dashboard->description ?? '') }}</textarea>
</div>

<div class="mb-3 form-check">
    <input type="hidden" name="is_public" value="0">
    <input type="checkbox" class="form-check-input" name="is_public" id="is_public" value="1"
           @checked(old('is_public', $dashboard->is_public ?? false))>
    <label class="form-check-label" for="is_public">Public (shareable by token)</label>
</div>

<div class="mb-3">
    <label class="form-label" for="widgets">Widgets (JSON)</label>
    <textarea name="widgets" id="widgets" rows="14" class="form-control font-monospace @error('widgets') is-invalid @enderror">{{ $widgetsJson }}</textarea>
    <div class="form-text">
        Supported types: <code>kpi</code>, <code>bar</code>, <code>pie</code>, <code>table</code>.
        Fields: <code>type</code>, <code>title</code>, <code>layer_id</code>, <code>column</code>, <code>group_by</code>, <code>aggregation</code> (count|sum|avg), <code>limit</code>.
    </div>
    @error('widgets')<div class="invalid-feedback">{{ $message }}</div>@enderror
</div>

@if($layers->isNotEmpty())
    <div class="mb-3">
        <label class="form-label">Available layers</label>
        <ul class="small mb-0">
            @foreach($layers as $layer)
                <li><code>{{ $layer->id }}</code> — {{ $layer->name }}</li>
            @endforeach
        </ul>
    </div>
@endif
