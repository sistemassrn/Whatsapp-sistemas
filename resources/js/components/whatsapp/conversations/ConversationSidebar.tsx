import { Settings, UserRound } from 'lucide-react';
import type { RefObject } from 'react';
import { ThemeToggle } from '../../../theme';
import { ConversationRow } from './ConversationRow';
import { SearchForm } from './SearchForm';
import type { ConversationItem, Props } from './types';

export function ConversationSidebar({
    operatorName,
    chatSearch,
    onChatSearchChange,
    onChatSearchClear,
    onOpenAgenda,
    onOpenMaintenance,
    conversations,
    selectedChatId,
    filters,
    emptyState,
    conversationListRef,
    onSelect,
}: {
    operatorName: string;
    chatSearch: string;
    onChatSearchChange: (value: string) => void;
    onChatSearchClear: () => void;
    onOpenAgenda: () => void;
    onOpenMaintenance: () => void;
    conversations: ConversationItem[];
    selectedChatId: string | null;
    filters: Props['filters'];
    emptyState: string | null | undefined;
    conversationListRef: RefObject<HTMLDivElement | null>;
    onSelect: (chatId: string) => void;
}) {
    return (
        <aside className="app-surface flex w-full flex-col border-r md:w-115 md:shrink-0">
            <div className="app-surface border-b p-4">
                <div className="flex items-center justify-between gap-3">
                    <div>
                        <h1 className="text-xl font-semibold">Chats</h1>
                        <p className="app-faint mt-1 text-xs">
                            {operatorName}
                        </p>
                    </div>

                    <div className="flex shrink-0 flex-wrap justify-end gap-2">
                        <ThemeToggle />
                        <button
                            type="button"
                            onClick={onOpenAgenda}
                            className="app-button grid size-9 place-items-center rounded-lg border transition"
                            aria-label="Abrir agenda"
                        >
                            <UserRound className="size-4" />
                        </button>
                        <button
                            type="button"
                            onClick={onOpenMaintenance}
                            className="app-button grid size-9 place-items-center rounded-lg border transition"
                            aria-label="Abrir mantenimiento"
                        >
                            <Settings className="size-4" />
                        </button>
                    </div>
                </div>

                <SearchForm
                    placeholder="Buscar chats o contactos"
                    value={chatSearch}
                    onChange={onChatSearchChange}
                    onSubmit={() => undefined}
                    onClear={onChatSearchClear}
                    className="mt-4"
                />

                {/* <div className="mt-3 flex gap-2 text-xs font-medium">
                    <span className="rounded-full border border-green-600 bg-green-50 px-3 py-1 text-green-700">Todos</span>
                    <span className="rounded-full border border-gray-200 bg-white px-3 py-1 text-gray-500">No leídos</span>
                    <span className="rounded-full border border-gray-200 bg-white px-3 py-1 text-gray-500">Pendientes</span>
                </div> */}
            </div>

            <div
                ref={conversationListRef}
                className="min-h-0 flex-1 overflow-y-auto"
            >
                {conversations.length === 0 ? (
                    <p className="app-muted p-4 text-sm leading-6">
                        {emptyState ??
                            'No hay conversaciones para mostrar todavía.'}
                    </p>
                ) : (
                    conversations.map((conversation) => (
                        <ConversationRow
                            key={conversation.id}
                            conversation={conversation}
                            active={
                                selectedChatId === conversation.external_id
                            }
                            filters={filters}
                            onSelect={onSelect}
                        />
                    ))
                )}
            </div>
        </aside>
    );
}
