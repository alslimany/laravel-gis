@extends('layouts.app')

@section('content')
<div class="container">
    <div class="row justify-content-center">
        <div class="col-lg-8">
            <div class="card">
                <div class="card-header">Edit Dashboard</div>
                <div class="card-body">
                    <form method="POST" action="{{ route('dashboards.update', $dashboard) }}">
                        @csrf
                        @method('PUT')
                        @include('dashboards._form', ['dashboard' => $dashboard, 'layers' => $layers])
                        <div class="d-flex gap-2">
                            <button type="submit" class="btn btn-primary">Save</button>
                            <a href="{{ route('dashboards.show', $dashboard) }}" class="btn btn-secondary">Cancel</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
