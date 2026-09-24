@extends('layouts.app')

@section('content')
<div class="container">
    <div class="row justify-content-center">
        <div class="col-md-12">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span>Forms</span>
                    <a href="{{ route('forms.create') }}" class="btn btn-primary btn-sm">
                        <i class="fas fa-plus"></i> New Form
                    </a>
                </div>

                <div class="card-body">
                    @if(session('success'))
                        <div class="alert alert-success">{{ session('success') }}</div>
                    @endif

                    @if($forms->isEmpty())
                        <div class="text-center py-5">
                            <p class="text-muted">No forms yet.</p>
                            <a href="{{ route('forms.create') }}" class="btn btn-primary">Create Your First Form</a>
                        </div>
                    @else
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>Name</th>
                                        <th>Layer</th>
                                        <th>Public</th>
                                        <th>Created By</th>
                                        <th>Created</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($forms as $form)
                                        <tr>
                                            <td>
                                                <a href="{{ route('forms.show', $form) }}">{{ $form->name }}</a>
                                            </td>
                                            <td>{{ $form->layer->name ?? '-' }}</td>
                                            <td>
                                                @if($form->is_public)
                                                    <span class="badge bg-success">Public</span>
                                                @else
                                                    <span class="badge bg-secondary">Private</span>
                                                @endif
                                            </td>
                                            <td>{{ $form->user->name ?? '-' }}</td>
                                            <td>{{ $form->created_at->format('Y-m-d') }}</td>
                                            <td>
                                                <a href="{{ route('forms.edit', $form) }}" class="btn btn-sm btn-outline-primary">Edit</a>
                                                <form action="{{ route('forms.destroy', $form) }}" method="POST" class="d-inline" onsubmit="return confirm('Delete this form?')">
                                                    @csrf
                                                    @method('DELETE')
                                                    <button type="submit" class="btn btn-sm btn-outline-danger">Delete</button>
                                                </form>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                        {{ $forms->links() }}
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
