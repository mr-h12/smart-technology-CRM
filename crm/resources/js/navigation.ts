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
                // §13 screen 4. `admin.system_settings` — §3.11's own row,
                // the Super Admin's, and the same string the route declares.
                // `navigation.spec.ts` pins the two equal, so the menu and the
                // guard cannot describe different products.
                name: 'settings',
                labelKey: 'nav.item.settings',
                icon: 'M10 2.5a1 1 0 0 1 .97.76l.3 1.2c.4.13.78.3 1.13.5l1.06-.64a1 1 0 0 1 1.22.15l1.06 1.06a1 1 0 0 1 .15 1.22l-.64 1.06c.2.35.37.73.5 1.13l1.2.3a1 1 0 0 1 .76.97v1.5a1 1 0 0 1-.76.97l-1.2.3c-.13.4-.3.78-.5 1.13l.64 1.06a1 1 0 0 1-.15 1.22l-1.06 1.06a1 1 0 0 1-1.22.15l-1.06-.64c-.35.2-.73.37-1.13.5l-.3 1.2a1 1 0 0 1-.97.76h-1.5a1 1 0 0 1-.97-.76l-.3-1.2a5.9 5.9 0 0 1-1.13-.5l-1.06.64a1 1 0 0 1-1.22-.15L3.5 15.9a1 1 0 0 1-.15-1.22l.64-1.06a5.9 5.9 0 0 1-.5-1.13l-1.2-.3A1 1 0 0 1 1.5 11.2V9.7a1 1 0 0 1 .76-.97l1.2-.3c.13-.4.3-.78.5-1.13L3.32 6.2a1 1 0 0 1 .15-1.22L4.53 3.9a1 1 0 0 1 1.22-.15l1.06.64c.35-.2.73-.37 1.13-.5l.3-1.2A1 1 0 0 1 9.2 2.5zM10 7.6a2.4 2.4 0 1 0 0 4.8 2.4 2.4 0 0 0 0-4.8z',
                permission: 'admin.system_settings',
            },
            {
                // §13 screen 5. `admin.fx_rates` — §3.11's own row, held by
                // the Super Admin **and the Manager**, and the same string the
                // route declares (`navigation.spec.ts` pins the two equal).
                // The rounding half of that screen needs `admin.system_settings`
                // and is drawn by permission inside the component; naming the
                // narrower row here would hide the whole screen from the
                // Manager, who §3.11 sends to it.
                name: 'currencies',
                labelKey: 'nav.item.currencies',
                icon: 'M10 2a8 8 0 1 0 0 16 8 8 0 0 0 0-16zm.9 3v1.2c1.2.2 2 .9 2.1 2h-1.7c-.1-.5-.5-.8-1.2-.8-.7 0-1.1.3-1.1.8 0 .4.3.6 1.2.8l.8.2c1.6.4 2.3 1 2.3 2.2 0 1.2-.9 2-2.4 2.2V15H9.2v-1.4c-1.4-.2-2.3-1-2.4-2.2h1.8c.1.6.6.9 1.4.9.8 0 1.2-.3 1.2-.8 0-.4-.3-.6-1.3-.9l-.9-.2c-1.5-.4-2.1-1-2.1-2.1 0-1.2.9-2 2.3-2.2V5z',
                permission: 'admin.fx_rates',
            },
            {
                // §13 screen 6. `admin.system_limits` — §3.11's own row, one
                // line below "system settings" and deliberately not it, and the
                // same string the route declares (`navigation.spec.ts` pins the
                // two equal). Both rows are the Super Admin's today; naming the
                // wrong one would only become visible on the day §3.12 rule 5
                // regrants one of them.
                name: 'limits',
                labelKey: 'nav.item.limits',
                icon: 'M10 2a8 8 0 1 0 0 16 8 8 0 0 0 0-16zm0 2a6 6 0 0 1 6 6 6 6 0 0 1-6 6 6 6 0 0 1-6-6 6 6 0 0 1 6-6zm-.9 1.8v4.6l3.4 2.1.9-1.4-2.6-1.6V5.8z',
                permission: 'admin.system_limits',
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
