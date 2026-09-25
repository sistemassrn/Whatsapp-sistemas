import { Head, router, useForm } from "@inertiajs/react";
import { useEffect } from "react";
import type { FormEvent } from "react";

type Operator = {
    name: string;
    usuario: string;
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
        error?: string | null;
    };
};

export default function Connect({ operator, flash, openwa }: ConnectProps) {
    const startForm = useForm({});
    const logoutForm = useForm({});
    const disconnectForm = useForm({});

    const sessionId =
        readText(openwa.session, "id") ??
        readText(openwa.session, "_id") ??
        readText(openwa.session, "sessionId");
    const sessionStatus =
        readText(openwa.session, "status") ?? "Sin estado informado";
    const healthStatus =
        readText(openwa.health, "status") ??
        (openwa.health ? "Respondió" : "Sin respuesta");
    const hasQrCode = Boolean(openwa.qrCode?.qrCode);
    const hasSession = Boolean(openwa.session);

    useEffect(() => {
        if (hasQrCode || sessionStatus === "ready") {
            return;
        }

        const interval = window.setInterval(() => {
            router.reload({
                only: ["openwa", "flash"],
                preserveScroll: true,
            });
        }, 3000);

        return () => window.clearInterval(interval);
    }, [hasQrCode, sessionStatus]);

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
            <Head title="Conectar WhatsApp" />

            <main className="min-h-screen bg-gray-50 px-6 py-12 text-black">
                <section className="mx-auto w-full max-w-6xl rounded-3xl border border-black/10 bg-white p-8">
                    <div className="flex flex-col gap-6 lg:flex-row lg:items-start lg:justify-between">
                        <div>
                            <h1 className="text-3xl font-semibold tracking-tight text-black">
                                Conectar WhatsApp con OpenWA
                            </h1>
                        </div>

                        <div className="flex shrink-0 gap-2">
                            <form onSubmit={start}>
                                <button
                                    type="submit"
                                    disabled={startForm.processing}
                                    className="w-full rounded-xl bg-green-600 px-4 py-3 text-sm font-semibold text-white transition hover:bg-green-700 disabled:cursor-not-allowed disabled:opacity-60 sm:w-auto lg:w-48"
                                >
                                    {startForm.processing
                                        ? "Conectando..."
                                        : "Iniciar sesión"}
                                </button>
                            </form>

                            {hasSession && (
                                <form onSubmit={disconnect}>
                                    <button
                                        type="submit"
                                        disabled={disconnectForm.processing}
                                        className="w-full rounded-xl border border-gray-300 px-4 py-3 text-sm font-semibold text-black transition hover:border-red-600 hover:text-red-700 disabled:cursor-not-allowed disabled:opacity-60 sm:w-auto lg:w-48"
                                    >
                                        Cerrar WhatsApp
                                    </button>
                                </form>
                            )}

                            <form onSubmit={logout}>
                                <button
                                    type="submit"
                                    disabled={logoutForm.processing}
                                    className="w-full rounded-xl border border-gray-300 px-4 py-3 text-sm font-semibold text-black transition hover:border-black disabled:cursor-not-allowed disabled:opacity-60 sm:w-auto lg:w-32"
                                >
                                    Salir
                                </button>
                            </form>
                        </div>
                    </div>

                    {(flash?.success || flash?.error || openwa.error) && (
                        <div className="mt-6 space-y-3">
                            {flash?.success && (
                                <Notice tone="success" message={flash.success} />
                            )}
                            {(flash?.error || openwa.error) && (
                                <Notice
                                    tone="error"
                                    message={flash?.error ?? openwa.error ?? ""}
                                />
                            )}
                        </div>
                    )}

                    <div className="mt-8 grid gap-6 lg:grid-cols-[minmax(0,1fr)_360px]">
                        <div>
                            <dl className="grid gap-4 rounded-2xl bg-gray-100 p-5 sm:grid-cols-2">
                                <div>
                                    <dt className="text-xs font-semibold uppercase tracking-wide text-gray-500">
                                        Operador
                                    </dt>
                                    <dd className="mt-1 font-medium text-black">
                                        {operator?.name ?? "Operador Demo"}
                                    </dd>
                                </div>

                                <div>
                                    <dt className="text-xs font-semibold uppercase tracking-wide text-gray-500">
                                        Usuario
                                    </dt>
                                    <dd className="mt-1 font-medium text-black">
                                        {operator?.usuario ?? "testing"}
                                    </dd>
                                </div>
                            </dl>

                            <div className="mt-6 grid gap-4 rounded-2xl border border-gray-200 p-5 sm:grid-cols-2">
                                <Status
                                    label="Configuración"
                                    value={
                                        openwa.configured
                                            ? "Configurado"
                                            : "Falta OPENWA_API_KEY"
                                    }
                                />
                                <Status
                                    label="OpenWA health"
                                    value={healthStatus}
                                />
                                <Status label="URL base" value={openwa.baseUrl} />
                                <Status
                                    label="Sesión configurada"
                                    value={openwa.sessionName}
                                />
                                <Status
                                    label="Sesión encontrada"
                                    value={openwa.session ? "Sí" : "No"}
                                />
                                <Status
                                    label="ID de sesión"
                                    value={sessionId ?? "No disponible"}
                                />
                                <Status
                                    label="Estado de sesión"
                                    value={sessionStatus}
                                />
                                <Status
                                    label="Estado de QR"
                                    value={openwa.qrCode?.status ?? "No disponible"}
                                />
                            </div>

                            {sessionStatus === "ready" && (
                                <div className="mt-6 rounded-2xl border border-green-200 bg-green-50 p-5 text-sm leading-6 text-green-800">
                                    WhatsApp está conectado. El QR ya no es necesario y podés consultar conversaciones.
                                </div>
                            )}
                        </div>

                        <aside className="rounded-2xl bg-gray-100 p-5 lg:sticky lg:top-6">
                            <h2 className="text-lg font-semibold text-black">Código QR</h2>
                            <p className="mt-2 text-sm leading-6 text-gray-600">
                                Escanealo desde WhatsApp para vincular esta sesión compartida.
                            </p>

                            {hasQrCode ? (
                                <img
                                    src={openwa.qrCode?.qrCode}
                                    alt="QR para vincular WhatsApp"
                                    className="mt-2 h-auto w-full rounded-xl border border-gray-200 bg-white p-3"
                                />
                            ) : (
                                <p className="mt-4 rounded-xl border border-gray-200 bg-white p-4 text-sm leading-6 text-gray-600">
                                    El QR aparece cuando OpenWA lo informa disponible. Esta pantalla lo consulta automáticamente cada 3 segundos.
                                </p>
                            )}
                        </aside>
                    </div>
                </section>
            </main>
        </>
    );
}

function Status({ label, value }: { label: string; value: string }) {
    return (
        <div>
            <dt className="text-xs font-semibold uppercase tracking-wide text-gray-500">
                {label}
            </dt>
            <dd className="mt-1 wrap-break-word font-medium text-black">
                {value}
            </dd>
        </div>
    );
}

function Notice({
    tone,
    message,
}: {
    tone: "success" | "error";
    message: string;
}) {
    const classes =
        tone === "success"
            ? "border-green-200 bg-green-50 text-green-800"
            : "border-red-200 bg-red-50 text-red-700";

    return (
        <p
            className={`rounded-xl border px-4 py-3 text-sm font-medium ${classes}`}
        >
            {message}
        </p>
    );
}

function readText(
    source: Record<string, unknown> | null,
    key: string,
): string | null {
    const value = source?.[key];

    return typeof value === "string" && value !== "" ? value : null;
}
