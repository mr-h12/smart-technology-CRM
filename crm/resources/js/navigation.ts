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
    /** `resource.action.scope` (SEC-07). Read by Module 1, ignored until then. */
    readonly permission: string;
    /** Only the four §5.1 names may ever carry one. */
    readonly badge?: BadgeableItem;
}

export interface NavigationGroup {
    readonly labelKey: string;
    readonly items: readonly NavigationItem[];
}

export const NAVIGATION: readonly NavigationGroup[] = [
    {
        labelKey: 'nav.group.system',
        items: [
            {
                name: 'home',
                labelKey: 'nav.item.home',
                icon: 'M3 10.5 10 4l7 6.5V17a1 1 0 0 1-1 1h-4v-5H8v5H4a1 1 0 0 1-1-1z',
                permission: 'system.health.view',
            },
        ],
    },
];
