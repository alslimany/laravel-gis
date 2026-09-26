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
use App\Services\FormSubmissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
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
            'attachment' => UploadedFile::fake()->image('site.jpg'),
        ])->assertRedirect();

        $submission = FormSubmission::first();
        $this->assertSame('site.jpg', $submission->attachment_name);
        $this->assertSame('image/jpeg', $submission->attachment_mime);
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}_site\.jpg$/', basename((string) $submission->attachment_path));
        Storage::disk('local')->assertExists($submission->attachment_path);
        $this->assertDatabaseCount('feature_attachments', 0);
    }

    public function test_public_submit_rejects_dangerous_attachments_and_sanitizes_names(): void
    {
        Storage::fake('local');
        $form = $this->storeForm(['name' => 'Attachment guard']);

        $this->post(route('forms.public.submit', $form->share_token), [
            'attributes' => ['site' => 'North well'],
            'attachment' => UploadedFile::fake()->create('shell.php', 4, 'application/x-php'),
        ])->assertSessionHasErrors('attachment');

        $this->post(route('forms.public.submit', $form->share_token), [
            'attributes' => ['site' => 'North well'],
            'attachment' => UploadedFile::fake()->image('evil.php.jpg'),
        ])->assertSessionHasErrors('attachment');

        $this->assertDatabaseCount('form_submissions', 0);

        $image = UploadedFile::fake()->image('secret.jpg');
        $upload = new UploadedFile(
            $image->getPathname(),
            '..\\..\\My Site (north).jpg',
            'image/jpeg',
            null,
            true
        );

        $this->post(route('forms.public.submit', $form->share_token), [
            'attributes' => ['site' => 'North well'],
            'attachment' => $upload,
        ])->assertRedirect();

        $submission = FormSubmission::first();
        $this->assertSame('My_Site_north.jpg', $submission->attachment_name);
        $this->assertSame('image/jpeg', $submission->attachment_mime);
        $stored = basename((string) $submission->attachment_path);
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}_My_Site_north\.jpg$/', $stored);
        $this->assertStringNotContainsString('..', (string) $submission->attachment_path);
        Storage::disk('local')->assertExists($submission->attachment_path);
    }

    public function test_public_submit_hides_internal_exception_details(): void
    {
        $form = $this->storeForm(['name' => 'Fragile form']);

        $this->mock(FormSubmissionService::class, function ($mock) {
            $mock->shouldReceive('record')
                ->once()
                ->andThrow(new \RuntimeException('SQLSTATE[secret] duplicate key'));
        });

        $logged = [];
        Log::listen(function ($message) use (&$logged) {
            $logged[] = $message;
        });

        $response = $this->post(route('forms.public.submit', $form->share_token), [
            'attributes' => ['site' => 'North well'],
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('error', 'Submission failed. Please try again.');
        $this->assertStringNotContainsString('SQLSTATE', (string) session('error'));
        $this->assertStringNotContainsString('duplicate key', (string) session('error'));

        $match = collect($logged)->first(function ($message) use ($form) {
            return $message->level === 'warning'
                && $message->message === 'Form submission failed'
                && ($message->context['form_id'] ?? null) === $form->id
                && str_contains((string) ($message->context['error'] ?? ''), 'SQLSTATE[secret] duplicate key');
        });
        $this->assertNotNull($match);
    }

    public function test_csv_export_prefixes_formula_triggers_including_leading_hyphen(): void
    {
        $form = $this->storeForm(['name' => 'Formula export']);

        foreach (['=1+1', '+1+1', '-1+1', '@SUM(A1)', 'North well'] as $value) {
            $this->submitPublic($form, [
                'attributes' => ['site' => $value],
            ])->assertRedirect();
        }

        FormSubmission::create([
            'form_id' => $form->id,
            'organization_id' => $form->organization_id,
            'attributes' => ['site' => '  -2+2'],
        ]);

        $csv = $this->actingAs($this->editor)->get(route('forms.export.csv', $form));
        $csv->assertOk();
        $content = $csv->getContent();

        $this->assertStringContainsString("'=1+1", $content);
        $this->assertStringContainsString("'+1+1", $content);
        $this->assertStringContainsString("'-1+1", $content);
        $this->assertStringContainsString("'@SUM(A1)", $content);
        $this->assertStringContainsString("'  -2+2", $content);
        $this->assertStringContainsString('North well', $content);
        $this->assertDoesNotMatchRegularExpression('/(?<!\')-1\+1/', $content);
    }

    public function test_optional_layer_migration_down_drops_forms_with_null_layer_id(): void
    {
        $standalone = $this->storeForm(['name' => 'Standalone rollback']);
        $layer = $this->makeLayer();
        $linked = $this->storeForm([
            'name' => 'Linked rollback',
            'layer_id' => $layer->id,
        ]);
        $this->submitPublic($standalone)->assertRedirect();

        $migration = require database_path('migrations/2026_09_26_120000_make_form_layer_optional_and_store_submissions.php');

        try {
            $migration->down();

            $this->assertFalse(Schema::hasTable('form_submissions'));
            $this->assertFalse(Schema::hasColumn('forms', 'collect_geometry'));
            $this->assertDatabaseMissing('forms', ['id' => $standalone->id]);
            $this->assertDatabaseHas('forms', [
                'id' => $linked->id,
                'layer_id' => $layer->id,
            ]);
            $this->assertSame(0, DB::table('forms')->whereNull('layer_id')->count());

            $column = collect(Schema::getColumns('forms'))->firstWhere('name', 'layer_id');
            $this->assertNotNull($column);
            $this->assertFalse((bool) $column['nullable']);
        } finally {
            if (! Schema::hasTable('form_submissions')) {
                $migration->up();
            }
        }
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

    public function test_editor_can_save_one_show_when_rule_without_a_layer(): void
    {
        $form = $this->storeForm([
            'name' => 'Branching survey',
            'schema' => $this->branchingSchema(),
        ]);

        $this->assertNull($form->layer_id);
        $this->assertSame('show', $form->schema[1]['visibility']['action']);
        $this->assertSame('status', $form->schema[1]['visibility']['field']);
        $this->assertSame('equals', $form->schema[1]['visibility']['operator']);
        $this->assertSame('yes', $form->schema[1]['visibility']['value']);
        $this->assertArrayNotHasKey('visibility', $form->schema[0]);

        $this->get(route('forms.public.show', $form->share_token))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Forms/Public')
                ->where('form.schema.1.visibility.action', 'show')
                ->where('form.schema.1.visibility.field', 'status')
                ->where('form.schema.1.visibility.operator', 'equals')
                ->where('form.schema.1.visibility.value', 'yes'));
    }

    public function test_visibility_rule_must_reference_another_field(): void
    {
        $schema = $this->branchingSchema();
        $schema[1]['visibility']['field'] = 'details';

        $this->actingAs($this->editor)
            ->post(route('forms.store'), [
                'name' => 'Self rule',
                'is_public' => true,
                'schema' => $schema,
            ])
            ->assertSessionHasErrors('schema.1.visibility.field');

        $schema[1]['visibility']['field'] = 'status';
        $schema[1]['visibility']['operator'] = 'contains';

        $this->actingAs($this->editor)
            ->post(route('forms.store'), [
                'name' => 'Bad operator',
                'is_public' => true,
                'schema' => $schema,
            ])
            ->assertSessionHasErrors('schema.1.visibility.operator');

        $this->assertDatabaseCount('forms', 0);
    }

    public function test_public_submit_respects_a_show_when_rule(): void
    {
        $form = $this->storeForm([
            'name' => 'Show when',
            'schema' => $this->branchingSchema(),
        ]);

        $this->post(route('forms.public.submit', $form->share_token), [
            'attributes' => ['status' => 'no', 'details' => 'should not stick'],
        ])->assertRedirect(route('forms.public.show', $form->share_token));

        $hidden = FormSubmission::query()->first();
        $this->assertSame('no', $hidden->attributes['status']);
        $this->assertArrayNotHasKey('details', $hidden->attributes);

        $this->post(route('forms.public.submit', $form->share_token), [
            'attributes' => ['status' => 'yes'],
        ])->assertSessionHasErrors('attributes.details');

        $this->post(route('forms.public.submit', $form->share_token), [
            'attributes' => ['status' => 'yes', 'details' => 'Follow up'],
        ])->assertRedirect(route('forms.public.show', $form->share_token));

        $shown = FormSubmission::query()->orderByDesc('id')->first();
        $this->assertSame('Follow up', $shown->attributes['details']);
        $this->assertSame(2, FormSubmission::query()->count());
    }

    public function test_public_submit_respects_a_hide_when_rule(): void
    {
        $schema = $this->branchingSchema();
        $schema[1]['visibility']['action'] = 'hide';
        $schema[1]['visibility']['operator'] = 'not_equals';
        $schema[1]['visibility']['value'] = 'no';

        $form = $this->storeForm([
            'name' => 'Hide unless no',
            'schema' => $schema,
        ]);

        $this->post(route('forms.public.submit', $form->share_token), [
            'attributes' => ['status' => 'yes', 'details' => 'hidden'],
        ])->assertRedirect(route('forms.public.show', $form->share_token));

        $hidden = FormSubmission::query()->first();
        $this->assertArrayNotHasKey('details', $hidden->attributes);

        $this->post(route('forms.public.submit', $form->share_token), [
            'attributes' => ['status' => 'no'],
        ])->assertSessionHasErrors('attributes.details');

        $this->post(route('forms.public.submit', $form->share_token), [
            'attributes' => ['status' => 'no', 'details' => 'Kept'],
        ])->assertRedirect();

        $shown = FormSubmission::query()->orderByDesc('id')->first();
        $this->assertSame('Kept', $shown->attributes['details']);
    }

    public function test_hidden_required_field_does_not_block_a_linked_layer(): void
    {
        $form = $this->storeForm([
            'name' => 'Linked branch',
            'create_layer' => true,
            'collect_geometry' => false,
            'schema' => $this->branchingSchema(),
        ]);

        $this->post(route('forms.public.submit', $form->share_token), [
            'attributes' => ['status' => 'no', 'details' => 'should not stick'],
        ])->assertRedirect(route('forms.public.show', $form->share_token));

        $submission = FormSubmission::query()->first();
        $this->assertNotNull($submission->feature_id);
        $this->assertSame('no', $submission->attributes['status']);
        $this->assertArrayNotHasKey('details', $submission->attributes);

        $layer = Layer::findOrFail($form->layer_id);
        $row = DB::table($layer->table_name)->where('id', $submission->feature_id)->first();
        $this->assertSame('no', $row->status);
        $this->assertNull($row->details);
    }

    public function test_form_show_filters_submissions_and_summarizes_counts(): void
    {
        $form = $this->storeForm([
            'name' => 'Report',
            'schema' => [
                ['name' => 'status', 'label' => 'Status', 'type' => 'select', 'options' => ['open', 'closed']],
                ['name' => 'notes', 'label' => 'Notes', 'type' => 'text'],
            ],
        ]);

        $open = $this->makeSubmission($form, ['status' => 'open', 'notes' => 'North'], [
            'latitude' => 32.1,
            'longitude' => 13.2,
        ]);
        $open->created_at = '2026-01-15 10:00:00';
        $open->save();

        $closed = $this->makeSubmission($form, ['status' => 'closed', 'notes' => 'closed-only-note']);
        $closed->created_at = '2026-02-02 10:00:00';
        $closed->save();

        $later = $this->makeSubmission($form, ['status' => 'open', 'notes' => 'Later'], [
            'attachment_name' => 'photo.jpg',
        ]);
        $later->created_at = '2026-03-20 10:00:00';
        $later->save();

        $this->actingAs($this->editor)
            ->get(route('forms.show', [
                'form' => $form,
                'from' => '2026-01-01',
                'to' => '2026-02-28',
                'field' => 'status',
                'value' => 'open',
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Forms/Show')
                ->where('submissionCount', 1)
                ->where('summary.total', 3)
                ->where('summary.matching', 1)
                ->where('summary.with_location', 1)
                ->where('summary.with_attachment', 0)
                ->where('summary.field.name', 'status')
                ->where('summary.field.counts', [
                    ['value' => 'open', 'count' => 1],
                    ['value' => 'closed', 'count' => 1],
                ])
                ->where('filters.field', 'status')
                ->where('filters.value', 'open')
                ->has('submissions.data', 1)
                ->where('submissions.data.0.attributes.notes', 'North')
                ->where('links.export_csv', route('forms.export.csv', [
                    'form' => $form,
                    'from' => '2026-01-01',
                    'to' => '2026-02-28',
                    'field' => 'status',
                    'value' => 'open',
                ])));

        $csv = $this->actingAs($this->editor)->get(route('forms.export.csv', [
            'form' => $form,
            'field' => 'status',
            'value' => 'closed',
        ]));
        $csv->assertOk();
        $this->assertStringContainsString('closed-only-note', $csv->getContent());
        $this->assertStringNotContainsString('North', $csv->getContent());

        $this->actingAs($this->editor)
            ->get(route('forms.show', ['form' => $form, 'from' => '2026-04-01', 'to' => '2026-03-01']))
            ->assertSessionHasErrors('to');
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

    /**
     * @return list<array<string, mixed>>
     */
    protected function branchingSchema(): array
    {
        return [
            ['name' => 'status', 'label' => 'Status', 'type' => 'select', 'options' => ['yes', 'no']],
            [
                'name' => 'details',
                'label' => 'Details',
                'type' => 'text',
                'required' => true,
                'visibility' => [
                    'action' => 'show',
                    'field' => 'status',
                    'operator' => 'equals',
                    'value' => 'yes',
                ],
            ],
        ];
    }

    protected function makeSubmission(Form $form, array $attributes, array $overrides = []): FormSubmission
    {
        return FormSubmission::create(array_merge([
            'form_id' => $form->id,
            'organization_id' => $form->organization_id,
            'attributes' => $attributes,
        ], $overrides));
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
