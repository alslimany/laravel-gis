@extends('layouts.app')

@section('content')
<div class="container">
    <div class="row justify-content-center">
        <div class="col-lg-8">
            <div class="card">
                <div class="card-header">Create Dashboard</div>
                <div class="card-body">
                    <form method="POST" action="{{ route('dashboards.store') }}">
                        @csrf
                        @include('dashboards._form', ['dashboard' => null, 'layers' => $layers])
                        <div class="d-flex gap-2">
                            <button type="submit" class="btn btn-primary">Create</button>
                            <a href="{{ route('dashboards.index') }}" class="btn btn-secondary">Cancel</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
