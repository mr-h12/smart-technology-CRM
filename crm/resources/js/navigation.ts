/**
 * Design System §5.1: navigation is grouped by business module, and "do not
 * show a module, action, count, or record link that the role is not permitted
 * to access."
 *
 * Two consequences shape this file.
 *
 * First, `permission` is present on every item and unused today. Module 1 owns
 * dynamic RBAC (SEC-07, `resource.action.scope`), so there is nothing yet to
 * ask. The field exists so the filter has somewhere to read from rather than
 * being retrofitted across every call site later — and SEC-09 is explicit that
 * hiding an item is presentation, never authorization: the API still decides.
 *
 * Second, this list is short because the application is short. Seeding it with
 * the twelve MVP modules would put eleven links in front of a user that resolve
 * to nothing — a dead link is not a permission problem, it is a lie. Items are
 * added as their module lands, and `LogicalPropertiesTest` fails if an item ever
 * names a route app.ts does not register.
 *
 * §5.1 permits a badge only on Requests, Approvals, Reports and My Quotations.
 * The type says so; nothing counts anything yet.
 */

export type BadgeableItem = 'requests' | 'approvals' | 'reports' | 'my-quotations';

export interface NavigationItem {
    /** Named route, resolved by the router — never a raw path. */
    readonly name: string;
    /** Key into the lang files. Coding Standards §11: no literal reaches a screen. */
    readonly labelKey: string;
    /** SVG path data for a 20×20 view box. Not mirrored: §6.1 mirrors direction, not symbols. */
    readonly icon: string;
    /**
     * `resource.action` or a full `resource.action.scope` triple (`SEC-07`),
     * or **null** for a screen whose route declares no permission.
     *
     * Null is not "visible to everyone by accident": it means the route itself
     * carries no `meta.requiredPermission`, so hiding the link would be the
     * mirror image of the defect §5.1 forbids — a screen the person may open
     * with no way to reach it. `navigation.spec.ts` asserts the two agree.
     */
    readonly permission: string | null;
    /** Only the four §5.1 names may ever carry one. */
    readonly badge?: BadgeableItem;
}

export interface NavigationGroup {
    readonly labelKey: string;
    readonly items: readonly NavigationItem[];
}

export const NAVIGATION: readonly NavigationGroup[] = [
    {
        // §5.1: "group navigation by business module". Customers is the first
        // business module to land, and Deals, Quotations and the rest join this
        // group as they arrive — which is why it is not called "Customers".
        labelKey: 'nav.group.sales',
        items: [
            {
                // §8 puts *Customers* on six roles' screens; the permission is
                // §3.3's `view` row, which is what `GET /customers` names.
                // Scope is deliberately absent: a caller holding `own` must
                // still reach the screen and see their own rows, so naming a
                // scope here would hide it from five of the six.
                name: 'customers',
                labelKey: 'nav.item.customers',
                icon: 'M10 10a3 3 0 1 0 0-6 3 3 0 0 0 0 6zm-6 7c0-2.8 2.7-5 6-5s6 2.2 6 5v1H4z',
                permission: 'customer.view',
            },
            {
                // §8's *Suppliers*. The permission is `catalog.view` because
                // §3.7 is one row pair covering the catalog and its suppliers —
                // there is no `supplier.*` resource to name.
                //
                // ⚠️ §8 and §3.7 disagree about who gets this screen: §3.7
                // grants `catalog.view` to the CEO and the Outdoor Supervisor,
                // §8 gives neither a Suppliers screen. **Owner's ruling,
                // 2026-08-31: the sidebar follows §3.7.** Keying it on §8's
                // screen list would leave a screen the person may open with no
                // way to reach it — the mirror image of the defect §5.1
                // forbids, and the same defect this file names from the other
                // side. Recorded in `CHECKLIST.md` awaiting a `D-xx`, and
                // `navigation.spec.ts` pins this string equal to the route's.
                name: 'suppliers',
                labelKey: 'nav.item.suppliers',
                icon: 'M2 6h9v8H2zm10 2h3.2l1.8 2.4V14h-5zM5 17a1.5 1.5 0 1 0 0-3 1.5 1.5 0 0 0 0 3zm9 0a1.5 1.5 0 1 0 0-3 1.5 1.5 0 0 0 0 3z',
                permission: 'catalog.view',
            },
        ],
    },
    {
        labelKey: 'nav.group.administration',
        items: [
            {
                // §8 gives the Manager an *Employees* screen; §3.11 gives the
                // permission to the Manager and the Super Admin. The item is
                // filtered by `permission` in AppSidebar, which is §5.1's "do
                // not show a module the role is not permitted to access" — and
                // SEC-09's reminder that this is presentation, never the check.
                name: 'users',
                labelKey: 'nav.item.users',
                icon: 'M7 9a3 3 0 1 0 0-6 3 3 0 0 0 0 6zm7 1a2.5 2.5 0 1 0 0-5 2.5 2.5 0 0 0 0 5zM2 16c0-2.5 2.2-4.5 5-4.5s5 2 5 4.5v1H2zm11.2 1H18v-1c0-2-1.6-3.6-3.8-3.6-.6 0-1.1.1-1.6.3.9 1 1.4 2.3 1.4 3.7z',
                permission: 'admin.create_user',
            },
            {
                // §13 screen 3. Drawn for the Super Admin alone, because
                // §3.11 gives "create / edit role · permissions" to nobody
                // else — and `navigation.spec.ts` pins this string equal to the
                // route's `meta.requiredPermission`, so the menu and the guard
                // cannot describe different products.
                name: 'roles',
                labelKey: 'nav.item.roles',
                icon: 'M10 2 3 5v5c0 4 3 6.9 7 8 4-1.1 7-4 7-8V5zm0 4a2 2 0 1 1 0 4 2 2 0 0 1 0-4zm0 5.5c1.7 0 3.2.9 3.9 2.2A5.9 5.9 0 0 1 10 15.6a5.9 5.9 0 0 1-3.9-1.9c.7-1.3 2.2-2.2 3.9-2.2z',
                permission: 'admin.manage_roles',
            },
        ],
    },
    {
        labelKey: 'nav.group.system',
        items: [
            {
                // `SEC-04` · `SEC-05`. The context bar's user chip links here
                // too, which is the "user menu" §5.1 names — but that chip is
                // hidden below 768px, and a security screen that disappears on
                // a phone is one the field roles cannot reach. So it is also a
                // menu item.
                //
                // `permission: null` because the route declares none: §3.11 has
                // no row for managing your own account, and `navigation.spec.ts`
                // asserts the item and the route say the same thing.
                // The one settings page since S-02 — §13's screens 4, 5 and 6
                // as sections of it. `admin.fx_rates` is the **widest** of the
                // three rows those sections carry, so the Manager, who holds FX
                // rates and neither of the others, still reaches the page and
                // sees the one section that is theirs. The same string the
                // route declares; `navigation.spec.ts` pins the two equal.
                name: 'settings',
                labelKey: 'nav.item.settings',
                icon: 'M10 2.5a1 1 0 0 1 .97.76l.3 1.2c.4.13.78.3 1.13.5l1.06-.64a1 1 0 0 1 1.22.15l1.06 1.06a1 1 0 0 1 .15 1.22l-.64 1.06c.2.35.37.73.5 1.13l1.2.3a1 1 0 0 1 .76.97v1.5a1 1 0 0 1-.76.97l-1.2.3c-.13.4-.3.78-.5 1.13l.64 1.06a1 1 0 0 1-.15 1.22l-1.06 1.06a1 1 0 0 1-1.22.15l-1.06-.64c-.35.2-.73.37-1.13.5l-.3 1.2a1 1 0 0 1-.97.76h-1.5a1 1 0 0 1-.97-.76l-.3-1.2a5.9 5.9 0 0 1-1.13-.5l-1.06.64a1 1 0 0 1-1.22-.15L3.5 15.9a1 1 0 0 1-.15-1.22l.64-1.06a5.9 5.9 0 0 1-.5-1.13l-1.2-.3A1 1 0 0 1 1.5 11.2V9.7a1 1 0 0 1 .76-.97l1.2-.3c.13-.4.3-.78.5-1.13L3.32 6.2a1 1 0 0 1 .15-1.22L4.53 3.9a1 1 0 0 1 1.22-.15l1.06.64c.35-.2.73-.37 1.13-.5l.3-1.2A1 1 0 0 1 9.2 2.5zM10 7.6a2.4 2.4 0 1 0 0 4.8 2.4 2.4 0 0 0 0-4.8z',
                permission: 'admin.fx_rates',
            },
            {
                // `DB-05`'s four managed lists. **`permission: null` because
                // the route declares none** — §3.11 has no row for them and the
                // read is authentication alone, so hiding the link would be the
                // defect §5.1's mirror image: a screen the person may open with
                // no way to reach it. `navigation.spec.ts` pins the two equal.
                name: 'managed-lists',
                labelKey: 'nav.item.managedLists',
                icon: 'M3 4.5h2v2H3zm4 0h10v2H7zM3 9h2v2H3zm4 0h10v2H7zM3 13.5h2v2H3zm4 0h10v2H7z',
                permission: null,
            },
            {
                name: 'account-security',
                labelKey: 'nav.item.accountSecurity',
                icon: 'M10 2 4 4.5V9c0 3.6 2.5 6.9 6 8 3.5-1.1 6-4.4 6-8V4.5zm0 4.5a2 2 0 0 1 2 2c0 .8-.5 1.5-1.2 1.8l.4 2.2H8.8l.4-2.2A2 2 0 0 1 10 6.5z',
                permission: null,
            },
            {
                name: 'home',
                labelKey: 'nav.item.home',
                icon: 'M3 10.5 10 4l7 6.5V17a1 1 0 0 1-1 1h-4v-5H8v5H4a1 1 0 0 1-1-1z',
                // Module 0's diagnostics page. Its route requires a session
                // and nothing more, and §3 has no `system.*` resource to name —
                // an invented one would hide the link from every role but the
                // Super Admin, whose unconditional access answers before the
                // list is consulted.
                permission: null,
            },
        ],
    },
];
