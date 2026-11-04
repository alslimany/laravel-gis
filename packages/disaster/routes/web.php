<?php

use Illuminate\Support\Facades\Route;
use Lumina\Disaster\Http\Controllers\ActivationController;
use Lumina\Disaster\Http\Controllers\Api\IngestAvlPingController;
use Lumina\Disaster\Http\Controllers\Api\IngestClosureController;
use Lumina\Disaster\Http\Controllers\Api\IngestIncidentController;
use Lumina\Disaster\Http\Controllers\AvlTelemetryController;
use Lumina\Disaster\Http\Controllers\BeneficiaryController;
use Lumina\Disaster\Http\Controllers\CapDisseminationController;
use Lumina\Disaster\Http\Controllers\ClosureController;
use Lumina\Disaster\Http\Controllers\CopController;
use Lumina\Disaster\Http\Controllers\CriticalSiteController;
use Lumina\Disaster\Http\Controllers\DamagePointController;
use Lumina\Disaster\Http\Controllers\DisasterController;
use Lumina\Disaster\Http\Controllers\EscalationRuleController;
use Lumina\Disaster\Http\Controllers\EvacCorridorController;
use Lumina\Disaster\Http\Controllers\EvacRoutingController;
use Lumina\Disaster\Http\Controllers\EvacuationOrderController;
use Lumina\Disaster\Http\Controllers\ExposureController;
use Lumina\Disaster\Http\Controllers\HazardSettingsController;
use Lumina\Disaster\Http\Controllers\IncidentController;
use Lumina\Disaster\Http\Controllers\IncidentIcsController;
use Lumina\Disaster\Http\Controllers\IncidentImageryController;
use Lumina\Disaster\Http\Controllers\IncidentWorkspaceController;
use Lumina\Disaster\Http\Controllers\InfrastructureConditionController;
use Lumina\Disaster\Http\Controllers\InventoryController;
use Lumina\Disaster\Http\Controllers\MedicalFacilityController;
use Lumina\Disaster\Http\Controllers\MessageTemplateController;
use Lumina\Disaster\Http\Controllers\MissingPersonController;
use Lumina\Disaster\Http\Controllers\PublicTokenController;
use Lumina\Disaster\Http\Controllers\RegistryController;
use Lumina\Disaster\Http\Controllers\ResourceRequestController;
use Lumina\Disaster\Http\Controllers\ShelterCheckinController;
use Lumina\Disaster\Http\Controllers\StagingAreaController;
use Lumina\Disaster\Http\Controllers\VolunteerController;
use Lumina\Disaster\Http\Controllers\WarningAckController;

/*
| Plugin routes: web, auth, organization, plugin:disaster,
| prefix x/disaster, name disaster.*
*/

Route::get('/', [DisasterController::class, 'index'])->name('index');
Route::get('/assets', [DisasterController::class, 'assets'])->name('assets.index');
Route::get('/planning', [DisasterController::class, 'planning'])->name('planning.index');
Route::get('/damage', [DisasterController::class, 'damage'])->name('damage.index');

Route::get('/cop', [CopController::class, 'index'])->name('cop.index');
Route::get('/cop/export', [CopController::class, 'export'])->name('cop.export');
Route::post('/cop/components', [CopController::class, 'storeComponent'])->name('cop.components.store');
Route::post('/cop/status', [CopController::class, 'storeStatus'])->name('cop.status.store');

Route::get('/hazards', [HazardSettingsController::class, 'show'])->name('hazards.index');
Route::put('/hazards', [HazardSettingsController::class, 'update'])->name('hazards.update');

Route::get('/medical-facilities', [MedicalFacilityController::class, 'index'])->name('medical.index');
Route::post('/medical-facilities', [MedicalFacilityController::class, 'store'])->name('medical.store');
Route::put('/medical-facilities/{medicalFacility}', [MedicalFacilityController::class, 'update'])->name('medical.update');
Route::delete('/medical-facilities/{medicalFacility}', [MedicalFacilityController::class, 'destroy'])->name('medical.destroy');

Route::get('/staging-areas', [StagingAreaController::class, 'index'])->name('staging.index');
Route::post('/staging-areas', [StagingAreaController::class, 'store'])->name('staging.store');
Route::put('/staging-areas/{stagingArea}', [StagingAreaController::class, 'update'])->name('staging.update');
Route::delete('/staging-areas/{stagingArea}', [StagingAreaController::class, 'destroy'])->name('staging.destroy');

Route::get('/critical-sites', [CriticalSiteController::class, 'index'])->name('critical-sites.index');
Route::post('/critical-sites', [CriticalSiteController::class, 'store'])->name('critical-sites.store');
Route::put('/critical-sites/{criticalSite}', [CriticalSiteController::class, 'update'])->name('critical-sites.update');
Route::delete('/critical-sites/{criticalSite}', [CriticalSiteController::class, 'destroy'])->name('critical-sites.destroy');

Route::get('/infrastructure-conditions', [InfrastructureConditionController::class, 'index'])->name('infrastructure-conditions.index');
Route::get('/infrastructure-conditions/overdue', [InfrastructureConditionController::class, 'overdue'])->name('infrastructure-conditions.overdue');
Route::post('/infrastructure-conditions', [InfrastructureConditionController::class, 'store'])->name('infrastructure-conditions.store');
Route::put('/infrastructure-conditions/{infrastructureCondition}', [InfrastructureConditionController::class, 'update'])->name('infrastructure-conditions.update');
Route::delete('/infrastructure-conditions/{infrastructureCondition}', [InfrastructureConditionController::class, 'destroy'])->name('infrastructure-conditions.destroy');

Route::get('/evac-corridors', [EvacCorridorController::class, 'index'])->name('evac.index');
Route::post('/evac-corridors', [EvacCorridorController::class, 'store'])->name('evac.store');
Route::put('/evac-corridors/{evacCorridor}', [EvacCorridorController::class, 'update'])->name('evac.update');
Route::delete('/evac-corridors/{evacCorridor}', [EvacCorridorController::class, 'destroy'])->name('evac.destroy');

Route::get('/evac-routing/assignments', [EvacRoutingController::class, 'assignments'])->name('evac-routing.assignments');
Route::get('/evac-routing/runs', [EvacRoutingController::class, 'runs'])->name('evac-routing.runs');
Route::post('/evac-routing/compute', [EvacRoutingController::class, 'compute'])->name('evac-routing.compute');
Route::post('/evac-routing/assignments/{evacAssignment}/override', [EvacRoutingController::class, 'override'])
    ->name('evac-routing.override');

Route::get('/exposure', [ExposureController::class, 'page'])->name('exposure.page');
Route::get('/exposure/summaries', [ExposureController::class, 'index'])->name('exposure.index');
Route::post('/exposure/compute', [ExposureController::class, 'compute'])->name('exposure.compute');

Route::get('/activations', [ActivationController::class, 'index'])->name('activations.index');
Route::get('/activations/{activation}', [ActivationController::class, 'show'])->name('activations.show');
Route::post('/activations/{activation}/acknowledge', [WarningAckController::class, 'store'])->name('activations.acknowledge');

Route::get('/escalation-rules', [EscalationRuleController::class, 'index'])->name('escalation-rules.index');
Route::post('/escalation-rules', [EscalationRuleController::class, 'store'])->name('escalation-rules.store');
Route::put('/escalation-rules/{escalationRule}', [EscalationRuleController::class, 'update'])->name('escalation-rules.update');
Route::delete('/escalation-rules/{escalationRule}', [EscalationRuleController::class, 'destroy'])->name('escalation-rules.destroy');

Route::get('/evacuation-orders', [EvacuationOrderController::class, 'index'])->name('evacuation-orders.index');
Route::post('/evacuation-orders', [EvacuationOrderController::class, 'store'])->name('evacuation-orders.store');
// The order form lives on the index page; /create is a friendly alias (never the {evacuationOrder} binding).
Route::get('/evacuation-orders/create', [EvacuationOrderController::class, 'create'])->name('evacuation-orders.create');
Route::get('/evacuation-orders/{evacuationOrder}', [EvacuationOrderController::class, 'show'])->whereNumber('evacuationOrder')->name('evacuation-orders.show');
Route::put('/evacuation-orders/{evacuationOrder}', [EvacuationOrderController::class, 'update'])->whereNumber('evacuationOrder')->name('evacuation-orders.update');
Route::delete('/evacuation-orders/{evacuationOrder}', [EvacuationOrderController::class, 'destroy'])->whereNumber('evacuationOrder')->name('evacuation-orders.destroy');
Route::post('/evacuation-orders/{evacuationOrder}/transition', [EvacuationOrderController::class, 'transition'])->whereNumber('evacuationOrder')->name('evacuation-orders.transition');
Route::post('/evacuation-orders/{evacuationOrder}/execution', [EvacuationOrderController::class, 'updateExecution'])->whereNumber('evacuationOrder')->name('evacuation-orders.execution');

Route::get('/incidents', [IncidentController::class, 'index'])->name('incidents.index');
Route::post('/incidents', [IncidentController::class, 'store'])->name('incidents.store');
Route::get('/incidents/{incident}', [IncidentController::class, 'show'])->name('incidents.show');
Route::put('/incidents/{incident}', [IncidentController::class, 'update'])->name('incidents.update');
Route::delete('/incidents/{incident}', [IncidentController::class, 'destroy'])->name('incidents.destroy');
Route::post('/incidents/{incident}/close', [IncidentIcsController::class, 'close'])->name('incidents.close');

Route::post('/incidents/{incident}/operational-periods', [IncidentIcsController::class, 'storeOperationalPeriod'])
    ->name('incidents.operational-periods.store');

Route::post('/incidents/{incident}/plans', [IncidentIcsController::class, 'storePlan'])->name('incidents.plans.store');
Route::put('/incidents/{incident}/plans/{plan}', [IncidentIcsController::class, 'updatePlan'])->name('incidents.plans.update');
Route::post('/incidents/{incident}/plans/{plan}/submit', [IncidentIcsController::class, 'submitPlan'])->name('incidents.plans.submit');
Route::post('/incidents/{incident}/plans/{plan}/approve', [IncidentIcsController::class, 'approvePlan'])->name('incidents.plans.approve');

Route::post('/incidents/{incident}/roles', [IncidentIcsController::class, 'storeRole'])->name('incidents.roles.store');
Route::delete('/incidents/{incident}/roles/{role}', [IncidentIcsController::class, 'destroyRole'])->name('incidents.roles.destroy');

Route::post('/incidents/{incident}/tasks', [IncidentIcsController::class, 'storeTask'])->name('incidents.tasks.store');
Route::put('/incidents/{incident}/tasks/{task}', [IncidentIcsController::class, 'updateTask'])->name('incidents.tasks.update');
Route::delete('/incidents/{incident}/tasks/{task}', [IncidentIcsController::class, 'destroyTask'])->name('incidents.tasks.destroy');

Route::get('/incidents/{incident}/sitreps/prefill', [IncidentIcsController::class, 'sitRepPrefill'])->name('incidents.sitreps.prefill');
Route::post('/incidents/{incident}/sitreps', [IncidentIcsController::class, 'storeSitRep'])->name('incidents.sitreps.store');
Route::get('/incidents/{incident}/sitreps/{sitrep}/export.json', [IncidentIcsController::class, 'exportSitRepJson'])
    ->name('incidents.sitreps.export.json');
Route::get('/incidents/{incident}/sitreps/{sitrep}/export.pdf', [IncidentIcsController::class, 'exportSitRepPdf'])
    ->name('incidents.sitreps.export.pdf');

Route::post('/incidents/{incident}/workspace/invite', [IncidentWorkspaceController::class, 'invite'])
    ->name('incidents.workspace.invite');
Route::post('/incidents/{incident}/workspace/members/{member}/revoke', [IncidentWorkspaceController::class, 'revoke'])
    ->name('incidents.workspace.members.revoke');

Route::get('/resource-requests', [ResourceRequestController::class, 'index'])->name('resource-requests.index');
Route::post('/resource-requests', [ResourceRequestController::class, 'store'])->name('resource-requests.store');
Route::post('/resource-requests/{resourceRequest}/transition', [ResourceRequestController::class, 'transition'])
    ->name('resource-requests.transition');
Route::post('/resource-requests/{resourceRequest}/match', [ResourceRequestController::class, 'match'])
    ->name('resource-requests.match');
Route::get('/resource-requests/{resourceRequest}/suggestions', [ResourceRequestController::class, 'suggestions'])
    ->name('resource-requests.suggestions');

Route::get('/inventory', [InventoryController::class, 'index'])->name('inventory.index');
Route::post('/inventory/types', [InventoryController::class, 'storeType'])->name('inventory.types.store');
Route::post('/inventory/warehouses', [InventoryController::class, 'storeWarehouse'])->name('inventory.warehouses.store');
Route::post('/inventory/adjust', [InventoryController::class, 'adjust'])->name('inventory.adjust');

Route::get('/volunteers', [VolunteerController::class, 'index'])->name('volunteers.index');
Route::post('/volunteers', [VolunteerController::class, 'store'])->name('volunteers.store');
Route::put('/volunteers/{volunteer}', [VolunteerController::class, 'update'])->name('volunteers.update');
Route::delete('/volunteers/{volunteer}', [VolunteerController::class, 'destroy'])->name('volunteers.destroy');

Route::get('/registry', [RegistryController::class, 'index'])->name('registry.index');
Route::post('/registry/checkins', [ShelterCheckinController::class, 'store'])->name('registry.checkins.store');
Route::get('/registry/beneficiaries', [BeneficiaryController::class, 'index'])->name('registry.beneficiaries.index');
Route::post('/registry/beneficiaries', [BeneficiaryController::class, 'store'])->name('registry.beneficiaries.store');
Route::get('/registry/beneficiaries/export', [BeneficiaryController::class, 'export'])->name('registry.beneficiaries.export');
Route::get('/registry/beneficiaries/{beneficiary}', [BeneficiaryController::class, 'show'])->name('registry.beneficiaries.show');
Route::get('/registry/missing', [MissingPersonController::class, 'index'])->name('registry.missing.index');
Route::post('/registry/missing', [MissingPersonController::class, 'store'])->name('registry.missing.store');
Route::post('/registry/missing/match', [MissingPersonController::class, 'match'])->name('registry.missing.match');
Route::get('/registry/missing/{missingPerson}', [MissingPersonController::class, 'show'])->name('registry.missing.show');
Route::post('/registry/reports', [MissingPersonController::class, 'storeReport'])->name('registry.reports.store');

Route::get('/damage-points', [DamagePointController::class, 'index'])->name('damage-points.index');
Route::get('/damage-points/aggregates', [DamagePointController::class, 'aggregates'])->name('damage-points.aggregates');
Route::post('/damage-points', [DamagePointController::class, 'store'])->name('damage-points.store');
Route::put('/damage-points/{damagePoint}', [DamagePointController::class, 'update'])->name('damage-points.update');
Route::post('/damage-points/{damagePoint}/review', [DamagePointController::class, 'review'])->name('damage-points.review');
Route::delete('/damage-points/{damagePoint}', [DamagePointController::class, 'destroy'])->name('damage-points.destroy');

Route::get('/incidents/{incident}/imagery', [IncidentImageryController::class, 'index'])->name('incidents.imagery.index');
Route::post('/incidents/{incident}/imagery', [IncidentImageryController::class, 'store'])->name('incidents.imagery.store');
Route::delete('/incidents/{incident}/imagery/{imagery}', [IncidentImageryController::class, 'destroy'])->name('incidents.imagery.destroy');

Route::get('/avl', [AvlTelemetryController::class, 'page'])->name('avl.page');
Route::get('/avl/units', [AvlTelemetryController::class, 'index'])->name('avl.index');

Route::get('/public-tokens', [PublicTokenController::class, 'index'])->name('public-tokens.index');
Route::post('/public-tokens', [PublicTokenController::class, 'store'])->name('public-tokens.store');
Route::delete('/public-tokens/{publicToken}', [PublicTokenController::class, 'destroy'])->name('public-tokens.destroy');

Route::get('/closures', [ClosureController::class, 'index'])->name('closures.index');
Route::post('/closures', [ClosureController::class, 'store'])->name('closures.store');
Route::delete('/closures/{closure}', [ClosureController::class, 'destroy'])->name('closures.destroy');

Route::post('/dissemination/cap', [CapDisseminationController::class, 'store'])->name('dissemination.cap.store');
Route::post('/dissemination/cap/publish', [CapDisseminationController::class, 'publish'])->name('dissemination.cap.publish');
Route::post('/dissemination/cap/{disseminationLog}/update', [CapDisseminationController::class, 'update'])->name('dissemination.cap.update');
Route::post('/dissemination/cap/{disseminationLog}/cancel', [CapDisseminationController::class, 'cancel'])->name('dissemination.cap.cancel');
Route::get('/dissemination/cap/{disseminationLog}/report', [CapDisseminationController::class, 'report'])->name('dissemination.cap.report');

Route::get('/message-templates', [MessageTemplateController::class, 'index'])->name('message-templates.index');
Route::post('/message-templates', [MessageTemplateController::class, 'store'])->name('message-templates.store');
Route::put('/message-templates/{messageTemplate}', [MessageTemplateController::class, 'update'])->name('message-templates.update');
Route::post('/message-templates/{messageTemplate}/validate', [MessageTemplateController::class, 'validateTemplate'])->name('message-templates.validate');
Route::post('/message-templates/{messageTemplate}/approve', [MessageTemplateController::class, 'approve'])->name('message-templates.approve');

Route::prefix('api/v1')->name('api.')->group(function () {
    Route::post('incidents', IngestIncidentController::class)->name('incidents.store');
    Route::post('avl/pings', IngestAvlPingController::class)->name('avl.pings');
    Route::post('closures', IngestClosureController::class)->name('closures.store');
});
