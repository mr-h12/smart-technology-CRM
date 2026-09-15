import { apiGet } from '@/api';

/** Module 8 · 2.3's two counters — Design System §5.1's *Approvals* and *My Quotations*. */
export interface Badges {
    approvals: number;
    my_quotations: number;
}

export async function readBadges(): Promise<Badges> {
    return (await apiGet<Badges>('/badges')).data;
}
