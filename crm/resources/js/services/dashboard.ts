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

/** `GET /dashboard/sales` (`D-144`, `D-150`): `average_deal_size` only with money, `workload` only with drill-down. */
export interface DashboardSales {
    funnel: { status: string; deals: number }[];
    conversion: { created: number; reached_won: number; rate_percent: string | null };
    win_loss: { won: number; lost: number; win_rate_percent: string | null };
    average_sales_cycle_days: string | null;
    average_deal_size?: { currency: string; value: string }[];
    workload?: { owner_id: string; name: string; open_deals: number }[];
}

export async function readDashboardSales(period: { from: string; to: string }): Promise<DashboardSales> {
    return (await apiGet<DashboardSales>(`/dashboard/sales?from=${period.from}&to=${period.to}`)).data;
}

/** `GET /dashboard/breakdowns` (`D-144`, `D-145`, `D-147`, `D-150`): each part present only for whom it is shown. */
export interface DashboardMoneyLine {
    currency: string;
    won_value: string;
}

export interface DashboardRanked {
    name: string;
    base_value: string | null;
    money: DashboardMoneyLine[];
}

export interface DashboardBreakdowns {
    base_currency: string | null;
    lost_by_reason: { code: string; deals: number }[];
    by_employee?: { owner_id: string; name: string; won_deals: number; money?: DashboardMoneyLine[] }[];
    by_sector?: { sector: string | null; won_chains: number; money: DashboardMoneyLine[] }[];
    top_customers?: (DashboardRanked & { customer_id: string })[];
    products?: { best: (DashboardRanked & { catalog_item_id: string })[]; worst: (DashboardRanked & { catalog_item_id: string })[] };
}

export async function readDashboardBreakdowns(period: { from: string; to: string }): Promise<DashboardBreakdowns> {
    return (await apiGet<DashboardBreakdowns>(`/dashboard/breakdowns?from=${period.from}&to=${period.to}`)).data;
}
