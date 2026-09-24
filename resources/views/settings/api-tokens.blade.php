@extends('layouts.app')

@section('content')
<div class="container">
    <div class="row justify-content-center">
        <div class="col-md-10">
            <div class="card mb-4">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span>API Tokens</span>
                    <a href="{{ route('dashboard') }}" class="btn btn-sm btn-secondary">Back</a>
                </div>

                <div class="card-body">
                    @if(session('success'))
                        <div class="alert alert-success">{{ session('success') }}</div>
                    @endif

                    @if(!empty($plainTextToken))
                        <div class="alert alert-warning">
                            <strong>Your new token</strong> (copy now — it will not be shown again):
                            <code class="d-block mt-2 user-select-all">{{ $plainTextToken }}</code>
                        </div>
                    @endif

                    @if ($errors->any())
                        <div class="alert alert-danger">
                            <ul class="mb-0">
                                @foreach ($errors->all() as $error)
                                    <li>{{ $error }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    <p class="text-muted">
                        Personal access tokens authenticate API requests with
                        <code>Authorization: Bearer &lt;token&gt;</code>.
                    </p>

                    <form method="POST" action="{{ route('api-tokens.store') }}" class="row g-2 align-items-end mb-4">
                        @csrf
                        <div class="col-md-8">
                            <label for="name" class="form-label">Token name</label>
                            <input type="text" class="form-control @error('name') is-invalid @enderror"
                                   id="name" name="name" value="{{ old('name') }}"
                                   placeholder="e.g. CLI, mobile app" required>
                            @error('name')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                        <div class="col-md-4">
                            <button type="submit" class="btn btn-primary w-100">Create token</button>
                        </div>
                    </form>

                    <div class="table-responsive">
                        <table class="table table-striped mb-0">
                            <thead>
                                <tr>
                                    <th>Name</th>
                                    <th>Abilities</th>
                                    <th>Last used</th>
                                    <th>Created</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($tokens as $token)
                                    <tr>
                                        <td>{{ $token->name }}</td>
                                        <td>
                                            @foreach($token->abilities ?? [] as $ability)
                                                <span class="badge bg-secondary">{{ $ability }}</span>
                                            @endforeach
                                        </td>
                                        <td>{{ $token->last_used_at?->diffForHumans() ?? 'Never' }}</td>
                                        <td>{{ $token->created_at?->toDayDateTimeString() }}</td>
                                        <td class="text-end">
                                            <form method="POST" action="{{ route('api-tokens.destroy', $token->id) }}"
                                                  class="d-inline" onsubmit="return confirm('Revoke this token?');">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" class="btn btn-sm btn-danger">Revoke</button>
                                            </form>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="5" class="text-center text-muted">No API tokens yet.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
