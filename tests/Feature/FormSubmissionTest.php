<?php

namespace Tests\Feature;

use App\Models\Form;
use App\Models\FormSubmission;
use App\Models\Layer;
use App\Models\LayerField;
use App\Models\Map;
use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use App\Models\Webhook;
use App\Services\FeatureService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class FormSubmissionTest extends TestCase
{
    use RefreshDatabase;

    protected Organization $organization;

    protected User $editor;

    protected User $viewer;

    protected function setUp(): void
    {
        parent::setUp();

        $editorRole = Role::create(['name' => 'editor', 'description' => 'Editor']);
        Role::create(['name' => 'admin', 'description' => 'Administrator']);

        $this->organization = Organization::factory()->create();
        $this->editor = User::factory()->create([
            'organization_id' => $this->organization->id,
        ]);
        $this->editor->roles()->attach($editorRole);

        $this->viewer = User::factory()->create([
            'organization_id' => $this->organization->id,
        ]);
    }

    public function test_editor_can_create_a_form_without_a_layer(): void
    {
        $form = $this->storeForm();

        $this->assertNull($form->layer_id);
        $this->assertFalse($form->collect_geometry);
        $this->assertTrue($form->is_public);
        $this->assertSame('site', $form->schema[0]['name']);
    }

    public function test_editor_can_link_a_form_to_a_layer(): void
    {
        $layer = $this->makeLayer(['geometry_type' => null]);

        $form = $this->storeForm([
            'layer_id' => $layer->id,
            'schema' => [],
        ]);

        $this->assertSame($layer->id, $form->layer_id);
    }

    public function test_empty_schema_copies_fields_from_the_linked_layer(): void
    {
        $layer = $this->makeLayer(['geometry_type' => null]);
        LayerField::create([
            'layer_id' => $layer->id,
            'name' => 'status',
            'alias' => 'Status',
            'type' => 'string',
            'required' => false,
            'sort_order' => 0,
        ]);

        $form = $this->storeForm([
            'name' => 'Copied fields',
            'layer_id' => $layer->id,
            'schema' => [],
        ]);

        $this->assertSame('status', $form->schema[0]['name']);
        $this->assertSame('Status', $form->schema[0]['label']);
    }

    public function test_editor_can_create_a_layer_from_form_fields(): void
    {
        $form = $this->storeForm([
            'name' => 'Inventory',
            'create_layer' => true,
            'collect_geometry' => false,
        ]);

        $layer = Layer::findOrFail($form->layer_id);
        $this->assertNull($layer->geometry_type);
        $this->assertSame('form', $layer->metadata['source'] ?? null);
        $this->assertTrue(Schema::hasTable($layer->table_name));
        $this->assertTrue(Schema::hasColumn($layer->table_name, 'site'));
        $this->assertSame(1, $layer->fields()->count());
        $this->assertFalse($form->collect_geometry);
    }

    public function test_creating_a_point_layer_from_fields_collects_geometry(): void
    {
        $form = $this->storeForm([
            'name' => 'Points',
            'create_layer' => true,
            'collect_geometry' => true,
        ]);

        $layer = Layer::findOrFail($form->layer_id);
        $this->assertSame('Point', $layer->geometry_type);
        $this->assertTrue($form->collect_geometry);
        $this->assertTrue(Schema::hasColumn($layer->table_name, 'geom'));
    }

    public function test_create_layer_requires_at_least_one_field(): void
    {
        $this->actingAs($this->editor)
            ->post(route('forms.store'), [
                'name' => 'Empty',
                'create_layer' => true,
                'schema' => [['name' => '', 'label' => '', 'type' => 'text']],
            ])
            ->assertSessionHasErrors('schema');

        $this->assertDatabaseCount('forms', 0);
    }

    public function test_form_cannot_link_and_create_a_layer_at_once(): void
    {
        $layer = $this->makeLayer();

        $this->actingAs($this->editor)
            ->post(route('forms.store'), [
                'name' => 'Both',
                'layer_id' => $layer->id,
                'create_layer' => true,
                'schema' => [
                    ['name' => 'site', 'label' => 'Site', 'type' => 'text'],
                ],
            ])
            ->assertSessionHasErrors('layer_id');
    }

    public function test_link_mode_requires_a_chosen_layer(): void
    {
        $this->actingAs($this->editor)
            ->post(route('forms.store'), [
                'name' => 'Missing layer',
                'layer_mode' => 'link',
                'schema' => [
                    ['name' => 'site', 'label' => 'Site', 'type' => 'text'],
                ],
            ])
            ->assertSessionHasErrors('layer_id');
    }

    public function test_layer_from_another_organization_is_rejected(): void
    {
        $otherOrg = Organization::factory()->create();
        $otherUser = User::factory()->create(['organization_id' => $otherOrg->id]);
        $otherLayer = $this->makeLayer([
            'user_id' => $otherUser->id,
            'organization_id' => $otherOrg->id,
        ]);

        $this->actingAs($this->editor)
            ->post(route('forms.store'), [
                'name' => 'Cross org',
                'layer_id' => $otherLayer->id,
                'schema' => [
                    ['name' => 'site', 'label' => 'Site', 'type' => 'text'],
                ],
            ])
            ->assertNotFound();
    }

    public function test_viewer_cannot_create_a_form(): void
    {
        $this->actingAs($this->viewer)
            ->post(route('forms.store'), [
                'name' => 'Blocked',
                'schema' => [
                    ['name' => 'site', 'label' => 'Site', 'type' => 'text'],
                ],
            ])
            ->assertForbidden();
    }

    public function test_standalone_form_collects_lists_and_exports_submissions(): void
    {
        $form = $this->storeForm();

        $this->submitPublic($form)
            ->assertRedirect(route('forms.public.show', $form->share_token))
            ->assertSessionHas('success');

        $submission = FormSubmission::first();
        $this->assertNotNull($submission);
        $this->assertSame($form->id, $submission->form_id);
        $this->assertNull($submission->layer_id);
        $this->assertNull($submission->feature_id);
        $this->assertSame('North well', $submission->attributes['site']);
        $this->assertNull($submission->geometry_wkt);

        $this->actingAs($this->editor)
            ->get(route('forms.show', $form))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Forms/Show')
                ->where('submissionCount', 1)
                ->where('requiresGeometry', false)
                ->where('links.attributes', null)
                ->where('links.map', null)
                ->where('links.layer_csv', null)
                ->where('links.export_csv', route('forms.export.csv', $form))
                ->where('links.export_excel', route('forms.export.excel', $form))
                ->has('submissions.data', 1)
                ->where('submissions.data.0.attributes.site', 'North well'));

        $this->actingAs($this->editor)
            ->get(route('forms.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Forms/Index')
                ->where('forms.data.0.submissions_count', 1)
                ->where('forms.data.0.layer', null));

        $csv = $this->actingAs($this->editor)->get(route('forms.export.csv', $form));
        $csv->assertOk();
        $this->assertStringContainsString('text/csv', (string) $csv->headers->get('content-type'));
        $this->assertStringContainsString('North well', $csv->getContent());
        $this->assertStringContainsString('site', $csv->getContent());

        $excel = $this->actingAs($this->editor)->get(route('forms.export.excel', $form));
        $excel->assertOk();
        $sheet = IOFactory::load($excel->baseResponse->getFile()->getPathname())->getActiveSheet();
        $this->assertSame('site', $sheet->getCell('D1')->getValue());
        $this->assertSame('North well', $sheet->getCell('D2')->getValue());
    }

    public function test_standalone_submission_can_store_an_attachment_without_a_layer(): void
    {
        Storage::fake('local');
        $form = $this->storeForm();

        $this->post(route('forms.public.submit', $form->share_token), [
            'attributes' => ['site' => 'North well'],
            'attachment' => UploadedFile::fake()->create('site.jpg', 20, 'image/jpeg'),
        ])->assertRedirect();

        $submission = FormSubmission::first();
        $this->assertSame('site.jpg', $submission->attachment_name);
        Storage::disk('local')->assertExists($submission->attachment_path);
        $this->assertDatabaseCount('feature_attachments', 0);
    }

    public function test_required_fields_and_private_forms_are_enforced(): void
    {
        $form = $this->storeForm(['is_public' => false]);

        $this->post(route('forms.public.submit', $form->share_token), [
            'attributes' => ['site' => 'North well'],
        ])->assertNotFound();

        $public = $this->storeForm(['name' => 'Public sites']);
        $this->post(route('forms.public.submit', $public->share_token), [
            'attributes' => ['site' => ''],
        ])->assertSessionHasErrors('attributes.site');
        $this->assertDatabaseCount('form_submissions', 0);
    }

    public function test_geometry_is_optional_until_the_form_requires_a_location(): void
    {
        $optional = $this->storeForm(['name' => 'Notes']);
        $this->submitPublic($optional)->assertRedirect();
        $this->assertNull(FormSubmission::first()->geometry_wkt);

        $spatial = $this->storeForm([
            'name' => 'Located notes',
            'collect_geometry' => true,
        ]);

        $this->get(route('forms.public.show', $spatial->share_token))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Forms/Public')
                ->where('requiresGeometry', true));

        $this->post(route('forms.public.submit', $spatial->share_token), [
            'attributes' => ['site' => 'North well'],
        ])->assertSessionHasErrors('wkt');

        $this->post(route('forms.public.submit', $spatial->share_token), [
            'attributes' => ['site' => 'North well'],
            'latitude' => 32,
            'longitude' => 13,
        ])->assertRedirect();

        $submission = FormSubmission::where('form_id', $spatial->id)->first();
        $this->assertSame('POINT(13 32)', $submission->geometry_wkt);
        $this->assertEquals(32.0, $submission->latitude);
        $this->assertEquals(13.0, $submission->longitude);
        $this->assertNull($submission->feature_id);
    }

    public function test_partial_coordinates_are_rejected(): void
    {
        $form = $this->storeForm();

        $this->submitPublic($form, [
            'latitude' => 32,
        ])->assertSessionHasErrors('latitude');

        $this->assertDatabaseCount('form_submissions', 0);
    }

    public function test_linked_spatial_form_writes_a_feature_and_dispatches_webhook(): void
    {
        Http::fake();
        $layer = $this->makeLayer(['geometry_type' => 'Point']);
        $form = $this->storeForm([
            'name' => 'Linked points',
            'layer_id' => $layer->id,
        ]);

        Webhook::create([
            'organization_id' => $this->organization->id,
            'name' => 'Form hook',
            'url' => 'https://hooks.example.test/gis',
            'events' => ['form.submitted'],
            'is_active' => true,
        ]);

        $this->mock(FeatureService::class, function ($mock) use ($layer) {
            $mock->shouldReceive('create')
                ->once()
                ->with(
                    \Mockery::on(fn ($candidate) => $candidate instanceof Layer && $candidate->id === $layer->id),
                    \Mockery::on(fn ($attributes) => ($attributes['site'] ?? null) === 'Tripoli'),
                    'POINT(13 32)'
                )
                ->andReturn(['id' => 7, 'properties' => ['id' => 7]]);
        });

        $this->post(route('forms.public.submit', $form->share_token), [
            'attributes' => ['site' => ''],
        ])->assertSessionHasErrors('attributes.site');
        $this->assertDatabaseCount('form_submissions', 0);

        $this->post(route('forms.public.submit', $form->share_token), [
            'attributes' => ['site' => 'Tripoli'],
        ])->assertSessionHasErrors('wkt');
        $this->assertDatabaseCount('form_submissions', 0);

        $this->post(route('forms.public.submit', $form->share_token), [
            'attributes' => ['site' => 'Tripoli'],
            'latitude' => 32,
            'longitude' => 13,
        ])->assertRedirect();

        $submission = FormSubmission::where('form_id', $form->id)->first();
        $this->assertSame(7, $submission->feature_id);
        $this->assertSame($layer->id, $submission->layer_id);
        $this->assertSame('POINT(13 32)', $submission->geometry_wkt);

        Http::assertSent(function ($request) use ($form) {
            return $request->url() === 'https://hooks.example.test/gis'
                && $request['event'] === 'form.submitted'
                && $request['payload']['form_id'] === $form->id
                && $request['payload']['layer_id'] === $form->layer_id
                && $request['payload']['submission_id'] === FormSubmission::where('form_id', $form->id)->value('id')
                && ($request['payload']['feature']['id'] ?? null) === 7;
        });
    }

    public function test_linked_non_spatial_form_keeps_location_on_the_submission(): void
    {
        $layer = $this->makeLayer(['geometry_type' => null]);
        $form = $this->storeForm([
            'name' => 'Table form',
            'layer_id' => $layer->id,
        ]);

        $this->mock(FeatureService::class, function ($mock) {
            $mock->shouldReceive('create')
                ->once()
                ->with(\Mockery::type(Layer::class), \Mockery::type('array'), null)
                ->andReturn(['id' => 4, 'properties' => ['id' => 4]]);
        });

        $this->post(route('forms.public.submit', $form->share_token), [
            'attributes' => ['site' => 'Office'],
            'latitude' => 32,
            'longitude' => 13,
        ])->assertRedirect();

        $submission = FormSubmission::first();
        $this->assertSame(4, $submission->feature_id);
        $this->assertSame('POINT(13 32)', $submission->geometry_wkt);
    }

    public function test_linked_form_show_links_to_the_attribute_table_and_map(): void
    {
        $layer = $this->makeLayer(['geometry_type' => 'Point', 'name' => 'Wells']);
        $form = $this->storeForm([
            'name' => 'Well form',
            'layer_id' => $layer->id,
        ]);

        Map::create([
            'name' => 'Field map',
            'user_id' => $this->editor->id,
            'organization_id' => $this->organization->id,
            'layers' => [
                ['id' => $layer->id, 'name' => $layer->name, 'type' => 'mvt', 'visible' => true],
            ],
        ]);

        $this->actingAs($this->editor)
            ->get(route('forms.show', $form))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Forms/Show')
                ->where('requiresGeometry', true)
                ->where('links.attributes', route('layers.attributes', $layer))
                ->where('links.map', route('maps.builder', ['layer' => $layer->id]))
                ->where('links.layer_csv', route('forms.export.layer.csv', $form))
                ->where('links.layer_excel', route('forms.export.layer.excel', $form))
                ->has('maps', 1)
                ->where('maps.0.name', 'Field map'));
    }

    public function test_linked_form_exports_layer_features(): void
    {
        $form = $this->storeForm([
            'name' => 'Inventory export',
            'create_layer' => true,
            'collect_geometry' => false,
        ]);
        $layer = Layer::findOrFail($form->layer_id);
        DB::table($layer->table_name)->insert(['site' => 'Warehouse']);

        $csv = $this->actingAs($this->editor)->get(route('forms.export.layer.csv', $form));
        $csv->assertOk();
        $this->assertStringContainsString('Warehouse', $csv->getContent());

        $excel = $this->actingAs($this->editor)->get(route('forms.export.layer.excel', $form));
        $excel->assertOk();
        $sheet = IOFactory::load($excel->baseResponse->getFile()->getPathname())->getActiveSheet();
        $values = $sheet->toArray();
        $flat = array_merge(...array_map(fn ($row) => array_map(fn ($cell) => (string) $cell, $row), $values));
        $this->assertContains('Warehouse', $flat);
    }

    public function test_layer_export_is_unavailable_without_a_layer(): void
    {
        $form = $this->storeForm();

        $this->actingAs($this->editor)
            ->get(route('forms.export.layer.csv', $form))
            ->assertNotFound();
    }

    public function test_guest_and_other_organization_cannot_export_submissions(): void
    {
        $form = $this->storeForm();
        $this->submitPublic($form)->assertRedirect();

        auth()->logout();

        $this->get(route('forms.export.csv', $form))->assertRedirect(route('login'));

        $otherOrg = Organization::factory()->create();
        $other = User::factory()->create(['organization_id' => $otherOrg->id]);
        $other->roles()->attach(Role::where('name', 'editor')->first());

        $this->actingAs($other)->get(route('forms.show', $form))->assertForbidden();
        $this->actingAs($other)->get(route('forms.export.csv', $form))->assertForbidden();
        $this->actingAs($other)->get(route('forms.export.excel', $form))->assertForbidden();
    }

    public function test_update_can_unlink_a_layer(): void
    {
        $layer = $this->makeLayer(['geometry_type' => null]);
        $form = $this->storeForm([
            'layer_id' => $layer->id,
        ]);

        $this->actingAs($this->editor)
            ->put(route('forms.update', $form), [
                'name' => 'Unlinked survey',
                'layer_mode' => 'none',
                'is_public' => true,
                'collect_geometry' => false,
                'schema' => $form->schema,
            ])
            ->assertRedirect(route('forms.show', $form));

        $fresh = $form->fresh();
        $this->assertNull($fresh->layer_id);
        $this->assertSame('Unlinked survey', $fresh->name);
        $this->assertDatabaseHas('layers', ['id' => $layer->id]);
    }

    public function test_standalone_submit_dispatches_webhook_without_a_feature(): void
    {
        Http::fake();
        $form = $this->storeForm(['name' => 'Webhook form']);
        Webhook::create([
            'organization_id' => $this->organization->id,
            'name' => 'Form hook',
            'url' => 'https://hooks.example.test/gis',
            'events' => ['form.submitted'],
            'is_active' => true,
        ]);

        $this->submitPublic($form)->assertRedirect();

        Http::assertSent(function ($request) use ($form) {
            return $request->url() === 'https://hooks.example.test/gis'
                && $request['event'] === 'form.submitted'
                && $request['payload']['form_id'] === $form->id
                && $request['payload']['feature'] === null
                && $request['payload']['layer_id'] === null;
        });
    }

    protected function storeForm(array $overrides = []): Form
    {
        $payload = array_merge([
            'name' => 'Site survey',
            'description' => 'Field notes',
            'is_public' => true,
            'schema' => [
                ['name' => 'site', 'label' => 'Site', 'type' => 'text', 'required' => true],
            ],
        ], $overrides);

        $this->actingAs($this->editor)
            ->post(route('forms.store'), $payload)
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        return Form::query()->where('name', $payload['name'])->latest('id')->firstOrFail();
    }

    protected function submitPublic(Form $form, array $payload = [])
    {
        return $this->post(route('forms.public.submit', $form->share_token), array_merge([
            'attributes' => ['site' => 'North well'],
        ], $payload));
    }

    protected function makeLayer(array $overrides = []): Layer
    {
        return Layer::factory()->create(array_merge([
            'user_id' => $this->editor->id,
            'organization_id' => $this->organization->id,
            'geometry_type' => 'Point',
            'project_id' => null,
        ], $overrides));
    }
}
