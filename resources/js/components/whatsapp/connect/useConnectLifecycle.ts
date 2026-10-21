import { router } from "@inertiajs/react";
import { useEffect } from "react";
import toast from "react-hot-toast";

type UseConnectLifecycleOptions = {
    flashSuccess?: string | null;
    flashError?: string | null;
    openwaError?: string | null;
    hasBlockingError: boolean;
    hasQrCode: boolean;
    isReady: boolean;
};

export function useConnectLifecycle({
    flashSuccess,
    flashError,
    openwaError,
    hasBlockingError,
    hasQrCode,
    isReady,
}: UseConnectLifecycleOptions) {
    useEffect(() => {
        if (flashSuccess) {
            toast.success(flashSuccess);
        }

        if (flashError || (openwaError && hasBlockingError)) {
            toast.error(flashError ?? "No pudimos conectar. Reintentá en unos segundos.");
        }
    }, [flashError, flashSuccess, hasBlockingError, openwaError]);

    useEffect(() => {
        if (!isReady) {
            return;
        }

        router.visit("/whatsapp/conversations");
    }, [isReady]);

    useEffect(() => {
        if (isReady || hasBlockingError) {
            return;
        }

        const pollingInterval = hasQrCode ? 12000 : 5000;

        const interval = window.setInterval(() => {
            router.reload({
                only: ["openwa", "flash"],
                preserveScroll: true,
            });
        }, pollingInterval);

        return () => window.clearInterval(interval);
    }, [hasBlockingError, hasQrCode, isReady]);
}
