import { useEffect, useRef, useState } from 'react';
import { isAvatarUrl } from '../utils';

const requestedAvatarRefreshes = new Set<string>();

export function useAvatarSource(
    sourceUrl: string | null,
    contactId: number | null,
) {
    const safeSourceUrl = isAvatarUrl(sourceUrl) ? sourceUrl : null;
    const [avatarUrl, setAvatarUrl] = useState(safeSourceUrl);
    const retryState = useRef({
        sourceUrl: safeSourceUrl,
        contactId,
        hasRetried: false,
        requestVersion: 0,
    });

    useEffect(() => {
        retryState.current = {
            sourceUrl: safeSourceUrl,
            contactId,
            hasRetried: false,
            requestVersion: retryState.current.requestVersion + 1,
        };
        setAvatarUrl(safeSourceUrl);
    }, [contactId, sourceUrl]);

    useEffect(
        () => () => {
            retryState.current.requestVersion += 1;
        },
        [],
    );

    const handleAvatarError = () => {
        const failedUrl = avatarUrl;

        setAvatarUrl(null);

        if (failedUrl === null || contactId === null) {
            return;
        }

        const retryKey = `${contactId}:${failedUrl}`;

        if (
            retryState.current.hasRetried ||
            requestedAvatarRefreshes.has(retryKey)
        ) {
            return;
        }

        retryState.current.hasRetried = true;
        requestedAvatarRefreshes.add(retryKey);

        const requestVersion = retryState.current.requestVersion;

        void fetch(`/whatsapp/contacts/${contactId}/avatar`, {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken(),
            },
            body: JSON.stringify({ force: true }),
        })
            .then(async (response) => {
                if (!response.ok) {
                    return null;
                }

                return response.json() as Promise<{ avatar_url?: unknown }>;
            })
            .then((payload) => {
                const refreshedUrl = payload?.avatar_url;

                if (
                    retryState.current.requestVersion !== requestVersion ||
                    retryState.current.sourceUrl !== failedUrl ||
                    retryState.current.contactId !== contactId ||
                    !isAvatarUrl(refreshedUrl) ||
                    refreshedUrl === failedUrl
                ) {
                    return;
                }

                setAvatarUrl(refreshedUrl);
            })
            .catch(() => undefined);
    };

    return { avatarUrl, handleAvatarError };
}

function csrfToken(): string {
    return (
        document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')
            ?.content ?? ''
    );
}
