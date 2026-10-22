import { apiGet, apiPost } from '@/api';

/** Module 16 (`D-153`) — the five kinds an alert can be. */
export type AlertKind = 'quotation_expiring' | 'account_locked' | 'job_failed' | 'storage_over_limit' | 'backup_failed';

/** One open alert addressed to the caller; a repeat is one alert with its `occurrences`. */
export interface Alert {
    id: string;
    kind: AlertKind;
    subject_id: string | null;
    details: Record<string, unknown>;
    occurrences: number;
    first_raised_at: string;
    last_raised_at: string;
    read: boolean;
}

/** The bell's number, read on every page change like `GET /badges`. */
export async function readUnreadAlerts(): Promise<number> {
    return (await apiGet<{ unread: number }>('/alerts/unread-count')).data.unread;
}

/** The caller's open alerts, newest first — the first page is what the bell shows. */
export async function listAlerts(): Promise<Alert[]> {
    return (await apiGet<Alert[]>('/alerts')).data;
}

/** @returns the caller's unread count after the change */
export async function markAlertRead(id: string): Promise<number> {
    return (await apiPost<{ unread: number }>(`/alerts/${encodeURIComponent(id)}/read`)).data.unread;
}

/** @returns the caller's unread count after the change */
export async function markAllAlertsRead(): Promise<number> {
    return (await apiPost<{ unread: number }>('/alerts/read-all')).data.unread;
}
