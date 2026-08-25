import { describe, expect, it } from 'vitest';
import { NAVIGATION } from '@/navigation';
import { routes } from '@/router';

/**
 * Point 5.2 — the menu and the route table must agree.
 *
 * Design System §5.1: "do not show a module, action, count, or record link that
 * the role is not permitted to access." Two ways to break that, and this
 * catches both.
 *
 * A link whose `permission` is **stricter** than its route's hides a screen the
 * person may open. A link whose `permission` is **looser** shows a link that
 * bounces them to the denial screen. Either way the sidebar and the guard are
 * describing different products, and the sidebar is the one the user believes.
 */

const byName = new Map(routes.map((route) => [route.name, route]));

describe('every navigation item', () => {
    const items = NAVIGATION.flatMap((group) => group.items);

    it('is not empty, or this file asserts nothing', () => {
        expect(items.length).toBeGreaterThan(0);
    });

    it.each(items.map((item) => [item.name, item] as const))('%s names a registered route', (_name, item) => {
        expect(byName.has(item.name)).toBe(true);
    });

    it.each(items.map((item) => [item.name, item] as const))(
        '%s declares exactly the permission its route requires',
        (_name, item) => {
            const required = byName.get(item.name)?.meta?.requiredPermission ?? null;

            expect(item.permission).toBe(required);
        },
    );

    it.each(items.map((item) => [item.name, item] as const))('%s points at a route behind auth', (_name, item) => {
        // Every screen in this system is behind a session (`SEC-01`: no public
        // sign-up, and no public page but the login form).
        expect(byName.get(item.name)?.meta?.requiresAuth).toBe(true);
    });
});
