import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { Router } from 'vue-router';
import type { AuthenticatedUser } from '@/stores/auth';

/**
 * Point 5.1 — the navigation guards.
 *
 * §3.1 · §8 · §3.12 rule 1 · `SEC-01` · `SEC-09` · `D-67`.
 *
 * ── What these tests are not ───────────────────────────────────────────────
 *
 * They are not authorization tests. §3.12 rule 1 puts enforcement at the API,
 * and `RbacEnforcementTest` and `RolePermissionManagementTest` are where a 403
 * is proved. A guard that let somebody through would show them a screen whose
 * every request is refused — a presentation defect, and worth catching, but not
 * the boundary.
 *
 * The router is built through `createAppRouter()` rather than assembled here,
 * so the `beforeEach` under test is the one the application installs.
 */

const PROFILE: AuthenticatedUser = {
    id: '01a0-user',
    name: 'Test Indoor Sales',
    email: 'indoor.sales@example.test',
    is_active: true,
    role: { id: '01a0-role', slug: 'indoor_sales', name: 'Indoor Sales' },
    permissions: ['customer.view.own'],
    unconditional_access: false,
};

function jsonResponse(status: number, body: unknown): Response {
    return new Response(JSON.stringify(body), {
        status,
        headers: { 'Content-Type': 'application/json' },
    });
}

async function freshRouter(user: AuthenticatedUser | null): Promise<Router> {
    vi.resetModules();
    window.localStorage.clear();
    window.history.replaceState({}, '', '/');

    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(
        jsonResponse(201, {
            data: { token: 'a'.repeat(64), token_type: 'Bearer', idle_timeout_seconds: 28800, user },
        }),
    ));

    const { createAppRouter } = await import('@/router');
    const router = createAppRouter();

    if (user !== null) {
        const { useAuth } = await import('@/stores/auth');
        await useAuth().login(user.email, 'Passw0rd123');
    }

    await router.push('/');
    await router.isReady();

    return router;
}

beforeEach(() => {
    window.localStorage.clear();
});

describe('an unauthenticated visitor', () => {
    it('is sent to the login screen from a protected route', async () => {
        const router = await freshRouter(null);

        await router.push('/');

        expect(router.currentRoute.value.name).toBe('login');
    });

    it('keeps where they were going, so the login can return them to it', async () => {
        const router = await freshRouter(null);

        await router.push('/403');

        expect(router.currentRoute.value.query.redirect).toBe('/403');
    });

    it('may reach the login screen itself (SEC-01 has no other public page)', async () => {
        const router = await freshRouter(null);

        await router.push('/login');

        expect(router.currentRoute.value.name).toBe('login');
    });

    it('is sent to the login screen from an unknown path rather than a blank page', async () => {
        const router = await freshRouter(null);

        await router.push('/no-such-screen');

        expect(router.currentRoute.value.name).toBe('login');
    });
});

describe('an authenticated visitor', () => {
    it('reaches a protected route', async () => {
        const router = await freshRouter(PROFILE);

        await router.push('/');

        expect(router.currentRoute.value.name).toBe('home');
    });

    it('is turned away from the login screen', async () => {
        const router = await freshRouter(PROFILE);

        await router.push('/login');

        expect(router.currentRoute.value.name).not.toBe('login');
    });

    /**
     * ⚠️ **Changed by Module 3 Point 4.0, and the change is the point.**
     *
     * This test used to assert `home` *"while §8's screen has not been built"*.
     * §8 opens Indoor Sales with *Customers* and `LANDING_ROUTE.indoor_sales`
     * has said `'customers'` since Point 5.1; `landingRouteFor` resolved it to
     * the fallback only because the route was not registered. Registering it is
     * what 4.0 did, so the redirect now reaches §8's own screen — the mapping
     * was never edited, which is exactly what its docblock promised: "each
     * module lands by registering its route, not by editing this file".
     */
    it('lands on §8\'s own first screen once that route is registered', async () => {
        const router = await freshRouter(PROFILE);

        await router.push('/login');

        expect(router.currentRoute.value.name).toBe('customers');
    });

    /** The fallback still has to work, and every other role's §8 screen is still unbuilt. */
    it('still lands on the fallback for a role whose §8 screen does not exist yet', async () => {
        // §8 opens the Manager with *Dashboard*, which is Module 14.
        const router = await freshRouter({
            ...PROFILE,
            name: 'Test Manager',
            email: 'manager@example.test',
            role: { id: '01a0-manager', slug: 'manager', name: 'Manager' },
        });

        await router.push('/login');

        expect(router.currentRoute.value.name).toBe('home');
    });
});

describe('a route that declares a permission (SEC-09)', () => {
    it('renders for a role that holds it', async () => {
        const router = await freshRouter(PROFILE);

        router.addRoute({
            path: '/customers',
            name: 'customers',
            component: { template: '<div />' },
            meta: { requiresAuth: true, requiredPermission: 'customer.view' },
        });

        await router.push('/customers');

        expect(router.currentRoute.value.name).toBe('customers');
    });

    it('shows the denial screen for a role that does not', async () => {
        const router = await freshRouter(PROFILE);

        router.addRoute({
            path: '/employees',
            name: 'employees',
            component: { template: '<div />' },
            meta: { requiresAuth: true, requiredPermission: 'admin.create_user' },
        });

        await router.push('/employees');

        expect(router.currentRoute.value.name).toBe('forbidden');
    });

    it('asks for the login screen before it asks which permission a route wants', async () => {
        // Order matters: answering "forbidden" to a signed-out caller would tell
        // them the route exists and is permission-protected.
        const router = await freshRouter(null);

        router.addRoute({
            path: '/employees',
            name: 'employees',
            component: { template: '<div />' },
            meta: { requiresAuth: true, requiredPermission: 'admin.create_user' },
        });

        await router.push('/employees');

        expect(router.currentRoute.value.name).toBe('login');
    });
});

describe('landingRouteFor', () => {
    it('uses §8\'s screen once that route exists', async () => {
        const { landingRouteFor } = await import('@/router');

        expect(landingRouteFor('indoor_sales', () => true)).toBe('customers');
        expect(landingRouteFor('procurement', () => true)).toBe('assigned-deals');
        expect(landingRouteFor('ceo', () => true)).toBe('dashboard');
    });

    it('falls back rather than redirecting to a route that does not exist', async () => {
        const { landingRouteFor, FALLBACK_ROUTE } = await import('@/router');

        expect(landingRouteFor('manager', () => false)).toBe(FALLBACK_ROUTE);
    });

    it('falls back for a ninth role §3.12 rule 5 lets an administrator add', async () => {
        const { landingRouteFor, FALLBACK_ROUTE } = await import('@/router');

        expect(landingRouteFor('auditor', () => true)).toBe(FALLBACK_ROUTE);
        expect(landingRouteFor(null, () => true)).toBe(FALLBACK_ROUTE);
    });
});

describe('safeRedirect', () => {
    it.each([
        ['a same-origin path', '/users', '/users'],
        ['a path with a query', '/users?page=2', '/users?page=2'],
    ])('accepts %s', async (_name, value, expected) => {
        const { safeRedirect } = await import('@/router');

        expect(safeRedirect(value)).toBe(expected);
    });

    it.each([
        ['a protocol-relative URL', '//evil.test/steal'],
        ['an absolute URL', 'https://evil.test/steal'],
        ['a bare word', 'users'],
        ['an array, which is what a repeated query parameter gives', ['/a', '/b']],
        ['nothing at all', undefined],
    ])('refuses %s', async (_name, value) => {
        // The open redirect every "return to where you were" feature ships
        // with by default. `//host` is resolved by the browser as another
        // origin, so the second character matters as much as the first.
        const { safeRedirect } = await import('@/router');

        expect(safeRedirect(value)).toBeNull();
    });
});
