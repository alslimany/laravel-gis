<?php

use App\Http\Controllers\AnalysisController;
use App\Http\Controllers\ApiTokenController;
use App\Http\Controllers\AttributeTableController;
use App\Http\Controllers\CatalogController;
use App\Http\Controllers\ContentAccessController;
use App\Http\Controllers\DashboardBoardController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DataImportController;
use App\Http\Controllers\ExportController;
use App\Http\Controllers\FeatureAttachmentController;
use App\Http\Controllers\FeatureController;
use App\Http\Controllers\FormController;
use App\Http\Controllers\GeocodeController;
use App\Http\Controllers\GisAssistantController;
use App\Http\Controllers\GroupController;
use App\Http\Controllers\HealthCheckController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\LayerController;
use App\Http\Controllers\LayerFieldController;
use App\Http\Controllers\MapController;
use App\Http\Controllers\MapIconController;
use App\Http\Controllers\MvtController;
use App\Http\Controllers\OrganizationController;
use App\Http\Controllers\PrintController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\WebhookController;
use Illuminate\Support\Facades\Route;

Route::get('/map-icons/{name}.svg', [MapIconController::class, 'show'])
    ->where('name', '[a-z0-9\-]+')
    ->name('map-icons.show');

Route::get('/health', [HealthCheckController::class, 'index'])->name('health.check');
Route::get('/health/detailed', [HealthCheckController::class, 'detailed'])->name('health.detailed');
Route::get('/health/metrics', [HealthCheckController::class, 'metrics'])->name('health.metrics');

Route::get('/', function () {
    return auth()->check()
        ? redirect('/dashboard')
        : redirect('/login');
});

Auth::routes();

Route::get('/home', [HomeController::class, 'index'])->name('home');

// Public shared / public form / public dashboard
Route::get('/maps/shared/{token}', [MapController::class, 'viewShared'])->name('maps.shared');
Route::get('/maps/shared/{token}/tiles/{layer}/{z}/{x}/{y}.mvt', [MvtController::class, 'publicTile'])
    ->whereNumber(['layer', 'z', 'x', 'y'])
    ->name('maps.shared.tiles');
Route::get('/projects/shared/{token}', [ProjectController::class, 'viewShared'])->name('projects.shared');
Route::get('/f/{token}', [FormController::class, 'publicShow'])->name('forms.public.show');
Route::post('/f/{token}', [FormController::class, 'publicSubmit'])->name('forms.public.submit');
Route::get('/dashboards/shared/{token}', [DashboardBoardController::class, 'publicView'])->name('dashboards.public');
Route::get('/dashboards/shared/{token}/data', [DashboardBoardController::class, 'publicData'])->name('dashboards.public.data');

Route::middleware('auth')->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    Route::post('/imports/imagery', [DataImportController::class, 'storeImagery'])->name('imports.imagery.store');
    Route::resource('imports', DataImportController::class)->except(['edit', 'update']);
    Route::get('/imports/{import}/status', [DataImportController::class, 'status'])->name('imports.status');

    Route::resource('layers', LayerController::class);
    Route::post('/layers/{layer}/publish', [LayerController::class, 'publish'])->name('layers.publish');
    Route::post('/layers/{layer}/unpublish', [LayerController::class, 'unpublish'])->name('layers.unpublish');
    Route::post('/layers/{layer}/style', [LayerController::class, 'updateStyle'])->name('layers.style.update');

    Route::get('/layers/{layer}/attributes', [AttributeTableController::class, 'index'])->name('layers.attributes');
    Route::put('/layers/{layer}/features/{featureId}', [FeatureController::class, 'updateFromTable'])->name('layers.features.update');
    Route::delete('/layers/{layer}/features/{featureId}', [FeatureController::class, 'destroyFromTable'])->name('layers.features.destroy');
    Route::post('/layers/{layer}/features/bulk-destroy', [FeatureController::class, 'destroyMany'])->name('layers.features.bulk-destroy');
    Route::delete('/layers/{layer}/shapes/{shape}', [FeatureController::class, 'destroyShape'])->name('layers.shapes.destroy');
    Route::get('/layers/{layer}/geojson', [AttributeTableController::class, 'geojson'])->name('layers.geojson');
    Route::get('/layers/{layer}/export/excel', [ExportController::class, 'exportExcel'])->name('layers.export.excel');

    Route::get('/export/layer/{layer}/geojson', [ExportController::class, 'exportGeoJSON'])->name('export.layer.geojson');
    Route::get('/export/layer/{layer}/csv', [ExportController::class, 'exportCSV'])->name('export.layer.csv');
    Route::get('/export/map/{map}/config', [ExportController::class, 'exportMapConfig'])->name('export.map.config');

    Route::get('/maps/builder/{id?}', [MapController::class, 'builder'])->name('maps.builder');
    Route::get('/maps/{map}/share', [MapController::class, 'share'])->name('maps.share');
    Route::get('/maps/{map}/print', [PrintController::class, 'show'])->name('maps.print');
    Route::match(['get', 'post'], '/maps/{map}/print/pdf', [PrintController::class, 'pdf'])->name('maps.print.pdf');
    Route::resource('maps', MapController::class);

    Route::get('/projects/{project}/share', [ProjectController::class, 'share'])->name('projects.share');
    Route::get('/projects/{project}/invite', [ProjectController::class, 'invite'])->name('projects.invite');
    Route::post('/projects/{project}/invite', [ProjectController::class, 'storeInvite'])->name('projects.invite.store');
    Route::delete('/projects/{project}/collaborators/{user}', [ProjectController::class, 'removeCollaborator'])->name('projects.collaborators.remove');
    Route::put('/projects/{project}/collaborators/{user}/role', [ProjectController::class, 'updateRole'])->name('projects.collaborators.role');
    Route::post('/projects/{project}/comments', [ProjectController::class, 'storeComment'])->name('projects.comments.store');
    Route::delete('/projects/{project}/comments/{comment}', [ProjectController::class, 'destroyComment'])->name('projects.comments.destroy');
    Route::resource('projects', ProjectController::class);

    Route::middleware('organization')->group(function () {
        Route::get('/organization/settings', [OrganizationController::class, 'settings'])->name('organization.settings');
        Route::put('/organization', [OrganizationController::class, 'update'])->name('organization.update');

        Route::get('forms/{form}/submissions/export.csv', [FormController::class, 'exportCsv'])->name('forms.export.csv');
        Route::get('forms/{form}/submissions/export.xlsx', [FormController::class, 'exportExcel'])->name('forms.export.excel');
        Route::get('forms/{form}/layer/export.csv', [FormController::class, 'exportLayerCsv'])->name('forms.export.layer.csv');
        Route::get('forms/{form}/layer/export.xlsx', [FormController::class, 'exportLayerExcel'])->name('forms.export.layer.excel');
        Route::resource('forms', FormController::class);
        Route::resource('groups', GroupController::class);
        Route::post('groups/{group}/users', [GroupController::class, 'attachUser'])->name('groups.users.attach');
        Route::put('groups/{group}/users/{user}/role', [GroupController::class, 'updateUserRole'])->name('groups.users.updateRole');
        Route::delete('groups/{group}/users/{user}', [GroupController::class, 'detachUser'])->name('groups.users.detach');

        Route::get('content-access/{type}/{id}', [ContentAccessController::class, 'show'])->name('content-access.show');
        Route::put('content-access', [ContentAccessController::class, 'update'])->name('content-access.update');

        Route::resource('webhooks', WebhookController::class)->except(['show']);

        Route::get('settings/api-tokens', [ApiTokenController::class, 'index'])->name('api-tokens.index');
        Route::post('settings/api-tokens', [ApiTokenController::class, 'store'])->name('api-tokens.store');
        Route::delete('settings/api-tokens/{tokenId}', [ApiTokenController::class, 'destroy'])->name('api-tokens.destroy');

        Route::get('/catalog', [CatalogController::class, 'index'])->name('catalog.index');
        Route::post('/dashboards/preview', [DashboardBoardController::class, 'preview'])->name('dashboards.preview');
        Route::resource('dashboards', DashboardBoardController::class);
        Route::get('/dashboards/{dashboard}/data', [DashboardBoardController::class, 'data'])->name('dashboards.data');

        Route::post('/ai/gis-assistant', [GisAssistantController::class, 'chat'])->name('ai.gis-assistant.chat');
    });

    Route::middleware('role:admin')->group(function () {
        Route::resource('users', UserController::class)->only(['index', 'edit', 'update', 'destroy']);
    });

    // Session JSON API used by the map workspace
    Route::prefix('api')->group(function () {
        Route::get('/geocode', [GeocodeController::class, 'search'])->name('api.geocode');

        Route::prefix('layers/{layer}')->group(function () {
            Route::get('/geojson', [AttributeTableController::class, 'geojson'])->name('api.layers.geojson');
            Route::get('/attributes', [AttributeTableController::class, 'index'])->name('api.layers.attributes');
            Route::get('/columns', [AttributeTableController::class, 'columns'])->name('api.layers.columns');
            Route::get('/columns/{column}/values', [AttributeTableController::class, 'distinct'])
                ->where('column', '[A-Za-z_][A-Za-z0-9_]*')
                ->name('api.layers.columns.values');
            Route::put('/attributes/{featureId}', [AttributeTableController::class, 'update'])->name('api.layers.attributes.update');

            Route::get('/features', [FeatureController::class, 'index'])->name('api.layers.features.index');
            Route::post('/features', [FeatureController::class, 'store'])->name('api.layers.features.store');
            Route::get('/features/{featureId}', [FeatureController::class, 'show'])->name('api.layers.features.show');
            Route::put('/features/{featureId}', [FeatureController::class, 'update'])->name('api.layers.features.update');
            Route::delete('/features/{featureId}', [FeatureController::class, 'destroy'])->name('api.layers.features.destroy');

            Route::get('/tiles/{z}/{x}/{y}.mvt', [MvtController::class, 'tile'])->name('api.layers.tiles');

            Route::get('/fields', [LayerFieldController::class, 'index'])->name('api.layers.fields.index');
            Route::post('/fields', [LayerFieldController::class, 'store'])->name('api.layers.fields.store');
            Route::put('/fields/{field}', [LayerFieldController::class, 'update'])->name('api.layers.fields.update');
            Route::delete('/fields/{field}', [LayerFieldController::class, 'destroy'])->name('api.layers.fields.destroy');

            Route::get('/features/{featureId}/attachments', [FeatureAttachmentController::class, 'index'])->name('api.layers.attachments.index');
            Route::post('/features/{featureId}/attachments', [FeatureAttachmentController::class, 'store'])->name('api.layers.attachments.store');
            Route::get('/features/{featureId}/attachments/{attachment}', [FeatureAttachmentController::class, 'show'])->name('api.layers.attachments.show');
            Route::delete('/features/{featureId}/attachments/{attachment}', [FeatureAttachmentController::class, 'destroy'])->name('api.layers.attachments.destroy');
        });

        Route::prefix('maps')->group(function () {
            Route::post('/', [MapController::class, 'store'])->name('api.maps.store');
            Route::put('/{map}', [MapController::class, 'update'])->name('api.maps.update');
        });

        Route::prefix('analysis')->group(function () {
            Route::post('/buffer', [AnalysisController::class, 'buffer'])->name('api.analysis.buffer');
            Route::post('/spatial-query', [AnalysisController::class, 'spatialQuery'])->name('api.analysis.spatial-query');
            Route::post('/attribute-query', [AnalysisController::class, 'attributeQuery'])->name('api.analysis.attribute-query');
            Route::post('/measure-distance', [AnalysisController::class, 'measureDistance'])->name('api.analysis.measure-distance');
            Route::post('/measure-area', [AnalysisController::class, 'measureArea'])->name('api.analysis.measure-area');
            Route::post('/layer-buffer', [AnalysisController::class, 'layerBuffer'])->name('api.analysis.layer-buffer');
            Route::post('/union', [AnalysisController::class, 'union'])->name('api.analysis.union');
            Route::post('/intersect', [AnalysisController::class, 'intersect'])->name('api.analysis.intersect');
            Route::post('/erase', [AnalysisController::class, 'erase'])->name('api.analysis.erase');
        });

        Route::prefix('export')->group(function () {
            Route::get('/layer/{layer}/geojson', [ExportController::class, 'exportGeoJSON'])->name('api.export.geojson');
            Route::get('/layer/{layer}/csv', [ExportController::class, 'exportCSV'])->name('api.export.csv');
            Route::get('/layer/{layer}/excel', [ExportController::class, 'exportExcel'])->name('api.export.excel');
            Route::get('/map/{map}/config', [ExportController::class, 'exportMapConfig'])->name('api.export.map-config');
            Route::get('/map/{map}/prepare', [ExportController::class, 'prepareMapExport'])->name('api.export.map-prepare');
            Route::post('/query-results', [ExportController::class, 'exportQueryResults'])->name('api.export.query-results');
        });
    });
});
