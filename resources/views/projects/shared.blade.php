<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $project->name }} - Shared Project</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        body {
            margin: 0;
            padding: 0;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
        }
        .project-header {
            background: #fff;
            border-bottom: 1px solid #dee2e6;
            padding: 1rem;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .project-content {
            padding: 2rem;
        }
    </style>
</head>
<body>
    <div class="project-header">
        <div class="container-fluid">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h4 class="mb-0">{{ $project->name }}</h4>
                    <small class="text-muted">Shared by {{ $project->user->name ?? 'Unknown' }}</small>
                </div>
                <span class="badge bg-success">Public View</span>
            </div>
        </div>
    </div>

    <div class="project-content">
        <div class="container-fluid">
            <div class="row">
                <div class="col-md-12">
                    <div class="card mb-3">
                        <div class="card-body">
                            <h5>Description</h5>
                            <p>{{ $project->description ?: 'No description provided.' }}</p>
                            
                            <div class="row mt-3">
                                <div class="col-md-6">
                                    <p><strong>Created:</strong> {{ $project->created_at->format('F d, Y') }}</p>
                                    <p><strong>Organization:</strong> {{ $project->organization->name ?? 'N/A' }}</p>
                                </div>
                                <div class="col-md-6">
                                    <p><strong>Layers:</strong> {{ $project->layers->count() }}</p>
                                    <p><strong>Last Updated:</strong> {{ $project->updated_at->format('F d, Y') }}</p>
                                </div>
                            </div>
                        </div>
                    </div>

                    @if($project->layers->count() > 0)
                        <div class="card">
                            <div class="card-header">
                                <h6 class="mb-0">Layers</h6>
                            </div>
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table class="table table-sm">
                                        <thead>
                                            <tr>
                                                <th>Name</th>
                                                <th>Type</th>
                                                <th>Features</th>
                                                <th>Status</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            @foreach($project->layers as $layer)
                                                <tr>
                                                    <td>{{ $layer->name }}</td>
                                                    <td>
                                                        <span class="badge bg-info">{{ $layer->geometry_type }}</span>
                                                    </td>
                                                    <td>{{ $layer->feature_count ?? 0 }}</td>
                                                    <td>
                                                        @if($layer->is_published)
                                                            <span class="badge bg-success">Published</span>
                                                        @else
                                                            <span class="badge bg-secondary">Draft</span>
                                                        @endif
                                                    </td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
