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

/** `GET /dashboard/overview` (`D-144`): the period's counts, and the "now" figures whatever the period. */
export interface DashboardOverview {
    customers: number;
    deals: { total: number; open: number; closed: number };
    self_approvals: number;
    now: { pending_approvals: number; returned: number };
    last_updated_at: string | null;
}

export async function readDashboardOverview(period: { from: string; to: string }): Promise<DashboardOverview> {
    return (await apiGet<DashboardOverview>(`/dashboard/overview?from=${period.from}&to=${period.to}`)).data;
}

/** `GET /dashboard/money` (`D-141`, `D-144`, `D-148`, `D-149`): exact decimal strings, never mixed across currencies. */
export interface DashboardCurrencyMoney {
    currency: string;
    won_value: string;
    cost: string;
    gross_profit: string;
    net_profit: string;
    margin_percent: string;
    delivery_cost: string;
    won_chains: number;
}

export interface DashboardMoney {
    currencies: DashboardCurrencyMoney[];
    combined: { currency: string | null; won_value: string; gross_profit: string; net_profit: string; delivery_cost: string; unconverted: number };
    monthly: { month: string; currency: string; won_value: string; gross_profit: string }[];
}

export async function readDashboardMoney(period: { from: string; to: string }): Promise<DashboardMoney> {
    return (await apiGet<DashboardMoney>(`/dashboard/money?from=${period.from}&to=${period.to}`)).data;
}
