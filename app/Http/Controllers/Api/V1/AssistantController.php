<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Access\RecordScope;
use App\Domain\AI\EmbeddingProviderFailed;
use App\Domain\AI\EmbeddingProviderNotConfigured;
use App\Domain\AI\LlmProviderFailed;
use App\Domain\AI\ProviderNotConfigured;
use App\Domain\AI\RagAnswerService;
use App\Domain\Audit\AuditLogger;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\AskAssistantRequest;
use App\Http\Requests\Api\V1\CreateConversationRequest;
use App\Http\Resources\Api\V1\AssistantMessageResource;
use App\Http\Resources\Api\V1\ConversationResource;
use App\Models\AssistantMessage;
use App\Models\Citation;
use App\Models\Conversation;
use App\Models\Document;
use App\Models\ProcessingJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AssistantController extends Controller
{
    public function index(Request $request)
    {
        $conversations = Conversation::query()->where('organization_id', $request->user()->organization_id)
            ->where('user_id', $request->user()->id)->latest('updated_at')
            ->paginate(min(max((int) $request->query('perPage', 20), 1), 100));

        return ConversationResource::collection($conversations);
    }

    public function store(CreateConversationRequest $request, AuditLogger $audit): JsonResponse
    {
        $conversation = Conversation::query()->create([
            'organization_id' => $request->user()->organization_id,
            'user_id' => $request->user()->id,
            'case_id' => $request->validated('caseId'),
            'title' => $request->validated('title') ?: 'Nueva consulta',
        ]);
        $audit->record($request, $request->user(), 'assistant.conversation_created', 'conversation', $conversation->id);

        return (new ConversationResource($conversation))->response()->setStatusCode(201);
    }

    public function show(Request $request, string $conversationId): JsonResponse
    {
        $conversation = $this->findConversation($request, $conversationId)->load('messages.citations.document');

        return response()->json([
            'data' => (new ConversationResource($conversation))->resolve(),
            'messages' => AssistantMessageResource::collection($conversation->messages)->resolve(),
        ]);
    }

    public function query(AskAssistantRequest $request, AuditLogger $audit, RagAnswerService $answers): JsonResponse
    {
        $conversation = Conversation::query()->create([
            'organization_id' => $request->user()->organization_id,
            'user_id' => $request->user()->id,
            'case_id' => $request->validated('caseId'),
            'title' => mb_substr($request->validated('question'), 0, 80),
        ]);
        $audit->record($request, $request->user(), 'assistant.query', 'conversation', $conversation->id);

        return $this->ask($request, $conversation->id, $audit, $answers);
    }

    public function summarize(Request $request, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate(['documentoId' => ['required', 'uuid']]);
        $document = app(RecordScope::class)->documents($request->user())->with('currentVersion')->findOrFail($data['documentoId']);
        $this->authorize('view', $document);
        abort_unless($document->current_version_id, 409, 'El documento no tiene una versión almacenada.');
        $text = trim((string) $document->currentVersion?->extracted_text);
        $summary = $text === ''
            ? 'El documento todavía no tiene texto extraído. Solicita el procesamiento OCR antes de generar el resumen.'
            : mb_substr(preg_replace('/\s+/', ' ', $text) ?? $text, 0, 700);
        $job = ProcessingJob::query()->create([
            'organization_id' => $document->organization_id,
            'document_id' => $document->id,
            'document_version_id' => $document->current_version_id,
            'type' => 'summary',
            'status' => 'completed',
            'progress' => 100,
            'result_text' => $summary,
            'started_at' => now(),
            'completed_at' => now(),
        ]);
        $audit->record($request, $request->user(), 'document.summary_requested', 'document', $document->id, ['resumenId' => $job->id]);

        return response()->json([
            'id' => $job->id,
            'documentoId' => $document->id,
            'status' => $job->status,
            'text' => $job->result_text,
        ], 201);
    }

    public function showSummary(Request $request, string $summaryId): JsonResponse
    {
        $job = ProcessingJob::query()->where('organization_id', $request->user()->organization_id)
            ->where('type', 'summary')->whereKey($summaryId)->firstOrFail();
        $document = app(RecordScope::class)->documents($request->user())->whereKey($job->document_id)->first();
        abort_if($document === null, 404);
        $this->authorize('view', $document);

        return response()->json([
            'id' => $job->id,
            'documentoId' => $job->document_id,
            'status' => $job->status,
            'text' => $job->result_text,
        ]);
    }

    public function ask(AskAssistantRequest $request, string $conversationId, AuditLogger $audit, RagAnswerService $answers): JsonResponse
    {
        $conversation = $this->findConversation($request, $conversationId);
        $data = $request->validated();
        $question = AssistantMessage::query()->create([
            'conversation_id' => $conversation->id,
            'role' => 'user',
            'content' => $data['question'],
            'status' => 'completed',
        ]);
        $answer = AssistantMessage::query()->create([
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'content' => '',
            'status' => 'processing',
        ]);
        $audit->record($request, $request->user(), 'assistant.query', 'conversation', $conversation->id, ['questionMessageId' => $question->id]);

        try {
            $filters = array_filter([
                'caseId' => $data['caseId'] ?? $conversation->case_id,
                'documentIds' => $data['documentIds'] ?? null,
            ], fn (mixed $value): bool => $value !== null);
            $narrowed = app(RecordScope::class)->narrow($request->user(), $filters);
            $result = $narrowed === null
                ? ['content' => 'No encontré fragmentos indexados y autorizados que permitan responder esta pregunta.', 'citations' => []]
                : $answers->answer($data['question'], $request->user()->organization_id, $narrowed, $data['answerMode'] ?? 'answer');
        } catch (EmbeddingProviderNotConfigured) {
            $answer->update(['status' => 'failed', 'error_code' => 'embedding_provider_unavailable']);

            return response()->json([
                'code' => 'embedding_provider_unavailable',
                'message' => 'The embedding provider is not configured or enabled.',
                'data' => (new AssistantMessageResource($answer->load('citations.document')))->resolve(),
            ], 503);
        } catch (EmbeddingProviderFailed) {
            $answer->update(['status' => 'failed', 'error_code' => 'embedding_provider_failed']);

            return response()->json([
                'code' => 'embedding_provider_failed',
                'message' => 'The configured embedding provider could not complete the request.',
                'data' => (new AssistantMessageResource($answer->load('citations.document')))->resolve(),
            ], 503);
        } catch (ProviderNotConfigured) {
            $answer->update(['status' => 'failed', 'error_code' => 'llm_provider_unavailable']);

            return response()->json([
                'code' => 'llm_provider_unavailable',
                'message' => 'The LLM provider is not configured or enabled.',
                'data' => (new AssistantMessageResource($answer->load('citations.document')))->resolve(),
            ], 503);
        } catch (LlmProviderFailed) {
            $answer->update(['status' => 'failed', 'error_code' => 'llm_provider_failed']);

            return response()->json([
                'code' => 'llm_provider_failed',
                'message' => 'The configured LLM provider could not complete the request.',
                'data' => (new AssistantMessageResource($answer->load('citations.document')))->resolve(),
            ], 503);
        }

        $answer->update(['content' => $result['content'], 'status' => 'completed']);
        $visibleDocuments = app(RecordScope::class)->documents($request->user())->pluck('id')->all();
        foreach ($result['citations'] as $index => $citation) {
            if (! in_array($citation['documentId'] ?? '', $visibleDocuments, true)) {
                continue;
            }
            Citation::query()->create([
                'assistant_message_id' => $answer->id,
                'reference_number' => $citation['reference'] ?? $index + 1,
                'document_id' => $citation['documentId'],
                'document_version_id' => $citation['versionId'],
                'page' => $citation['page'],
                'excerpt' => $citation['excerpt'],
                'relevance' => $citation['relevance'],
            ]);
        }
        $conversation->touch();

        return response()->json((new AssistantMessageResource($answer->load('citations.document')))->resolve());
    }

    public function showMessage(Request $request, string $messageId): AssistantMessageResource
    {
        $message = AssistantMessage::query()->whereHas('conversation', fn ($query) => $query
            ->where('organization_id', $request->user()->organization_id)
            ->where('user_id', $request->user()->id))
            ->with('citations.document')->whereKey($messageId)->firstOrFail();

        return new AssistantMessageResource($message);
    }

    private function findConversation(Request $request, string $conversationId): Conversation
    {
        return Conversation::query()->where('organization_id', $request->user()->organization_id)
            ->where('user_id', $request->user()->id)->whereKey($conversationId)->firstOrFail();
    }
}
