<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Audit\AuditLogger;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\NotificationResource;
use App\Models\UserNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function index(Request $request)
    {
        return NotificationResource::collection(UserNotification::query()->where('organization_id', $request->user()->organization_id)
            ->where('user_id', $request->user()->public_id)
            ->when($request->boolean('unreadOnly'), fn ($query) => $query->whereNull('read_at'))
            ->latest()->paginate(min(max((int) $request->query('perPage', 20), 1), 100)));
    }

    public function markRead(Request $request, string $notificationId, AuditLogger $audit): JsonResponse
    {
        $notification = UserNotification::query()->where('organization_id', $request->user()->organization_id)
            ->where('user_id', $request->user()->public_id)->whereKey($notificationId)->firstOrFail();
        $notification->update(['read_at' => now()]);
        $audit->record($request, $request->user(), 'notification.read', 'notification', $notification->id);

        return response()->json(null, 204);
    }

    public function unreadCount(Request $request): JsonResponse
    {
        $count = UserNotification::query()->where('organization_id', $request->user()->organization_id)
            ->where('user_id', $request->user()->public_id)->whereNull('read_at')->count();

        return response()->json(['count' => $count]);
    }

    public function markAllRead(Request $request, AuditLogger $audit): JsonResponse
    {
        $updated = UserNotification::query()->where('organization_id', $request->user()->organization_id)
            ->where('user_id', $request->user()->public_id)->whereNull('read_at')->update(['read_at' => now()]);
        $audit->record($request, $request->user(), 'notification.read_all', 'notification', $request->user()->public_id, ['count' => $updated]);

        return response()->json(['updated' => $updated]);
    }
}
