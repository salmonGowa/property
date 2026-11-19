<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreTenantDocumentRequest;
use App\Http\Resources\V1\TenantDocumentResource;
use App\Models\Tenant;
use App\Models\TenantDocument;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * KYC documents live on the private disk; files are only served through an authorised request.
 */
class TenantDocumentController extends Controller
{
    public function index(Tenant $tenant): AnonymousResourceCollection
    {
        Gate::authorize('viewDocuments', $tenant);

        return TenantDocumentResource::collection($tenant->documents()->with('uploader:id,name')->latest()->latest('id')->get());
    }

    public function store(StoreTenantDocumentRequest $request, Tenant $tenant): TenantDocumentResource
    {
        $file = $request->file('file');

        $document = $tenant->documents()->create([
            'type' => $request->validated('type'),
            'expires_on' => $request->validated('expires_on'),
            'file_path' => $file->store("kyc/{$tenant->id}", 'local'),
            'original_name' => $file->getClientOriginalName(),
            'uploaded_by' => $request->user()->id,
        ]);

        return new TenantDocumentResource($document->load('uploader:id,name'));
    }

    public function file(TenantDocument $tenantDocument): StreamedResponse
    {
        Gate::authorize('viewDocuments', $tenantDocument->tenant);

        abort_unless(Storage::disk('local')->exists($tenantDocument->file_path), 404, 'The file is missing from storage.');

        return Storage::disk('local')->response($tenantDocument->file_path, $tenantDocument->original_name, [
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function destroy(TenantDocument $tenantDocument): Response
    {
        Gate::authorize('manageDocuments', $tenantDocument->tenant);

        $tenantDocument->delete();

        return response()->noContent();
    }
}
