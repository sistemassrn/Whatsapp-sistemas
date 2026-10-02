<?php

namespace App\Http\Controllers;

use App\Models\Conversation;
use App\Models\Message;
use App\Services\WhatsappConnectionStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class WhatsappConversationsController extends Controller
{
    public function __invoke(Request $request, WhatsappConnectionStatus $connectionStatus): Response|RedirectResponse
    {
        $connection = $connectionStatus->connection(autoStart: true, includeQr: false);

        if (! $connection['isReady']) {
            return redirect()->route('whatsapp.connect');
        }

        $validated = $request->validate([
            'chat' => ['nullable', 'string'],
            'chat_search' => ['nullable', 'string', 'max:100'],
            'message_limit' => ['nullable', 'integer'],
        ]);

        $selectedChatId = $request->string('chat')->toString();
        $chatSearch = trim((string) ($validated['chat_search'] ?? ''));
        $selectedConversation = null;
        $messageLimit = min(max($request->integer('message_limit', 50), 1), 300);
        $selectedConversationMessageCount = 0;
        $firstUnreadMessageId = null;

        $conversationsQuery = Conversation::query()
            ->with(['contact', 'lastMessage'])
            ->tap(fn (Builder $query): Builder => self::withLatestMessageSnapshot($query))
            ->when($chatSearch !== '', fn (Builder $query): Builder => self::applyChatSearch($query, $chatSearch))
            ->orderByDesc('latest_message_timestamp')
            ->orderByDesc('updated_at');

        $conversations = $conversationsQuery
            ->limit(50)
            ->get();

        $messages = collect();

        if ($selectedChatId !== '') {
            $selectedConversation = Conversation::query()
                ->tap(fn (Builder $query): Builder => self::withLatestMessageSnapshot($query))
                ->where('external_id', $selectedChatId)
                ->when($chatSearch !== '', fn (Builder $query): Builder => self::applyChatSearch($query, $chatSearch))
                ->first();

            if ($selectedConversation === null && $chatSearch !== '') {
                $selectedConversation = $conversations->first();
            }

            if ($selectedConversation !== null) {
                if (! $conversations->contains('id', $selectedConversation->id)) {
                    $selectedConversation->load(['contact', 'lastMessage']);
                    $conversations = $conversations->prepend($selectedConversation)->take(50)->values();
                }

                $messagesQuery = Message::query()
                    ->where('conversation_id', $selectedConversation->id);

                $selectedConversationMessageCount = (clone $messagesQuery)->count();

                $messages = $messagesQuery
                    ->orderByRaw('COALESCE(sent_at, received_at, created_at) desc')
                    ->limit($messageLimit)
                    ->get()
                    ->sortBy(fn (Message $message) => $message->sent_at ?? $message->received_at ?? $message->created_at)
                    ->values();

                $firstUnreadMessageId = $this->firstUnreadMessageId($selectedConversation);

                $this->markConversationRead($selectedConversation);
                $conversations->each(function (Conversation $conversation) use ($selectedConversation): void {
                    if ($conversation->id === $selectedConversation->id) {
                        $conversation->forceFill([
                            'last_read_at' => $selectedConversation->last_read_at,
                            'marked_unread_at' => null,
                            'unread_count' => 0,
                        ]);
                    }
                });
            }
        }

        $latestMessages = Message::query()
            ->whereIn('id', $conversations->pluck('latest_message_id')->filter()->unique()->values())
            ->get()
            ->keyBy('id');

        return Inertia::render('whatsapp/conversations', [
            'operator' => $request->user() === null ? null : [
                'name' => $request->user()->nombre ?: $request->user()->usuario,
                'usuario' => $request->user()->usuario,
            ],
            'filters' => [
                'chat_search' => $chatSearch,
                'message_search' => '',
            ],
            'flash' => [
                'success' => session('success'),
                'error' => session('error'),
            ],
            'connection' => $connection,
            'conversations' => $conversations->map(function (Conversation $conversation) use ($latestMessages): array {
                $latestMessage = $latestMessages->get($conversation->getAttribute('latest_message_id')) ?? $conversation->lastMessage;
                $latestMessageAt = $latestMessage?->sent_at ?? $latestMessage?->received_at ?? $latestMessage?->created_at ?? $conversation->last_message_at;

                return [
                    'id' => $conversation->id,
                    'external_id' => $conversation->external_id,
                    'contact_id' => $conversation->contact_id,
                    'title' => self::conversationDisplayName($conversation),
                    'contact_name' => self::contactDisplayName($conversation),
                    'avatar_url' => $conversation->contact?->profile_photo_url,
                    'last_message_preview' => self::messagePreview($latestMessage),
                    'last_message_body' => $latestMessage?->body,
                    'last_message_direction' => $latestMessage?->direction,
                    'last_message_at' => $latestMessageAt?->toISOString(),
                    'last_read_at' => $conversation->last_read_at?->toISOString(),
                    'marked_unread_at' => $conversation->marked_unread_at?->toISOString(),
                    'unread_count' => $conversation->unread_count,
                ];
            }),
            'selectedChatId' => $selectedConversation?->external_id ?? ($selectedChatId !== '' ? $selectedChatId : null),
            'messageLimit' => $messageLimit,
            'hasMoreMessages' => $selectedConversationMessageCount > $messageLimit,
            'firstUnreadMessageId' => $firstUnreadMessageId,
            'messages' => $messages->map(fn (Message $message): array => [
                'id' => $message->id,
                'external_id' => $message->external_id,
                'direction' => $message->direction,
                'body' => $message->body,
                'type' => $message->type,
                'status' => $message->status,
                'error_message' => $message->error_message,
                'edited_at' => $message->edited_at?->toISOString(),
                'deleted_at' => $message->deleted_at?->toISOString(),
                'remote_edit_status' => $message->remote_edit_status,
                'remote_delete_status' => $message->remote_delete_status,
                'edit_error' => $message->edit_error,
                'delete_error' => $message->delete_error,
                'can_edit' => self::canMutateMessage($message),
                'can_delete' => self::canMutateMessage($message),
                'media_url' => $message->media_disk === 'whatsapp_media' && $message->media_path !== null
                    ? route('whatsapp.messages.media.show', $message)
                    : null,
                'media_mime_type' => $message->media_mime_type,
                'media_filename' => $message->media_filename,
                'media_size_bytes' => $message->media_size_bytes,
                'media_download_status' => $message->media_download_status,
                'media_error' => $message->media_error,
                'sent_at' => $message->sent_at?->toISOString(),
                'received_at' => $message->received_at?->toISOString(),
                'created_at' => $message->created_at?->toISOString(),
            ]),
            'emptyState' => $conversations->isEmpty()
                ? 'No existen conversaciones actualmente.'
                : null,
        ]);
    }

    public function markUnread(Request $request, Conversation $conversation): RedirectResponse
    {
        $conversation->forceFill([
            'marked_unread_at' => now(),
        ])->save();

        $redirectParameters = [
            'chat_search' => $request->string('chat_search')->toString(),
        ];

        if ($request->string('chat')->toString() !== '') {
            $redirectParameters['chat'] = $request->string('chat')->toString();
        }

        return redirect()->route('whatsapp.conversations', $redirectParameters);
    }

    private function markConversationRead(Conversation $conversation): void
    {
        if ($conversation->unread_count === 0 && $conversation->marked_unread_at === null) {
            return;
        }

        $conversation->forceFill([
            'last_read_at' => now(),
            'marked_unread_at' => null,
            'unread_count' => 0,
        ])->save();
    }

    private function firstUnreadMessageId(Conversation $conversation): ?int
    {
        if ($conversation->unread_count === 0 && $conversation->marked_unread_at === null) {
            return null;
        }

        if ($conversation->unread_count === 0 && $conversation->marked_unread_at !== null) {
            return Message::query()
                ->where('conversation_id', $conversation->id)
                ->orderByRaw('COALESCE(sent_at, received_at, created_at) desc')
                ->orderByDesc('id')
                ->value('id');
        }

        return Message::query()
            ->where('conversation_id', $conversation->id)
            ->when($conversation->last_read_at !== null, function (Builder $query) use ($conversation): void {
                $query->whereRaw('COALESCE(sent_at, received_at, created_at) > ?', [$conversation->last_read_at]);
            })
            ->orderByRaw('COALESCE(sent_at, received_at, created_at) asc')
            ->orderBy('id')
            ->value('id');
    }

    private static function likeContains(string $value): string
    {
        return '%'.str_replace(
            ['\\', '%', '_', '['],
            ['\\\\', '\\%', '\\_', '\\['],
            $value,
        ).'%';
    }

    private static function likeSql(string $column): string
    {
        return "{$column} LIKE ? ESCAPE '\\'";
    }

    private static function canMutateMessage(Message $message): bool
    {
        return $message->direction === 'outbound'
            && $message->type === 'text'
            && $message->status === 'accepted'
            && $message->external_id !== null
            && $message->external_id !== ''
            && $message->deleted_at === null;
    }

    private static function messagePreview(?Message $message): ?string
    {
        if ($message === null) {
            return null;
        }

        if ($message->deleted_at !== null) {
            return 'Mensaje eliminado';
        }

        if (is_string($message->body) && trim($message->body) !== '') {
            return $message->body;
        }

        return match ($message->type) {
            'image' => 'Imagen',
            'video' => 'Video',
            'audio' => 'Audio',
            'document' => 'Documento',
            'text' => 'Mensaje sin contenido',
            default => 'Archivo',
        };
    }

    private static function conversationDisplayName(Conversation $conversation): string
    {
        foreach ([
            $conversation->title,
            $conversation->contact?->name,
            $conversation->contact?->push_name,
            $conversation->contact?->phone,
            $conversation->contact?->external_id,
            $conversation->external_id,
        ] as $candidate) {
            $displayName = self::cleanDisplayIdentifier($candidate);

            if ($displayName !== null) {
                return $displayName;
            }
        }

        return 'Chat sin nombre';
    }

    private static function contactDisplayName(Conversation $conversation): ?string
    {
        foreach ([$conversation->contact?->name, $conversation->contact?->push_name, $conversation->contact?->phone] as $candidate) {
            $displayName = self::cleanDisplayIdentifier($candidate);

            if ($displayName !== null) {
                return $displayName;
            }
        }

        return null;
    }

    private static function cleanDisplayIdentifier(?string $value): ?string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        if (str_contains($value, '@')) {
            $value = Str::before($value, '@');
        }

        $value = trim($value);

        if ($value === '' || preg_match('/^[a-f0-9]{24,}$/i', $value) === 1) {
            return null;
        }

        return $value;
    }

    private static function applyChatSearch(Builder $query, string $chatSearch): Builder
    {
        $like = self::likeContains($chatSearch);

        return $query->where(function (Builder $query) use ($like): void {
            $query
                ->whereRaw(self::likeSql('title'), [$like])
                ->orWhereRaw(self::likeSql('external_id'), [$like])
                ->orWhereHas('contact', function (Builder $query) use ($like): void {
                    $query
                        ->whereRaw(self::likeSql('name'), [$like])
                        ->orWhereRaw(self::likeSql('push_name'), [$like])
                        ->orWhereRaw(self::likeSql('phone'), [$like])
                        ->orWhereRaw(self::likeSql('external_id'), [$like]);
                });
        });
    }

    private static function withLatestMessageSnapshot(Builder $query): Builder
    {
        return $query->addSelect([
            'latest_message_id' => self::latestMessageSubquery()->select('id'),
            'latest_message_timestamp' => self::latestMessageSubquery()->selectRaw('COALESCE(sent_at, received_at, created_at)'),
        ]);
    }

    private static function latestMessageSubquery(): Builder
    {
        return Message::query()
            ->whereColumn('conversation_id', 'conversations.id')
            ->orderByRaw('COALESCE(sent_at, received_at, created_at) desc')
            ->orderByDesc('id')
            ->limit(1);
    }
}
