/**
 * The SPA's route table and its guards — §8, §3.1, `SEC-09`, §3.12 rule 1.
 *
 * ── A guard is presentation. It is never the enforcement ───────────────────
 *
 * §3.12 rule 1: "Enforcement happens at the API — hiding a button is not the
 * same as blocking an action." Everything here decides which screen renders.
 * A person who edits the route table in a debugger reaches a component that
 * immediately asks `/api/v1` and is refused there, which is where the answer
 * has always been. The guards exist so that the *normal* path does not show
 * someone a screen full of empty error states they were never meant to open.
 */
import { createRouter, createWebHistory } from 'vue-router';
import type { RouteRecordRaw, Router, RouteLocationNormalized, RouteLocationRaw } from 'vue-router';
import { useAuth } from '@/stores/auth';
import Ping from '@/pages/Ping.vue';
import LoginView from '@/pages/auth/LoginView.vue';
import ForbiddenView from '@/pages/ForbiddenView.vue';
import UsersView from '@/pages/users/UsersView.vue';
import RolesMatrixView from '@/pages/roles/RolesMatrixView.vue';
import DealsView from '@/pages/deals/DealsView.vue';
import SupplierQuotationsView from '@/pages/supplier-quotations/SupplierQuotationsView.vue';
import SystemSettingsView from '@/pages/settings/SystemSettingsView.vue';
import AccountSecurityView from '@/pages/profile/AccountSecurityView.vue';
import ManagedListsView from '@/pages/lists/ManagedListsView.vue';
import CustomersView from '@/pages/customers/CustomersView.vue';
import CustomerDetailView from '@/pages/customers/CustomerDetailView.vue';
import SuppliersView from '@/pages/suppliers/SuppliersView.vue';
import CatalogView from '@/pages/catalog/CatalogView.vue';

declare module 'vue-router' {
    interface RouteMeta {
        /** Key into the lang files; the context bar reads it (§5.1). */
        titleKey?: string;
        /** A session is required to render this screen. */
        requiresAuth?: boolean;
        /** Only reachable while signed out — the login screen and nothing else yet. */
        guestOnly?: boolean;
        /** `resource.action` or a full `resource.action.scope` triple (§3.2). */
        requiredPermission?: string;
        /** Renders without the sidebar and context bar. */
        bare?: boolean;
    }
}

/**
 * §8's **first** screen for each role, by slug.
 *
 * ⚠️ **Read from §8, which disagrees with the point's brief.** The brief
 * proposed `/deals` for Manager and Team Leader and `/requests` for both Sales
 * roles; §8 opens the Manager and Team Leader lists with *Dashboard*, Outdoor
 * Sales with *Today's Visits*, and Indoor Sales with *Customers*. `CLAUDE.md`
 * puts the master documentation above the brief, so §8 is what this map
 * transcribes — and `RoleLandingTest` reads §8 back out of the documentation
 * rather than trusting this list. The disagreement is recorded as an owner
 * question in `CHECKLIST.md`, not resolved by silently preferring one.
 *
 * **None of these routes exists yet.** Dashboard is Module 14, Customers is
 * Module 3, Deals is Module 5, Visits is Module 12, and §13's administration is
 * later still. {@see landingRouteFor} therefore resolves an unregistered target
 * to {@see FALLBACK_ROUTE}: a redirect to a route the table does not contain is
 * a blank screen, and `navigation.ts` already names that defect — "a dead link
 * is not a permission problem, it is a lie". The map is written now so that
 * each module lands by registering its route, not by editing this file.
 */
export const LANDING_ROUTE: Readonly<Record<string, string>> = {
    super_admin: 'admin',            // §8: "22 administrative screens (section 13)"
    ceo: 'dashboard',                // §8: Dashboard · Customers · Quotations · Reports
    manager: 'dashboard',            // §8: Dashboard · Employees · Customers · …
    team_leader: 'dashboard',        // §8: Dashboard · Sales Team · Customers · …
    outdoor_supervisor: 'visits-today', // §8: Today's Visits · Visit History · …
    outdoor_sales: 'visits-today',   // §8: Today's Visits · Visit History · Customers · …
    indoor_sales: 'customers',       // §8: Customers · Deals · Quotations · …
    procurement: 'assigned-deals',   // §8: Assigned Deals · Negotiation Log · …
};

/** Where a signed-in person goes when §8's screen has not been built yet. */
export const FALLBACK_ROUTE = 'home';

export const routes: RouteRecordRaw[] = [
    {
        path: '/login',
        name: 'login',
        component: LoginView,
        // No `requiresAuth`. §9 Flow 0 has no sign-up and no public screen
        // besides this one (`SEC-01`).
        meta: { guestOnly: true, bare: true, titleKey: 'auth.login.title' },
    },
    {
        path: '/',
        name: 'home',
        component: Ping,
        meta: { requiresAuth: true, titleKey: 'nav.item.home' },
    },
    {
        // §8's *Customers* screen — Manager · Team Leader · Outdoor Supervisor ·
        // Outdoor Sales · Indoor Sales · CEO. `customer.view` and no scope: the
        // scope is answered per row by `CustomerRowScope`, and a guard naming
        // one would refuse five of the six roles the section lists.
        path: '/customers',
        name: 'customers',
        component: CustomersView,
        meta: { requiresAuth: true, requiredPermission: 'customer.view', titleKey: 'customers.title' },
    },
    {
        // Design System §5.2's Detail view for one customer. The same
        // `customer.view` as the list and the same reason for naming no scope:
        // whether *this* row is reachable is answered per row by
        // `CustomerRowScope`, and the endpoint says so with a 404 that does not
        // reveal which case applied (`OpenAPI §5.1`).
        //
        // No sidebar item: §8 lists *Customers*, not a record. This is reached
        // from the table, so `navigation.ts` has nothing to add.
        path: '/customers/:id',
        name: 'customer-detail',
        component: CustomerDetailView,
        meta: { requiresAuth: true, requiredPermission: 'customer.view', titleKey: 'customers.detail.title' },
    },
    {
        // §8's *Suppliers* screen — Module 4 Point 4.1. `catalog.view`, because
        // §3.7 is one permission pair covering the catalog **and** its
        // suppliers: there is no `supplier.*` resource in the matrix. No scope,
        // because every grant in §3.7 is `Scope::All`.
        //
        // ⚠️ §8 gives the CEO no Suppliers screen while §3.7 grants them
        // `catalog.view`. Owner's ruling, 2026-08-31: the route and the sidebar
        // both follow §3.7, recorded in `CHECKLIST.md` awaiting a `D-xx`.
        path: '/suppliers',
        name: 'suppliers',
        component: SuppliersView,
        meta: { requiresAuth: true, requiredPermission: 'catalog.view', titleKey: 'suppliers.title' },
    },
    {
        // §8's *Supplier Quotations* screen — Module 6 Point 6.2.
        // `supplier_quotation.view`, §3.6's own read row, which is also the
        // permission the list endpoint carries: a guard naming anything else
        // would send people to a screen whose first request 403s.
        //
        // ⚠️ §8 lists this screen for the Manager, Team Leader, Outdoor Sales,
        // Indoor Sales and Procurement, and **not** for the CEO — while §3.6
        // grants the CEO `supplier_quotation.view` as `All`. Same conflict the
        // Suppliers and Catalog routes above hit, same answer: the owner's
        // ruling of 2026-08-31 keys the route and the sidebar on the matrix.
        // Recorded in `CHECKLIST.md` awaiting a `D-xx`.
        //
        // No scope: §3.6 is "a shared screen — not restricted by ownership".
        path: '/supplier-quotations',
        name: 'supplier-quotations',
        component: SupplierQuotationsView,
        meta: { requiresAuth: true, requiredPermission: 'supplier_quotation.view', titleKey: 'supplierQuotations.title' },
    },
    {
        // §8's *Requests / Deals* screen — Module 5's own, and the module's
        // first (Point 6.2). §8 lists it for the Manager, Team Leader, Outdoor
        // Supervisor, Indoor Sales and Procurement, and **not** for the CEO or
        // Outdoor Sales — while §3.4 grants the CEO `deal.view` as `All` and
        // Outdoor Sales as `Own`. Same conflict the three routes around it hit,
        // same answer: the owner's ruling of 2026-08-31 keys the route and the
        // sidebar on the matrix, so no screen a person may open is unreachable.
        // Recorded in `CHECKLIST.md` awaiting a `D-xx`.
        //
        // ⚠️ Unlike §3.6's shared screen, §3.4 **is** scoped — and `Team`,
        // `Out` and `Asgn` resolve to no rows at all (Point 2.1), so three of
        // the roles admitted here are answered with an empty page rather than a
        // refusal. The screen says "none are visible to you" for that reason.
        path: '/deals',
        name: 'deals',
        component: DealsView,
        meta: { requiresAuth: true, requiredPermission: 'deal.view', titleKey: 'deals.title' },
    },
    {
        // §8's *Catalog* screen — §7.3's two tabs, on the same `catalog.view`
        // the Suppliers route carries: §3.7 is one row pair covering the
        // catalog and its suppliers, and there is no `catalog_item.*` resource
        // to name. §8 lists a Catalog screen for six roles and none for the
        // CEO; the owner's ruling of 2026-08-31 keys both the route and the
        // sidebar on §3.7 instead. Recorded in `CHECKLIST.md` awaiting a `D-xx`.
        path: '/catalog',
        name: 'catalog',
        component: CatalogView,
        meta: { requiresAuth: true, requiredPermission: 'catalog.view', titleKey: 'catalog.title' },
    },
    {
        // §8's *Employees* screen. `admin.create_user` and not a `user.view.*`
        // that does not exist — `D-78` mapped the six user endpoints onto
        // §3.11's two documented rows, and the screen has to name the same one
        // the API does or the guard and the endpoint would disagree.
        path: '/users',
        name: 'users',
        component: UsersView,
        meta: { requiresAuth: true, requiredPermission: 'admin.create_user', titleKey: 'users.title' },
    },
    {
        // §13 screen 3 — *Roles & Permissions (RBAC)*. `admin.manage_roles` is
        // §3.11's own row for "create / edit role · permissions", held by the
        // Super Admin alone, and it is the permission all four endpoints behind
        // this screen already name. A guard that named anything else would send
        // people to a screen whose every request 403s.
        path: '/roles',
        name: 'roles',
        component: RolesMatrixView,
        meta: { requiresAuth: true, requiredPermission: 'admin.manage_roles', titleKey: 'roles.title' },
    },
    {
        // `SEC-04` and `SEC-05`, for the person signed in. **No
        // `requiredPermission`, and that is read from §3.11 rather than
        // omitted**: the section has no row for changing your own password or
        // listing your own devices, and every endpoint behind this screen
        // takes the account from the bearer token with no `user_id` to widen.
        // Naming an `admin.*` ability here would hide a screen every employee
        // must reach; inventing a `user.*` one would invent a permission the
        // seeded matrix does not contain.
        path: '/account/security',
        name: 'account-security',
        component: AccountSecurityView,
        meta: { requiresAuth: true, titleKey: 'account.title' },
    },
    {
        // *System Settings* — **the one settings page** since S-02, carrying
        // §13's screens 4, 5 and 6 as sections of it (owner decision
        // 2026-08-29, recorded as a pending `D-xx`).
        //
        // ⚠️ **`admin.fx_rates`, and the reason is §3.11 rather than this
        // screen's own name.** The page's sections carry three different rows:
        // *system settings* and *system limits* are the Super Admin's, and
        // **FX rates is the Manager's too**. A route carries one permission, so
        // it carries the **widest** — every seeded holder of the other two also
        // holds this one. Guarding with `admin.system_settings`, which is what
        // this route said until S-02, would lock the Manager out of a row
        // §3.11 grants them. Each section is drawn by its own permission inside
        // the component: `SEC-09`'s visual complement, never the check.
        path: '/settings',
        name: 'settings',
        component: SystemSettingsView,
        meta: { requiresAuth: true, requiredPermission: 'admin.fx_rates', titleKey: 'settings.title' },
    },
    {
        // `DB-05`'s four managed lists — **the screen §13 does not name**, and a
        // route of its own rather than a fourth section of `/settings` (owner
        // decision 2026-08-28, reconfirmed 2026-08-29 after S-02; recorded as a
        // pending `D-xx` in `CHECKLIST.md`).
        //
        // ⚠️ **No `requiredPermission`, and it is read from §3.11 rather than
        // forgotten.** The section has no row for managed lists at all, and
        // `GET /managed-lists/{list}` is guarded by authentication alone for
        // the reason `routes/api.php` sets out: §8 puts Customers on six roles'
        // screens and Catalog on five, and not one of them renders without a
        // sector or a unit. Naming `admin.system_settings` here — the write's
        // permission — would hide the list from every role that has to read it.
        // The add form inside the component is drawn by that permission;
        // `SEC-09`'s visual complement, never the check.
        path: '/managed-lists',
        name: 'managed-lists',
        component: ManagedListsView,
        meta: { requiresAuth: true, titleKey: 'lists.title' },
    },
    {
        path: '/403',
        name: 'forbidden',
        component: ForbiddenView,
        meta: { requiresAuth: true, titleKey: 'state.denied.title' },
    },
    {
        // An unknown path is sent to the landing chain rather than rendering
        // nothing. ⚠️ This is **not** a 404 screen — the SPA has no page for
        // "this record does not exist" yet, and building one belongs with the
        // shell rather than with authentication. Recorded as a gap.
        path: '/:pathMatch(.*)*',
        name: 'not-found',
        // A path and not `{ name: FALLBACK_ROUTE }`: redirecting by name carries
        // the matched `pathMatch` param along, and vue-router logs "Discarded
        // invalid param(s)" because the target route declares none. Measured —
        // the warning appeared on the first run of `guards.spec.ts`.
        redirect: '/',
    },
];

/**
 * §8's screen for a role, narrowed to what the router can actually reach.
 *
 * Pure, and takes the registration test as an argument, so the mapping can be
 * checked without standing up a router.
 */
export function landingRouteFor(role: string | null, isRegistered: (name: string) => boolean): string {
    if (role === null) {
        return FALLBACK_ROUTE;
    }

    const target = LANDING_ROUTE[role];

    if (target === undefined || !isRegistered(target)) {
        return FALLBACK_ROUTE;
    }

    return target;
}

/**
 * The `?redirect=` a guard leaves behind, validated before it is obeyed.
 *
 * Only a same-origin absolute path is accepted. `//evil.test` is a
 * protocol-relative URL that a browser resolves to another host, so the second
 * character is checked as well as the first — this is the open-redirect that
 * every "return to where you were" feature ships with by default.
 */
export function safeRedirect(value: unknown): string | null {
    if (typeof value !== 'string' || !value.startsWith('/') || value.startsWith('//')) {
        return null;
    }

    return value;
}

/**
 * The single `beforeEach`, written as a pure function of the destination.
 *
 * One guard rather than three, because the order between them is the behaviour:
 * an unauthenticated caller must be sent to the login screen *before* anything
 * asks which permission the route wants, or the answer leaks whether the route
 * is permission-protected at all.
 */
export async function resolveNavigation(
    to: RouteLocationNormalized,
    isRegistered: (name: string) => boolean,
): Promise<RouteLocationRaw | true> {
    const auth = useAuth();

    // A page load with a token in storage but no profile in memory. The profile
    // has to be fetched before the guard can answer, or every reload of a
    // protected screen bounces to the login page and back.
    if (auth.state.token !== null && auth.user.value === null) {
        await auth.fetchCurrentUser();
    }

    const authenticated = auth.isAuthenticated.value && auth.user.value !== null;

    if (to.meta.guestOnly === true && authenticated) {
        return { name: landingRouteFor(auth.role.value, isRegistered) };
    }

    if (to.meta.requiresAuth === true && !authenticated) {
        return { name: 'login', query: { redirect: to.fullPath } };
    }

    const required = to.meta.requiredPermission;

    if (required !== undefined && authenticated && !auth.hasPermission(required)) {
        // `SEC-09`: a visual complement to the API's refusal, which has not
        // happened yet because the screen never opened. Replacing rather than
        // pushing, so Back does not return to the route that was just refused.
        return { name: 'forbidden', replace: true };
    }

    return true;
}

export function createAppRouter(): Router {
    // History mode, not hash: nginx already sends every unmatched path to
    // Laravel, which returns the SPA shell, so deep links work without a
    // fragment.
    const router = createRouter({ history: createWebHistory(), routes });

    router.beforeEach((to) => resolveNavigation(to, (name) => router.hasRoute(name)));

    return router;
}
