import { ThemeToggle } from "@/theme";
import { Head, router, useForm } from "@inertiajs/react";
import { useEffect } from "react";
import type { FormEvent } from "react";
import toast from "react-hot-toast";

type Operator = {
    name: string;
    usuario: string;
    ope_datatech: number;
};

type ConnectProps = {
    operator?: Operator;
    flash?: {
        success?: string | null;
        error?: string | null;
    };
    openwa: {
        configured: boolean;
        baseUrl: string;
        sessionName: string;
        health: Record<string, unknown> | null;
        session: Record<string, unknown> | null;
        qrCode: {
            qrCode?: string;
            status?: string;
        } | null;
        status: string;
        isReady: boolean;
        isStarted: boolean;
        canStart: boolean;
        started: boolean;
        autoStarted: boolean;
        lastCheckedAt: string;
        error?: string | null;
    };
};

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

    useEffect(() => {
        if (flash?.success) {
            toast.success(flash.success);
        }

        if (flash?.error || (openwa.error && !isRecoverableConnectionStatus(sessionStatus))) {
            toast.error(flash?.error ?? "No pudimos conectar. Reintentá en unos segundos.");
        }
    }, [flash?.error, flash?.success, openwa.error, sessionStatus]);

    useEffect(() => {
        if (!openwa.isReady) {
            return;
        }

        router.visit("/whatsapp/conversations");
    }, [openwa.isReady]);

    useEffect(() => {
        if (openwa.isReady || hasBlockingError) {
            return;
        }

        const interval = window.setInterval(() => {
            router.reload({
                only: ["openwa", "flash"],
                preserveScroll: true,
            });
        }, 3000);

        return () => window.clearInterval(interval);
    }, [hasBlockingError, openwa.isReady]);

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

            <main className="flex min-h-screen items-center justify-center bg-zinc-950 px-6 py-12 text-white">
                <ThemeToggle className="absolute top-5 right-5" />
                <section className="mx-auto w-full max-w-5xl rounded-3xl border border-white/10 bg-zinc-900 p-6 sm:p-8">
                    <div className="grid gap-8 lg:grid-cols-[minmax(0,1fr)_380px] lg:items-start">
                        <div className="flex min-h-full flex-col">
                            <dl className="grid gap-4 rounded-2xl border border-white/10 bg-zinc-900 p-5 sm:grid-cols-2">
                                <Status label="Estado actual" value={humanStatus(sessionStatus)} />
                                <Status
                                    label="Operador"
                                    value={operator?.name ?? "Operador demo"}
                                />
                                <Status
                                    label="Usuario"
                                    value={operator?.usuario ?? "testing"}
                                />
                                <Status
                                    label="Nro. operador"
                                    value={operator?.ope_datatech ?? "Nro. operador demo"}
                                />
                                <Status label="Servicio" value={healthStatus} />
                                <Status
                                    label="Última consulta"
                                    value={formatDateTime(openwa.lastCheckedAt)}
                                />
                            </dl>

                            <div className="mt-6 rounded-2xl border border-white/10 bg-zinc-900/70 p-5 text-sm leading-6 text-zinc-200">
                                <p>
                                    Escaneá el código cuando aparezca. Si no aparece, apretá en "Cerrar WhatsApp" e intentá de nuevo en unos segundos.
                                </p>
                            </div>

                            {openwa.isReady && (
                                <div className="mt-6 rounded-2xl border border-green-500/30 bg-green-950/40 p-5 text-sm leading-6 text-green-200">
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
                                            className="w-full rounded-xl border border-red-500 px-4 py-3 text-sm font-semibold text-red-300 transition hover:border-red-600 hover:text-red-400 disabled:cursor-not-allowed disabled:opacity-60 sm:w-auto"
                                        >
                                            Cerrar sesión
                                        </button>
                                    </form>
                                )}

                                <form onSubmit={logout}>
                                    <button
                                        type="submit"
                                        disabled={logoutForm.processing}
                                        className="w-full rounded-xl border border-zinc-700 px-4 py-3 text-sm font-semibold text-white transition hover:border-white disabled:cursor-not-allowed disabled:opacity-60 sm:w-auto"
                                    >
                                        Salir
                                    </button>
                                </form>
                            </div>
                        </div>

                        <aside className="flex min-h-full flex-col rounded-2xl border border-white/10 bg-zinc-900 p-5 lg:sticky lg:top-6">
                             <div>
                                <p className="text-sm font-medium text-zinc-200">{qrStatusMessage}</p>
                                <div className="mt-4 flex aspect-square w-full items-center justify-center rounded-xl border border-white/10 bg-white p-4">
                                {hasQrCode ? (
                                    <img
                                        src={openwa.qrCode?.qrCode}
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
                    </div>
                </section>
            </main>
        </>
    );
}

function Status({ label, value }: { label: string; value: string | number; }) {
    return (
        <div>
            <dt className="text-xs font-semibold uppercase tracking-wide text-zinc-400">
                {label}
            </dt>
            <dd className="mt-1 wrap-break-word font-medium text-white">
                {value}
            </dd>
        </div>
    );
}

function statusMessage({
    hasQrCode,
    hasError,
    isLoadingQr,
    openwa,
}: {
    hasQrCode: boolean;
    hasError: boolean;
    isLoadingQr: boolean;
    openwa: ConnectProps["openwa"];
}): string {
    if (hasError) {
        return openwa.error ?? "No pudimos conectar. Reintentá en unos segundos.";
    }

    if (openwa.isReady) {
        return "WhatsApp está conectado.";
    }

    if (hasQrCode) {
        return "Escaneá el código para conectar WhatsApp.";
    }

    if (isLoadingQr) {
        return openwa.isStarted
            ? "Preparando conexión…"
            : "Preparando conexión…";
    }

    return "Preparando conexión…";
}

function isRecoverableConnectionStatus(status: string): boolean {
    return [
        "action_required",
        "authenticating",
        "created",
        "disconnected",
        "initializing",
        "qr_ready",
    ].includes(status);
}

function humanStatus(status: string): string {
    const statuses: Record<string, string> = {
        action_required: "Requiere acción",
        authenticating: "Autenticando",
        created: "Creada",
        disconnected: "Desconectada",
        error: "Error",
        initializing: "Inicializando",
        not_configured: "Sin configurar",
        qr_ready: "QR disponible",
        ready: "Lista",
    };

    return statuses[status] ?? status;
}

function formatDateTime(value: string): string {
    const date = new Date(value);

    if (Number.isNaN(date.getTime())) {
        return "No disponible";
    }

    return date.toLocaleString();
}

function readText(
    source: Record<string, unknown> | null,
    key: string,
): string | null {
    const value = source?.[key];

    return typeof value === "string" && value !== "" ? value : null;
}
