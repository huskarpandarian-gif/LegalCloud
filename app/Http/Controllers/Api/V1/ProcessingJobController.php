<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Access\RecordScope;
use App\Domain\Audit\AuditLogger;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\ProcessingJobResource;
use App\Jobs\ProcessDocumentOcr;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\LegalCase;
use App\Models\ProcessingJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProcessingJobController extends Controller
{
    public function startOcr(Request $request, string $documentId, AuditLogger $audit): JsonResponse
    {
        $document = app(RecordScope::class)->documents($request->user())->findOrFail($documentId);
        $this->authorize('process', $document);

        if (! $document->current_version_id) {
            return response()->json(['code' => 'document_version_missing', 'message' => 'The document has no stored version.'], 409);
        }

        $activeJob = ProcessingJob::query()->where('document_version_id', $document->current_version_id)
            ->where('type', 'ocr')->whereIn('status', ['queued', 'processing'])->first();
        if ($activeJob) {
            return response()->json(['code' => 'processing_already_active', 'message' => 'OCR is already queued for this version.'], 409);
        }

        [$job] = DB::transaction(function () use ($request, $document, $audit): array {
            $job = ProcessingJob::query()->create([
                'organization_id' => $document->organization_id,
                'document_id' => $document->id,
                'document_version_id' => $document->current_version_id,
                'type' => 'ocr',
                'status' => 'queued',
            ]);
            $document->update(['status' => 'processing']);
            $audit->record($request, $request->user(), 'document.ocr_requested', 'document', $document->id, ['jobId' => $job->id]);
            ProcessDocumentOcr::dispatch($job->id)->afterCommit();

            return [$job];
        });

        return (new ProcessingJobResource($job))->response()->setStatusCode(202);
    }

    public function reprocess(Request $request, string $documentId, string $versionId, AuditLogger $audit): JsonResponse
    {
        $document = app(RecordScope::class)->documents($request->user())->findOrFail($documentId);
        $this->authorize('process', $document);
        $version = DocumentVersion::query()->where('document_id', $document->id)->whereKey($versionId)->firstOrFail();
        $activeJob = ProcessingJob::query()->where('document_version_id', $version->id)
            ->where('type', 'ocr')->whereIn('status', ['queued', 'processing'])->first();
        if ($activeJob) {
            return response()->json(['code' => 'processing_already_active', 'message' => 'OCR is already queued for this version.'], 409);
        }

        $job = DB::transaction(function () use ($request, $document, $version, $audit): ProcessingJob {
            $job = ProcessingJob::query()->create([
                'organization_id' => $document->organization_id,
                'document_id' => $document->id,
                'document_version_id' => $version->id,
                'type' => 'ocr',
                'status' => 'queued',
            ]);
            $document->update(['status' => 'processing']);
            $audit->record($request, $request->user(), 'document.ocr_requested', 'document', $document->id, ['jobId' => $job->id, 'versionId' => $version->id]);
            ProcessDocumentOcr::dispatch($job->id)->afterCommit();

            return $job;
        });

        return (new ProcessingJobResource($job))->response()->setStatusCode(202);
    }

    public function ingest(Request $request, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate([
            'documentoId' => ['nullable', 'uuid'],
            'expedienteId' => ['nullable', 'uuid'],
        ]);
        abort_if(empty($data['documentoId']) && empty($data['expedienteId']), 422, 'Indica un documento o un expediente.');

        $documents = app(RecordScope::class)->documents($request->user())
            ->when($data['documentoId'] ?? null, fn ($query, $id) => $query->whereKey($id))
            ->when($data['expedienteId'] ?? null, function ($query, $id) use ($request) {
                $case = app(RecordScope::class)->cases($request->user())->findOrFail($id);

                return $query->where('case_id', $case->id);
            })
            ->whereNotNull('current_version_id')
            ->get();

        $jobs = $documents->map(function (Document $document) use ($request, $audit) {
            $response = $this->startOcr($request, $document->id, $audit);

            return $response->getStatusCode() === 202 ? $response->getData(true) : null;
        })->filter()->values();

        return response()->json(['data' => $jobs], 202);
    }

    public function show(Request $request, string $jobId): ProcessingJobResource
    {
        $job = ProcessingJob::query()->where('organization_id', $request->user()->organization_id)->whereKey($jobId)->firstOrFail();
        $document = app(RecordScope::class)->documents($request->user())->whereKey($job->document_id)->first();
        abort_if($document === null, 404);
        $this->authorize('process', $document);

        return new ProcessingJobResource($job);
    }
}
