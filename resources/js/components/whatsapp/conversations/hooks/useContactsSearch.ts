import { useEffect, useState } from 'react';
import { CONTACTS_LOAD_ERROR } from '../constants';
import type { AgendaContact } from '../types';

export function useContactsSearch(search: string): {
    contacts: AgendaContact[];
    loading: boolean;
    error: string | null;
} {
    const [contacts, setContacts] = useState<AgendaContact[]>([]);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState<string | null>(null);

    useEffect(() => {
        const abortController = new AbortController();
        const timeout = window.setTimeout(() => {
            setLoading(true);
            setError(null);

            const query = new URLSearchParams();
            const normalizedSearch = search.trim();

            if (normalizedSearch !== '') {
                query.set('search', normalizedSearch);
            }

            void fetch(
                `/whatsapp/contacts${query.toString() ? `?${query.toString()}` : ''}`,
                {
                    headers: { Accept: 'application/json' },
                    signal: abortController.signal,
                },
            )
                .then((response) => {
                    if (!response.ok) {
                        throw new Error(CONTACTS_LOAD_ERROR);
                    }

                    return response.json() as Promise<{
                        contacts?: AgendaContact[];
                    }>;
                })
                .then((payload) =>
                    setContacts(
                        Array.isArray(payload.contacts) ? payload.contacts : [],
                    ),
                )
                .catch((fetchError: unknown) => {
                    if (
                        fetchError instanceof DOMException &&
                        fetchError.name === 'AbortError'
                    ) {
                        return;
                    }

                    setContacts([]);
                    setError(CONTACTS_LOAD_ERROR);
                })
                .finally(() => {
                    if (!abortController.signal.aborted) {
                        setLoading(false);
                    }
                });
        }, 250);

        return () => {
            window.clearTimeout(timeout);
            abortController.abort();
        };
    }, [search]);

    return { contacts, loading, error };
}
