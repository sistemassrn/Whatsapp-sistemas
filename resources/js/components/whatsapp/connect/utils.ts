import type { ConnectProps } from './types';

export function statusMessage({
    hasQrCode,
    hasError,
    isLoadingQr,
    openwa,
}: {
    hasQrCode: boolean;
    hasError: boolean;
    isLoadingQr: boolean;
    openwa: ConnectProps['openwa'];
}): string {
    if (hasError) {
        return openwa.error ?? 'No pudimos conectar. Reintentá en unos segundos.';
    }

    if (openwa.isReady) {
        return 'WhatsApp está conectado.';
    }

    if (hasQrCode) {
        return 'Escaneá el código para conectar WhatsApp.';
    }

    if (isLoadingQr) {
        return openwa.isStarted
            ? 'Preparando conexión…'
            : 'Preparando conexión…';
    }

    return 'Preparando conexión…';
}

export function isRecoverableConnectionStatus(status: string): boolean {
    return [
        'action_required',
        'authenticating',
        'created',
        'disconnected',
        'initializing',
        'qr_ready',
    ].includes(status);
}

export function humanStatus(status: string): string {
    const statuses: Record<string, string> = {
        action_required: 'Requiere acción',
        authenticating: 'Autenticando',
        created: 'Creada',
        disconnected: 'Desconectada',
        error: 'Error',
        initializing: 'Inicializando',
        not_configured: 'Sin configurar',
        qr_ready: 'QR disponible',
        ready: 'Lista',
    };

    return statuses[status] ?? status;
}

export function formatDateTime(value: string): string {
    const date = new Date(value);

    if (Number.isNaN(date.getTime())) {
        return 'No disponible';
    }

    return date.toLocaleString();
}

export function readText(
    source: Record<string, unknown> | null,
    key: string,
): string | null {
    const value = source?.[key];

    return typeof value === 'string' && value !== '' ? value : null;
}
