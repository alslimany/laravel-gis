<?php

namespace Lumina\Disaster\Tests;

use Lumina\Disaster\Support\Enums\CriticalSiteType;
use Lumina\Disaster\Support\Enums\DamageLevel;
use Lumina\Disaster\Support\Enums\DamageReviewStatus;
use Lumina\Disaster\Support\Enums\EvacuationOrderStatus;
use Lumina\Disaster\Support\Enums\Habitability;
use Lumina\Disaster\Support\Enums\IncidentPlanStatus;
use Lumina\Disaster\Support\Enums\IncidentRoleType;
use Lumina\Disaster\Support\Enums\InfrastructureConditionGrade;
use Lumina\Disaster\Support\Enums\InventoryLedgerReason;
use Lumina\Disaster\Support\Enums\Lifeline;
use Lumina\Disaster\Support\Enums\LifelineComponentStatus;
use Lumina\Disaster\Support\Enums\ResourceRequestStatus;
use Lumina\Disaster\Support\Enums\ResourceStatus;
use Lumina\Disaster\Support\Enums\TaskPriority;
use Lumina\Disaster\Support\Enums\TaskStatus;
use PHPUnit\Framework\TestCase;

class EnumsTest extends TestCase
{
    public function test_resource_status_values(): void
    {
        $this->assertSame('01_Available', ResourceStatus::Available->value);
        $this->assertSame('06_Out_of_Service', ResourceStatus::OutOfService->value);
        $this->assertCount(6, ResourceStatus::cases());
    }

    public function test_damage_assessment_enum_values_and_review_transitions(): void
    {
        $this->assertSame(
            ['none', 'minor', 'moderate', 'severe', 'destroyed'],
            array_map(fn (DamageLevel $l) => $l->value, DamageLevel::cases()),
        );
        $this->assertSame(
            ['habitable', 'limited', 'uninhabitable'],
            array_map(fn (Habitability $h) => $h->value, Habitability::cases()),
        );
        $this->assertTrue(DamageReviewStatus::Submitted->canTransitionTo(DamageReviewStatus::Reviewed));
        $this->assertTrue(DamageReviewStatus::Reviewed->canTransitionTo(DamageReviewStatus::Verified));
        $this->assertTrue(DamageReviewStatus::Reviewed->canTransitionTo(DamageReviewStatus::Rejected));
        $this->assertFalse(DamageReviewStatus::Submitted->canTransitionTo(DamageReviewStatus::Verified));
        $this->assertTrue(DamageReviewStatus::Verified->isOfficial());
        $this->assertFalse(DamageReviewStatus::Submitted->isOfficial());
    }

    public function test_critical_site_type_and_condition_grade_values(): void
    {
        $this->assertSame('dam', CriticalSiteType::Dam->value);
        $this->assertSame('hospital_field', CriticalSiteType::HospitalField->value);
        $this->assertContains(CriticalSiteType::Shelter, CriticalSiteType::cases());
        $this->assertCount(13, CriticalSiteType::cases());

        $this->assertSame(
            ['good', 'fair', 'poor', 'critical'],
            array_map(fn (InfrastructureConditionGrade $g) => $g->value, InfrastructureConditionGrade::cases()),
        );
    }

    public function test_evacuation_order_status_transitions(): void
    {
        $this->assertTrue(EvacuationOrderStatus::Draft->canTransitionTo(EvacuationOrderStatus::PendingApproval));
        $this->assertTrue(EvacuationOrderStatus::Approved->canTransitionTo(EvacuationOrderStatus::Issued));
        $this->assertFalse(EvacuationOrderStatus::Draft->canTransitionTo(EvacuationOrderStatus::Issued));
        $this->assertFalse(EvacuationOrderStatus::Completed->canTransitionTo(EvacuationOrderStatus::Cancelled));
        $this->assertCount(7, EvacuationOrderStatus::cases());
    }

    public function test_ics_lite_enum_values(): void
    {
        $this->assertSame('incident_commander', IncidentRoleType::IncidentCommander->value);
        $this->assertCount(8, IncidentRoleType::cases());
        $this->assertTrue(IncidentPlanStatus::Draft->canTransitionTo(IncidentPlanStatus::PendingApproval));
        $this->assertTrue(IncidentPlanStatus::PendingApproval->canTransitionTo(IncidentPlanStatus::Approved));
        $this->assertFalse(IncidentPlanStatus::Approved->canTransitionTo(IncidentPlanStatus::Draft));
        $this->assertSame('open', TaskStatus::Open->value);
        $this->assertSame('urgent', TaskPriority::Urgent->value);
    }

    public function test_fema_lifeline_and_status_enums(): void
    {
        $this->assertCount(8, Lifeline::cases());
        $this->assertSame('safety_and_security', Lifeline::SafetyAndSecurity->value);
        $this->assertSame('food_hydration_shelter', Lifeline::FoodHydrationShelter->value);
        $this->assertSame('health_and_medical', Lifeline::HealthAndMedical->value);
        $this->assertSame('energy', Lifeline::Energy->value);
        $this->assertSame('communications', Lifeline::Communications->value);
        $this->assertSame('transportation', Lifeline::Transportation->value);
        $this->assertSame('hazardous_materials', Lifeline::HazardousMaterials->value);
        $this->assertSame('water_systems', Lifeline::WaterSystems->value);
        $this->assertSame('Safety and Security', Lifeline::SafetyAndSecurity->label());
        $this->assertSame('Food, Hydration, Shelter', Lifeline::FoodHydrationShelter->label());

        $this->assertSame(
            ['stable', 'at_risk', 'impacted', 'unknown'],
            array_map(fn (LifelineComponentStatus $s) => $s->value, LifelineComponentStatus::cases()),
        );
        $this->assertSame(
            LifelineComponentStatus::Impacted,
            LifelineComponentStatus::rollUp([
                LifelineComponentStatus::Stable,
                LifelineComponentStatus::AtRisk,
                LifelineComponentStatus::Impacted,
            ]),
        );
        $this->assertSame(LifelineComponentStatus::Unknown, LifelineComponentStatus::rollUp([]));
        $this->assertSame('amber', LifelineComponentStatus::AtRisk->color());
    }

    public function test_resource_request_status_transitions(): void
    {
        $this->assertTrue(ResourceRequestStatus::Requested->canTransitionTo(ResourceRequestStatus::Approved));
        $this->assertTrue(ResourceRequestStatus::Approved->canTransitionTo(ResourceRequestStatus::Matched));
        $this->assertTrue(ResourceRequestStatus::Matched->canTransitionTo(ResourceRequestStatus::Dispatched));
        $this->assertTrue(ResourceRequestStatus::Dispatched->canTransitionTo(ResourceRequestStatus::Delivered));
        $this->assertFalse(ResourceRequestStatus::Requested->canTransitionTo(ResourceRequestStatus::Dispatched));
        $this->assertFalse(ResourceRequestStatus::Delivered->canTransitionTo(ResourceRequestStatus::Cancelled));
        $this->assertSame(['in', 'out', 'adjust'], array_map(
            fn (InventoryLedgerReason $r) => $r->value,
            InventoryLedgerReason::cases(),
        ));
    }
}
