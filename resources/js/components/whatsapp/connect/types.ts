export type Operator = {
    name: string;
    usuario: string;
    ope_datatech: number;
};

export type ConnectProps = {
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
