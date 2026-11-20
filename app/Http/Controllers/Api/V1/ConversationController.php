<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Notification;
use App\Services\WhatsAppService;
use App\Tenancy\TenantManager;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ConversationController extends ApiController
{
    public function index(Request $request)
    {
        $conversations = Conversation::query()
            ->with(['customer', 'lastMessage'])
            ->withCount(['messages as unread_count' => fn ($q) => $q->where('direction', 'inbound')->whereNull('read_at')])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->when($request->filled('customer_id'), fn ($q) => $q->where('customer_id', $request->integer('customer_id')))
            ->when($request->string('search')->trim()->value(), function ($q, $search) {
                $q->whereHas('customer', fn ($cq) => $cq->where(fn ($w) => $w
                    ->where('name', 'like', "%{$search}%")
                    ->orWhere('business_name', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")));
            })
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->paginate(min($request->integer('per_page', 15), 100));

        return $this->successResponse('Conversations retrieved successfully.', $conversations);
    }

    /**
     * Open (or return) the conversation with a customer.
     */
    public function store(Request $request, WhatsAppService $whatsapp)
    {
        $validated = $request->validate([
            'customer_id' => ['required', 'integer', Rule::exists('customers', 'id')->where('business_id', TenantManager::businessId())->whereNull('deleted_at')],
        ]);

        $conversation = $whatsapp->conversationFor(Customer::query()->findOrFail($validated['customer_id']));

        return $this->successResponse('Conversation ready.', $conversation->load('customer'), $conversation->wasRecentlyCreated ? 201 : 200);
    }

    public function show(int $conversation)
    {
        $conversation = Conversation::query()
            ->with(['customer', 'connection', 'messages' => fn ($q) => $q->latest('id')->limit(50)])
            ->findOrFail($conversation);

        // Opening a conversation marks inbound messages as read, and clears the
        // matching in-app notifications so every session sees the same count.
        $conversation->messages()->inbound()->whereNull('read_at')->update(['read_at' => now(), 'status' => 'read']);
        Notification::query()
            ->where('type', 'message_received')
            ->whereNull('read_at')
            ->where('data->conversation_id', $conversation->id)
            ->update(['read_at' => now()]);

        return $this->successResponse('Conversation retrieved successfully.', $conversation);
    }

    public function update(Request $request, int $conversation)
    {
        $validated = $request->validate(['status' => ['required', Rule::in(Conversation::STATUSES)]]);

        $conversation = Conversation::query()->findOrFail($conversation);
        $conversation->update($validated);

        return $this->successResponse('Conversation updated successfully.', $conversation->load('customer'));
    }

    public function messages(Request $request, int $conversation)
    {
        $conversation = Conversation::query()->findOrFail($conversation);

        $messages = $conversation->messages()
            ->when($request->filled('direction'), fn ($q) => $q->where('direction', $request->input('direction')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->latest('id')
            ->paginate(min($request->integer('per_page', 25), 100));

        return $this->successResponse('Conversation messages retrieved successfully.', $messages);
    }
}
