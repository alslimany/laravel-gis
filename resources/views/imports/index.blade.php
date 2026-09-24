@extends('layouts.app')

@section('content')
<div class="desk">
    <header class="desk-intro">
        <h1>Data imports</h1>
        <a href="{{ route('imports.create') }}" class="desk-primary">New import</a>
    </header>

    @if(session('success'))
        <p class="desk-flash">{{ session('success') }}</p>
    @endif

    @if(session('error'))
        <p class="desk-flash desk-flash-error">{{ session('error') }}</p>
    @endif

    <section class="desk-panel">
        @if($imports->isEmpty())
            <p class="desk-empty">
                No imports yet.
                <a href="{{ route('imports.create') }}">Import a dataset</a>
                to start a layer.
            </p>
        @else
            <div class="desk-table-wrap">
                <table class="desk-table">
                    <thead>
                        <tr>
                            <th>File</th>
                            <th class="desk-hide-sm">Type</th>
                            <th class="num desk-hide-sm">Size</th>
                            <th class="desk-status">Status</th>
                            <th class="num desk-hide-sm">Progress</th>
                            <th class="num desk-hide-sm">Features</th>
                            <th class="desk-hide-sm">Created</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($imports as $import)
                            @php
                                $bytes = (int) $import->file_size;
                                $size = $bytes >= 1048576
                                    ? number_format($bytes / 1048576, 1).' MB'
                                    : number_format($bytes / 1024, 0).' KB';
                            @endphp
                            <tr>
                                <td>
                                    <a href="{{ route('imports.show', $import) }}" class="desk-name">{{ $import->file_name }}</a>
                                </td>
                                <td class="mono desk-hide-sm">{{ strtoupper($import->file_type) }}</td>
                                <td class="num mono desk-hide-sm">{{ $size }}</td>
                                <td class="desk-status">
                                    @if($import->status === 'completed')
                                        <span class="pill pill-live">Completed</span>
                                    @elseif($import->status === 'processing')
                                        <span class="pill pill-run">Processing</span>
                                    @elseif($import->status === 'failed')
                                        <span class="pill pill-fail">Failed</span>
                                    @else
                                        <span class="pill">Pending</span>
                                    @endif
                                </td>
                                <td class="num mono desk-hide-sm">{{ (int) $import->progress }}%</td>
                                <td class="num mono desk-hide-sm">{{ $import->feature_count ? number_format($import->feature_count) : '—' }}</td>
                                <td class="desk-sub desk-sub-inline desk-hide-sm">{{ $import->created_at->diffForHumans() }}</td>
                                <td>
                                    <div class="desk-row-actions">
                                        <a href="{{ route('imports.show', $import) }}">View</a>
                                        @if($import->status === 'failed' || $import->status === 'completed')
                                            <form action="{{ route('imports.destroy', $import) }}" method="POST" onsubmit="return confirm('Delete this import?');">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit">Delete</button>
                                            </form>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @if($imports->hasPages())
                <div class="desk-pages">{{ $imports->links() }}</div>
            @endif
        @endif
    </section>
</div>
@endsection
