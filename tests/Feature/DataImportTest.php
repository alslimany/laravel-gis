<?php

namespace Tests\Feature;

use App\Models\DataImport;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DataImportTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        // Create user with organization
        $this->organization = Organization::factory()->create();
        $this->user = User::factory()->create([
            'organization_id' => $this->organization->id,
        ]);
    }

    public function test_user_can_access_imports_index()
    {
        $response = $this->actingAs($this->user)->get(route('imports.index'));

        $response->assertStatus(200);
        $response->assertViewIs('imports.index');
    }

    public function test_user_can_access_import_create_form()
    {
        $response = $this->actingAs($this->user)->get(route('imports.create'));

        $response->assertStatus(200);
        $response->assertViewIs('imports.create');
    }

    public function test_user_can_upload_geojson_file()
    {
        Storage::fake('local');

        $file = UploadedFile::fake()->create('test.geojson', 100, 'application/geo+json');

        $response = $this->actingAs($this->user)->post(route('imports.store'), [
            'file' => $file,
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('data_imports', [
            'user_id' => $this->user->id,
            'organization_id' => $this->organization->id,
            'file_type' => 'geojson',
            'status' => 'pending',
        ]);
    }

    public function test_user_can_view_import_details()
    {
        $import = DataImport::factory()->create([
            'user_id' => $this->user->id,
            'organization_id' => $this->organization->id,
        ]);

        $response = $this->actingAs($this->user)->get(route('imports.show', $import));

        $response->assertStatus(200);
        $response->assertViewIs('imports.show');
        $response->assertViewHas('import', $import);
    }

    public function test_user_cannot_view_other_users_import()
    {
        $otherOrganization = Organization::factory()->create();
        $otherUser = User::factory()->create([
            'organization_id' => $otherOrganization->id,
        ]);

        $import = DataImport::factory()->create([
            'user_id' => $otherUser->id,
            'organization_id' => $otherOrganization->id,
        ]);

        $response = $this->actingAs($this->user)->get(route('imports.show', $import));

        $response->assertStatus(403);
    }

    public function test_user_can_delete_their_import()
    {
        Storage::fake('local');

        $import = DataImport::factory()->create([
            'user_id' => $this->user->id,
            'organization_id' => $this->organization->id,
            'file_path' => 'test.geojson',
        ]);

        $response = $this->actingAs($this->user)->delete(route('imports.destroy', $import));

        $response->assertRedirect(route('imports.index'));
        $this->assertDatabaseMissing('data_imports', ['id' => $import->id]);
    }

    public function test_upload_validates_required_file()
    {
        $response = $this->actingAs($this->user)->post(route('imports.store'), []);

        $response->assertSessionHasErrors('file');
    }

    public function test_upload_validates_file_size()
    {
        Storage::fake('local');

        // Create a file larger than 100MB (mocked)
        $file = UploadedFile::fake()->create('large.geojson', 200000, 'application/geo+json');

        $response = $this->actingAs($this->user)->post(route('imports.store'), [
            'file' => $file,
        ]);

        $response->assertSessionHasErrors('file');
    }

    public function test_import_status_endpoint_returns_json()
    {
        $import = DataImport::factory()->create([
            'user_id' => $this->user->id,
            'organization_id' => $this->organization->id,
            'status' => 'processing',
            'progress' => 50,
        ]);

        $response = $this->actingAs($this->user)->get(route('imports.status', $import));

        $response->assertStatus(200);
        $response->assertJson([
            'status' => 'processing',
            'progress' => 50,
        ]);
    }

    public function test_data_import_model_has_correct_relationships()
    {
        $import = DataImport::factory()->create([
            'user_id' => $this->user->id,
            'organization_id' => $this->organization->id,
        ]);

        $this->assertInstanceOf(User::class, $import->user);
        $this->assertInstanceOf(Organization::class, $import->organization);
        $this->assertEquals($this->user->id, $import->user->id);
        $this->assertEquals($this->organization->id, $import->organization->id);
    }

    public function test_data_import_status_methods()
    {
        $import = DataImport::factory()->create([
            'status' => 'pending',
        ]);

        $this->assertTrue($import->isPending());
        $this->assertFalse($import->isProcessing());
        $this->assertFalse($import->isCompleted());
        $this->assertFalse($import->isFailed());

        $import->markAsProcessing();
        $this->assertTrue($import->isProcessing());

        $import->markAsCompleted('test_table', 'Point', 100);
        $this->assertTrue($import->isCompleted());
        $this->assertEquals('test_table', $import->table_name);
        $this->assertEquals('Point', $import->geometry_type);
        $this->assertEquals(100, $import->feature_count);

        $import = DataImport::factory()->create(['status' => 'pending']);
        $import->markAsFailed('Test error');
        $this->assertTrue($import->isFailed());
        $this->assertEquals('Test error', $import->error_message);
    }
}
