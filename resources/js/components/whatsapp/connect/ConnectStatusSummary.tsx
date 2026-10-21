import type { Operator } from "@/components/whatsapp/connect/types";
import {
    formatDateTime,
    humanStatus,
} from "@/components/whatsapp/connect/utils";

type ConnectStatusSummaryProps = {
    operator?: Operator;
    sessionStatus: string;
    healthStatus: string;
    lastCheckedAt: string;
};

export function ConnectStatusSummary({
    operator,
    sessionStatus,
    healthStatus,
    lastCheckedAt,
}: ConnectStatusSummaryProps) {
    return (
        <dl className="app-surface-soft grid gap-4 rounded-2xl border p-5 sm:grid-cols-2">
            <Status label="Operador" value={operator?.name ?? "Operador demo"} />
            <Status label="Usuario" value={operator?.usuario ?? "testing"} />
            <Status
                label="Nro. operador"
                value={operator?.ope_datatech ?? "Nro. operador demo"}
            />
            <Status label="Estado actual" value={humanStatus(sessionStatus)} />
            <Status label="Servicio" value={healthStatus} />
            <Status label="Última consulta" value={formatDateTime(lastCheckedAt)} />
        </dl>
    );
}

function Status({ label, value }: { label: string; value: string | number }) {
    return (
        <div>
            <dt className="app-faint text-xs font-semibold uppercase tracking-wide">
                {label}
            </dt>
            <dd className="mt-1 wrap-break-word font-medium">{value}</dd>
        </div>
    );
}
