<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Message;
use App\Models\Notification;
use Illuminate\Http\Request;

class NotificationController extends ApiController
{
    public function index(Request $request)
    {
        $notifications = Notification::query()
            ->forUser($request->user()->id)
            ->when($request->boolean('unread'), fn ($q) => $q->unread())
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->input('type')))
            ->latest('id')
            ->paginate(min($request->integer('per_page', 20), 100));

        $unread = Notification::query()->forUser($request->user()->id)->unread()->count();

        return response()->json([
            'success' => true,
            'message' => 'Notifications retrieved successfully.',
            'data' => $notifications,
            'meta' => ['unread_count' => $unread],
        ]);
    }

    public function unreadCount(Request $request)
    {
        return $this->successResponse('Unread count retrieved successfully.', [
            'unread_count' => Notification::query()->forUser($request->user()->id)->unread()->count(),
        ]);
    }

    public function markRead(Request $request, int $notification)
    {
        $notification = Notification::query()->forUser($request->user()->id)->findOrFail($notification);
        if (!$notification->read_at) {
            $notification->update(['read_at' => now()]);
            $this->markLinkedMessagesRead(collect([$notification]));
        }

        return $this->successResponse('Notification marked as read.', $notification);
    }

    public function markAllRead(Request $request)
    {
        $unread = Notification::query()->forUser($request->user()->id)->unread()->get();
        $this->markLinkedMessagesRead($unread);
        $count = Notification::query()->forUser($request->user()->id)->unread()->update(['read_at' => now()]);

        return $this->successResponse('All notifications marked as read.', ['updated' => $count]);
    }

    /** Keep conversation unread badges in step with notification read state. */
    private function markLinkedMessagesRead(\Illuminate\Support\Collection $notifications): void
    {
        $ids = $notifications
            ->where('type', 'message_received')
            ->map(fn (Notification $notification) => data_get($notification->data, 'message_id'))
            ->filter()
            ->values();

        if ($ids->isEmpty()) {
            return;
        }

        Message::query()->whereIn('id', $ids)->whereNull('read_at')->update(['read_at' => now(), 'status' => 'read']);
    }

    public function destroy(Request $request, int $notification)
    {
        Notification::query()->forUser($request->user()->id)->findOrFail($notification)->delete();

        return $this->successResponse('Notification deleted.');
    }
}
