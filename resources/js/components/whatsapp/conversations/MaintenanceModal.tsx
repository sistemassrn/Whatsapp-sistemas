import { X } from 'lucide-react';
import { type FormEvent, useRef } from 'react';
import { useCloseOnOutsidePointer } from './hooks/useCloseOnOutsidePointer';
import { useEscapeClose } from './hooks/useEscapeClose';
import type { MaintenanceInfo } from './types';

export function MaintenanceModal({
    maintenance,
    isRunning,
    isBusy,
    syncing,
    disconnecting,
    loggingOut,
    onClose,
    onSync,
    onDisconnect,
    onLogout,
}: {
    maintenance: MaintenanceInfo;
    isRunning: boolean;
    isBusy: boolean;
    syncing: boolean;
    disconnecting: boolean;
    loggingOut: boolean;
    onClose: () => void;
    onSync: (event: FormEvent<HTMLFormElement>) => void;
    onDisconnect: (event: FormEvent<HTMLFormElement>) => void;
    onLogout: (event: FormEvent<HTMLFormElement>) => void;
}) {
    const modalRef = useRef<HTMLDivElement>(null);

    useCloseOnOutsidePointer(modalRef, true, onClose);
    useEscapeClose(onClose);

    return (
        <div
            className="fixed inset-0 z-40 flex items-center justify-center bg-black/45 p-4"
            role="presentation"
        >
            <section
                ref={modalRef}
                className="app-surface w-full max-w-lg rounded-3xl border p-5 shadow-2xl shadow-black/30"
                role="dialog"
                aria-modal="true"
                aria-labelledby="maintenance-title"
            >
                <header className="flex items-start justify-between gap-4">
                    <div>
                        <h2
                            id="maintenance-title"
                            className="text-lg font-semibold"
                        >
                            Sincronización
                        </h2>
                    </div>
                    <button
                        type="button"
                        onClick={onClose}
                        className="app-button-secondary grid size-9 place-items-center rounded-full transition"
                        aria-label="Cerrar sincronización"
                    >
                        <X className="size-4" />
                    </button>
                </header>

                <div className="mt-5 space-y-2 rounded-2xl border border-(--app-border) bg-(--app-surface-soft) p-4 text-sm leading-6">
                    <p
                        className={
                            isRunning
                                ? 'font-medium text-green-600 dark:text-green-300'
                                : 'font-medium'
                        }
                    >
                        {maintenanceStatusDescription(maintenance)}
                    </p>
                    <p>{maintenance.scheduler}</p>
                    <p>Tipo: {maintenanceReasonLabel(maintenance.reason)}</p>
                    {/* <p>{maintenance.requested_at ? `Solicitado el ${formatMaintenanceDateTime(maintenance.requested_at)}` : 'Todavía no hay fecha de solicitud registrada.'}</p> */}
                    <p>
                        {maintenance.started_at
                            ? `Comenzó el ${formatMaintenanceDateTime(maintenance.started_at)}`
                            : 'Todavía no hay fecha de inicio registrada.'}
                    </p>
                    <p>
                        {maintenance.finished_at
                            ? `Finalizó el ${formatMaintenanceDateTime(maintenance.finished_at)}`
                            : 'Todavía no hay fecha de finalización registrada.'}
                    </p>
                    <p>
                        {maintenance.duration_seconds !== null
                            ? `Duración: ${formatDuration(maintenance.duration_seconds)}`
                            : 'Duración pendiente.'}
                    </p>
                    {/* <p className="app-muted">La recuperación de mutimedia cubre las últimas {maintenance.retry_media_window_hours} h y respeta un cooldown de {maintenance.retry_media_cooldown_hours} h por mensaje.</p> */}
                </div>

                {maintenance.error ? (
                    <div className="mt-4 rounded-2xl border border-red-500/30 bg-red-500/10 p-4 text-sm text-red-700 dark:text-red-200">
                        <p className="font-semibold">Último error</p>
                        <p className="mt-1 max-h-28 overflow-auto leading-5 whitespace-pre-wrap">
                            {maintenance.error}
                        </p>
                    </div>
                ) : null}

                <div className="mt-5 grid gap-2 sm:grid-cols-3">
                    <form onSubmit={onSync}>
                        <button
                            type="submit"
                            disabled={syncing || isBusy}
                            className="app-button-primary w-full rounded-xl px-4 py-3 text-sm font-semibold transition disabled:cursor-not-allowed disabled:opacity-60"
                        >
                            {maintenance.status === 'queued'
                                ? 'Esperando...'
                                : isRunning
                                  ? 'Sincronizando...'
                                  : 'Sincronizar'}
                        </button>
                    </form>
                    <form onSubmit={onDisconnect}>
                        <button
                            type="submit"
                            disabled={disconnecting}
                            className="app-button w-full rounded-xl border px-4 py-3 text-sm font-semibold transition disabled:cursor-not-allowed disabled:opacity-60"
                        >
                            Cambiar cuenta
                        </button>
                    </form>
                    <form onSubmit={onLogout}>
                        <button
                            type="submit"
                            disabled={loggingOut}
                            className="app-button-danger w-full rounded-xl border px-4 py-3 text-sm font-semibold transition disabled:cursor-not-allowed disabled:opacity-60"
                        >
                            Cerrar sesión
                        </button>
                    </form>
                </div>
            </section>
        </div>
    );
}

function maintenanceStatusDescription(maintenance: MaintenanceInfo): string {
    if (maintenance.status === 'queued') {
        return maintenance.requested_at
            ? `La sincronización fue solicitada el ${formatMaintenanceDateTime(maintenance.requested_at)} y está esperando ejecución.`
            : 'La sincronización fue solicitada y está esperando ejecución.';
    }

    if (maintenance.status === 'running') {
        if (
            maintenance.started_at &&
            maintenance.duration_seconds !== null &&
            maintenance.duration_seconds >=
                maintenance.sync_maintenance_stale_minutes * 60
        ) {
            return 'La sincronización parece demorada. Si se interrumpió, el sistema la marcará como fallida para permitir un nuevo intento.';
        }

        return maintenance.started_at
            ? `La sincronización empezó el ${formatMaintenanceDateTime(maintenance.started_at)} y sigue en curso.`
            : 'La sincronización está en curso.';
    }

    if (maintenance.status === 'success') {
        return 'La última sincronización finalizó correctamente.';
    }

    if (maintenance.status === 'failed') {
        return maintenance.error
            ? 'La última sincronización falló o fue interrumpida.'
            : 'La última sincronización falló.';
    }

    return 'Todavía no hay sincronizaciones registradas.';
}

function maintenanceReasonLabel(reason: string | null): string {
    if (reason === 'manual') {
        return 'Manual';
    }

    if (reason === 'scheduled') {
        return 'Programado';
    }

    if (reason === 'openwa_recovered') {
        return 'Recuperación OpenWA';
    }

    return 'Sin registro';
}

function formatDuration(seconds: number): string {
    if (seconds < 60) {
        return `${seconds} s`;
    }

    const minutes = Math.floor(seconds / 60);
    const remainingSeconds = seconds % 60;

    if (remainingSeconds === 0) {
        return `${minutes} min`;
    }

    return `${minutes} min ${remainingSeconds} s`;
}

function formatMaintenanceDateTime(value: string): string {
    const date = new Date(value);

    if (Number.isNaN(date.getTime())) {
        return value;
    }

    const formattedDate = formatDate(value, {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
    });

    const formattedTime = formatTime(value, true);

    return `${formattedDate}, a las ${formattedTime}`;
}

function formatDate(
    value: string,
    options: Intl.DateTimeFormatOptions,
): string {
    const date = new Date(value);

    if (Number.isNaN(date.getTime())) {
        return value;
    }

    return date.toLocaleDateString('es-AR', options);
}

function formatTime(value: string, includeSeconds = false): string {
    if (!value) {
        return '';
    }

    const date = new Date(value);

    if (Number.isNaN(date.getTime())) {
        return value;
    }

    return date.toLocaleTimeString('es-AR', {
        hour: 'numeric',
        minute: '2-digit',
        second: includeSeconds ? '2-digit' : undefined,
        hour12: true,
    });
}
