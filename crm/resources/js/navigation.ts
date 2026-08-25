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
        ],
    },
    {
        labelKey: 'nav.group.system',
        items: [
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
