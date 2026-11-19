<?php

namespace Tests\Feature\Http\Controllers\Api\V1;

use App\Enums\KycDocumentType;
use App\Enums\UserRole;
use App\Models\Tenant;
use App\Models\TenantDocument;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class TenantDocumentApiTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    public function test_property_manager_uploads_a_kyc_document_to_the_private_disk(): void
    {
        $user = User::factory()->withRole(UserRole::PropertyManager)->create(['name' => 'Grace Njeri']);
        $tenant = Tenant::factory()->create();

        $this->actingAs($user)->post("/api/v1/tenants/{$tenant->id}/documents", [
            'type' => 'national_id',
            'expires_on' => '2030-01-01',
            'file' => UploadedFile::fake()->create('id-front.pdf', 200, 'application/pdf'),
        ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('data.type.label', 'National ID / Passport')
            ->assertJsonPath('data.originalName', 'id-front.pdf')
            ->assertJsonPath('data.uploadedBy', 'Grace Njeri');

        $document = TenantDocument::query()->sole();
        $this->assertStringStartsWith("kyc/{$tenant->id}/", $document->file_path);
        Storage::disk('local')->assertExists($document->file_path);

        $this->getJson("/api/v1/tenants/{$tenant->id}/documents")->assertJsonCount(1, 'data');
    }

    public function test_rejects_files_that_are_not_pdfs_or_images(): void
    {
        $tenant = Tenant::factory()->create();

        $this->actingAs(User::factory()->create())->post("/api/v1/tenants/{$tenant->id}/documents", [
            'type' => 'kra_pin',
            'file' => UploadedFile::fake()->create('script.html', 10, 'text/html'),
        ], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['file' => 'Upload a PDF, JPG or PNG file.']);
    }

    public function test_roles_without_kyc_access_cannot_list_or_open_documents(): void
    {
        $document = $this->storedDocument();
        $this->actingAs(User::factory()->withRole(UserRole::Accountant)->create());

        $this->getJson("/api/v1/tenants/{$document->tenant_id}/documents")->assertForbidden();
        $this->getJson("/api/v1/tenant-documents/{$document->id}/file")->assertForbidden();
    }

    public function test_auditor_can_open_a_document_but_not_change_them(): void
    {
        $document = $this->storedDocument();
        $this->actingAs(User::factory()->withRole(UserRole::Auditor)->create());

        $this->get("/api/v1/tenant-documents/{$document->id}/file")
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertStreamedContent('scan');

        $this->deleteJson("/api/v1/tenant-documents/{$document->id}")->assertForbidden();
        $this->post("/api/v1/tenants/{$document->tenant_id}/documents", [], ['Accept' => 'application/json'])->assertForbidden();
    }

    public function test_deleting_a_document_removes_its_file(): void
    {
        $document = $this->storedDocument();

        $this->actingAs(User::factory()->create())
            ->deleteJson("/api/v1/tenant-documents/{$document->id}")
            ->assertNoContent();

        $this->assertModelMissing($document);
        Storage::disk('local')->assertMissing($document->file_path);
    }

    private function storedDocument(): TenantDocument
    {
        $tenant = Tenant::factory()->create();
        Storage::disk('local')->put("kyc/{$tenant->id}/id.pdf", 'scan');

        return $tenant->documents()->create([
            'type' => KycDocumentType::NationalId,
            'file_path' => "kyc/{$tenant->id}/id.pdf",
            'original_name' => 'id.pdf',
        ]);
    }
}
