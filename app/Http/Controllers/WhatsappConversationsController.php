<?php

namespace App\Http\Controllers;

use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class WhatsappConversationsController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $selectedChatId = $request->string('chat')->toString();
        $selectedConversation = null;
        $messageLimit = min(max($request->integer('message_limit', 50), 1), 300);
        $selectedConversationMessageCount = 0;

        $conversations = Conversation::query()
            ->with(['contact', 'lastMessage'])
            ->orderByDesc('last_message_at')
            ->orderByDesc('updated_at')
            ->limit(50)
            ->get();

        $messages = collect();

        if ($selectedChatId !== '') {
            $selectedConversation = Conversation::query()
                ->where('external_id', $selectedChatId)
                ->first();

            if ($selectedConversation !== null) {
                $selectedConversationMessageCount = Message::query()
                    ->where('conversation_id', $selectedConversation->id)
                    ->count();

                $messages = Message::query()
                    ->where('conversation_id', $selectedConversation->id)
                    ->orderByRaw('COALESCE(sent_at, received_at, created_at) desc')
                    ->limit($messageLimit)
                    ->get()
                    ->sortBy(fn (Message $message) => $message->sent_at ?? $message->received_at ?? $message->created_at)
                    ->values();
            }
        }

        return Inertia::render('whatsapp/conversations', [
            'operator' => session('testing_operator'),
            'conversations' => $conversations->map(fn (Conversation $conversation): array => [
                'id' => $conversation->id,
                'external_id' => $conversation->external_id,
                'title' => $conversation->title,
                'contact_name' => $conversation->contact?->name ?? $conversation->contact?->push_name,
                'last_message_body' => $conversation->lastMessage?->body,
                'last_message_direction' => $conversation->lastMessage?->direction,
                'last_message_at' => $conversation->last_message_at?->toISOString(),
            ]),
            'selectedChatId' => $selectedChatId !== '' ? $selectedChatId : null,
            'messageLimit' => $messageLimit,
            'hasMoreMessages' => $selectedConversationMessageCount > $messageLimit,
            'messages' => $messages->map(fn (Message $message): array => [
                'id' => $message->id,
                'external_id' => $message->external_id,
                'direction' => $message->direction,
                'body' => $message->body,
                'status' => $message->status,
                'sent_at' => $message->sent_at?->toISOString(),
                'received_at' => $message->received_at?->toISOString(),
                'created_at' => $message->created_at?->toISOString(),
            ]),
            'emptyState' => $conversations->isEmpty()
                ? 'No hay conversaciones persistidas todavía. Ejecutá php artisan whatsapp:sync-initial.'
                : null,
        ]);
    }
}
