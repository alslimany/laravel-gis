<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $form->name }} — {{ config('app.name', 'Laravel') }}</title>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=ibm-plex-sans:400,500,600,700" rel="stylesheet" />
    @vite(['resources/sass/app.scss', 'resources/js/app.js'])
    <style>
        body { font-family: "IBM Plex Sans", ui-sans-serif, system-ui, sans-serif; background: #eef2f6; color: #0b1220; }
        .card { border: 1px solid #dce3ec; border-radius: 0.85rem; box-shadow: none; }
        .card-header { background: #fff; border-bottom: 1px solid #dce3ec; }
        .btn-primary { background: #0f766e; border-color: #0f766e; }
        .form-control, .form-select { border-color: #dce3ec; border-radius: 0.5rem; }
        .form-control:focus, .form-select:focus { border-color: #0f766e; box-shadow: 0 0 0 3px color-mix(in srgb, #0f766e 22%, transparent); }
    </style>
</head>
<body class="bg-light">
<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-md-8">
            <div class="card shadow-sm">
                <div class="card-header">
                    <h4 class="mb-0">{{ $form->name }}</h4>
                    @if($form->description)
                        <p class="text-muted mb-0 mt-1">{{ $form->description }}</p>
                    @endif
                </div>
                <div class="card-body">
                    @if(session('success'))
                        <div class="alert alert-success">{{ session('success') }}</div>
                    @endif
                    @if(session('error'))
                        <div class="alert alert-danger">{{ session('error') }}</div>
                    @endif

                    <form method="POST" action="{{ route('forms.public.submit', $form->share_token) }}" enctype="multipart/form-data">
                        @csrf

                        @foreach($form->schema ?? [] as $field)
                            @if(!empty($field['name']))
                            @php
                                $name = $field['name'];
                                $label = $field['label'] ?? $name;
                                $type = $field['type'] ?? 'text';
                                $required = !empty($field['required']);
                            @endphp
                            <div class="mb-3">
                                <label class="form-label" for="attr_{{ $name }}">
                                    {{ $label }}
                                    @if($required)<span class="text-danger">*</span>@endif
                                </label>

                                @if($type === 'textarea')
                                    <textarea class="form-control @error('attributes.'.$name) is-invalid @enderror"
                                              id="attr_{{ $name }}" name="attributes[{{ $name }}]" rows="3"
                                              @required($required)>{{ old('attributes.'.$name) }}</textarea>
                                @elseif($type === 'select')
                                    <select class="form-select @error('attributes.'.$name) is-invalid @enderror"
                                            id="attr_{{ $name }}" name="attributes[{{ $name }}]" @required($required)>
                                        <option value="">-- Select --</option>
                                        @foreach($field['options'] ?? [] as $option)
                                            <option value="{{ $option }}" @selected(old('attributes.'.$name) == $option)>{{ $option }}</option>
                                        @endforeach
                                    </select>
                                @elseif($type === 'checkbox')
                                    <div class="form-check">
                                        <input type="hidden" name="attributes[{{ $name }}]" value="0">
                                        <input class="form-check-input" type="checkbox" id="attr_{{ $name }}"
                                               name="attributes[{{ $name }}]" value="1" @checked(old('attributes.'.$name))>
                                        <label class="form-check-label" for="attr_{{ $name }}">{{ $label }}</label>
                                    </div>
                                @else
                                    <input type="{{ $type === 'number' ? 'number' : ($type === 'date' ? 'date' : 'text') }}"
                                           class="form-control @error('attributes.'.$name) is-invalid @enderror"
                                           id="attr_{{ $name }}" name="attributes[{{ $name }}]"
                                           value="{{ old('attributes.'.$name) }}" @required($required)
                                           @if($type === 'number') step="any" @endif>
                                @endif
                                @error('attributes.'.$name)<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            @endif
                        @endforeach

                        <hr>
                        <h5 class="mb-3">Location</h5>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="latitude" class="form-label">Latitude</label>
                                <input type="number" step="any" class="form-control @error('latitude') is-invalid @enderror"
                                       id="latitude" name="latitude" value="{{ old('latitude') }}" placeholder="e.g. 24.7136">
                                @error('latitude')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="longitude" class="form-label">Longitude</label>
                                <input type="number" step="any" class="form-control @error('longitude') is-invalid @enderror"
                                       id="longitude" name="longitude" value="{{ old('longitude') }}" placeholder="e.g. 46.6753">
                                @error('longitude')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                        </div>
                        <div class="mb-3">
                            <label for="wkt" class="form-label">Or WKT geometry</label>
                            <input type="text" class="form-control @error('wkt') is-invalid @enderror"
                                   id="wkt" name="wkt" value="{{ old('wkt') }}" placeholder="POINT(46.67 24.71)">
                            @error('wkt')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            <small class="text-muted">Provide latitude/longitude or a WKT string.</small>
                        </div>

                        <div class="mb-3">
                            <label for="attachment" class="form-label">Attachment (optional)</label>
                            <input type="file" class="form-control @error('attachment') is-invalid @enderror"
                                   id="attachment" name="attachment">
                            @error('attachment')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>

                        <button type="submit" class="btn btn-primary w-100">Submit</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
</body>
</html>
