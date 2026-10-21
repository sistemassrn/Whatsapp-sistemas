import { ThemeToggle } from "@/theme";
import { ConnectQrPanel } from "@/components/whatsapp/connect/ConnectQrPanel";
import { ConnectStatusSummary } from "@/components/whatsapp/connect/ConnectStatusSummary";
import type { FormEvent } from "react";
import { Head, useForm } from "@inertiajs/react";
import type { ConnectProps } from "@/components/whatsapp/connect/types";
import {
    isRecoverableConnectionStatus,
    readText,
    statusMessage,
} from "@/components/whatsapp/connect/utils";
import { useConnectLifecycle } from "@/components/whatsapp/connect/useConnectLifecycle";

export default function Connect({ operator, flash, openwa }: ConnectProps) {
    const startForm = useForm({});
    const logoutForm = useForm({});
    const disconnectForm = useForm({});

    const sessionStatus = openwa.status || "Sin estado informado";
    const healthStatus =
        readText(openwa.health, "status") ??
        (openwa.health ? "Respondió" : "Sin respuesta");
    const hasQrCode = Boolean(openwa.qrCode?.qrCode);
    const hasSession = Boolean(openwa.session);
    const hasBlockingError = Boolean(flash?.error || (openwa.error && !isRecoverableConnectionStatus(sessionStatus)));
    const isLoadingQr = !hasQrCode && !hasBlockingError && !openwa.isReady;
    const qrStatusMessage = statusMessage({
        hasQrCode,
        hasError: hasBlockingError,
        isLoadingQr,
        openwa,
    });

    useConnectLifecycle({
        flashSuccess: flash?.success,
        flashError: flash?.error,
        openwaError: openwa.error,
        hasBlockingError,
        hasQrCode,
        isReady: openwa.isReady,
    });

    function start(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        startForm.post("/whatsapp/connect/start");
    }

    function logout(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        logoutForm.post("/logout");
    }

    function disconnect(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        disconnectForm.post("/whatsapp/disconnect");
    }

    return (
        <>
            <Head title="Escanear QR" />

            <main className="app-shell flex min-h-screen items-center justify-center px-6 py-12">
                <ThemeToggle className="absolute top-5 right-5" />
                <section className="app-surface mx-auto w-full max-w-5xl rounded-3xl border p-6 sm:p-8">
                    <div className="grid gap-8 lg:grid-cols-[minmax(0,1fr)_380px] lg:items-start">
                        <div className="flex min-h-full flex-col">
                            <ConnectStatusSummary
                                operator={operator}
                                sessionStatus={sessionStatus}
                                healthStatus={healthStatus}
                                lastCheckedAt={openwa.lastCheckedAt}
                            />

                            <div className="app-surface-soft app-muted mt-6 rounded-2xl border p-5 text-sm leading-6">
                                <p>
                                    Escaneá el código cuando aparezca. Si no aparece, apretá en "Cerrar WhatsApp" e intentá de nuevo en unos segundos.
                                </p>
                            </div>

                            {openwa.isReady && (
                                <div className="app-alert-success mt-6 rounded-2xl border p-5 text-sm leading-6">
                                    WhatsApp está conectado. El QR ya no es necesario y podés consultar conversaciones.
                                </div>
                            )}

                            <div className="mt-auto flex flex-col justify-end gap-2 pt-6 sm:flex-row sm:flex-wrap lg:justify-end">
                                {/* <form onSubmit={start}>
                                    <button
                                        type="submit"
                                        disabled={startForm.processing || !openwa.canStart}
                                        className="w-full rounded-xl bg-green-600 px-4 py-3 text-sm font-semibold text-white transition hover:bg-green-700 disabled:cursor-not-allowed disabled:opacity-60 sm:w-auto"
                                    >
                                        {startForm.processing
                                            ? "Conectando..."
                                            : "Iniciar sesión"}
                                    </button>
                                </form> */}

                                {hasSession && (
                                    <form onSubmit={disconnect}>
                                        <button
                                            type="submit"
                                            disabled={disconnectForm.processing}
                                            className="app-button-danger w-full rounded-xl border px-4 py-3 text-sm font-semibold transition disabled:cursor-not-allowed disabled:opacity-60 sm:w-auto"
                                        >
                                            Cerrar sesión
                                        </button>
                                    </form>
                                )}

                                <form onSubmit={logout}>
                                    <button
                                        type="submit"
                                        disabled={logoutForm.processing}
                                        className="app-button w-full rounded-xl border px-4 py-3 text-sm font-semibold transition disabled:cursor-not-allowed disabled:opacity-60 sm:w-auto"
                                    >
                                        Salir
                                    </button>
                                </form>
                            </div>
                        </div>

                        <ConnectQrPanel
                            qrCode={openwa.qrCode?.qrCode ?? null}
                            statusMessage={qrStatusMessage}
                            hasBlockingError={hasBlockingError}
                            isLoadingQr={isLoadingQr}
                        />
                    </div>
                </section>
            </main>
        </>
    );
}
