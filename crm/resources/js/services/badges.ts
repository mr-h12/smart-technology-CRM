import { apiGet } from '@/api';

/** Module 8 · 2.3's counters — Design System §5.1's *Approvals* and *My Quotations* — and Module 13 · 7.5d's *Reports*. */
export interface Badges {
    approvals: number;
    my_quotations: number;
    reports: number;
}

export async function readBadges(): Promise<Badges> {
    return (await apiGet<Badges>('/badges')).data;
}
