type ConnectQrPanelProps = {
    qrCode: string | null;
    statusMessage: string;
    hasBlockingError: boolean;
    isLoadingQr: boolean;
};

export function ConnectQrPanel({
    qrCode,
    statusMessage,
    hasBlockingError,
    isLoadingQr,
}: ConnectQrPanelProps) {
    return (
        <aside className="app-surface-raised flex min-h-full flex-col rounded-2xl border p-5 lg:sticky lg:top-6">
            <div>
                <p className="text-sm font-medium">{statusMessage}</p>
                <div className="mt-4 flex aspect-square w-full items-center justify-center rounded-xl border border-(--app-border) bg-white p-4">
                    {qrCode ? (
                        <img
                            src={qrCode}
                            alt="QR para vincular WhatsApp"
                            className="h-full w-full object-contain"
                        />
                    ) : hasBlockingError ? (
                        <div className="text-center text-sm text-red-700">
                            <div className="mx-auto flex h-12 w-12 items-center justify-center rounded-full border border-red-200 bg-red-50 text-xl font-semibold">
                                !
                            </div>
                            <p className="mt-3 font-medium">No se pudo obtener el QR.</p>
                        </div>
                    ) : (
                        <div className="text-center text-sm text-zinc-700">
                            <div className="mx-auto h-10 w-10 animate-spin rounded-full border-2 border-gray-200 border-t-green-600" />
                            <p className="mt-4 font-medium">
                                {isLoadingQr
                                    ? "Preparando conexión…"
                                    : "Esperando estado..."}
                            </p>
                        </div>
                    )}
                </div>
            </div>
        </aside>
    );
}
