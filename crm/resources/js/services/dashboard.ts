import { apiGet } from '@/api';

/** `D-145`'s five tabs, in the order the screen draws them. */
export type DashboardTab = 'overview' | 'sales' | 'finance' | 'procurement' | 'outdoor';

/** `GET /dashboard/meta` — what the caller's dashboard carries; the server derives it from their permissions. */
export interface DashboardMeta {
    scope: 'all' | 'team';
    tabs: DashboardTab[];
    drill_down: boolean;
    money: boolean;
    /** The company's day (`Y-m-d`, `locale.timezone`); the period is drawn from it, never from the browser's clock. */
    today: string;
    last_updated_at: string | null;
}

export async function readDashboardMeta(): Promise<DashboardMeta> {
    return (await apiGet<DashboardMeta>('/dashboard/meta')).data;
}
