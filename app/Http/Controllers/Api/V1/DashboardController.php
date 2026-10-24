<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Access\RecordScope;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\AuditEventResource;
use App\Models\AuditEvent;
use App\Models\ProcessingJob;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function show(Request $request, RecordScope $scope)
    {
        $user = $request->user();
        $payload = ['recentActivity' => []];

        if ($user->allows('cases.read')) {
            $cases = $scope->cases($user);
            $payload['cases'] = [
                'total' => (clone $cases)->count(),
                'open' => (clone $cases)->where('status', 'open')->count(),
                'inReview' => (clone $cases)->where('status', 'in_review')->count(),
            ];
        }

        if ($user->allows('documents.read')) {
            $documents = $scope->documents($user);
            $payload['documents'] = [
                'total' => (clone $documents)->count(),
                'indexed' => (clone $documents)->where('status', 'indexed')->count(),
                'pendingOcr' => (clone $documents)->where('status', 'processing')->count(),
            ];
        }

        if ($user->allows('documents.process')) {
            $jobs = ProcessingJob::query()->where('organization_id', $user->organization_id);
            $payload['processing'] = [
                'queued' => (clone $jobs)->where('status', 'queued')->count(),
                'failed' => (clone $jobs)->where('status', 'failed')->count(),
            ];
        }

        if ($user->allows('audit.read')) {
            $payload['recentActivity'] = AuditEventResource::collection(
                AuditEvent::query()->where('organization_id', $user->organization_id)->orderByDesc('occurred_at')->limit(5)->get(),
            )->resolve();
        }

        return response()->json($payload);
    }
}
