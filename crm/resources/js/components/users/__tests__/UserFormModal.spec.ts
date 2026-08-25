import { describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import { createI18n } from 'vue-i18n';
import en from '@/locales/en.json';
import ar from '@/locales/ar.json';
import UserFormModal from '@/components/users/UserFormModal.vue';
import { MANAGER_MAY_CREATE, assignableBy } from '@/domain/roleAssignment';

/**
 * Point 5.2 — §9 Flow 9's employee form, and the role filter §3.11 decides.
 *
 * Mounted directly with a supplied role list, because {@link UsersView} can
 * only obtain one for a Super Admin — §3.11 gives `admin.manage_roles` to that
 * role alone. The filter is still the real rule and still worth pinning: it
 * becomes reachable the day the Manager has an endpoint that returns roles.
 */

const ROLES = [
    { id: 'r-outsup', slug: 'outdoor_supervisor', name: 'Outdoor Supervisor', is_system: true },
    { id: 'r-outsales', slug: 'outdoor_sales', name: 'Outdoor Sales', is_system: true },
    { id: 'r-indoor', slug: 'indoor_sales', name: 'Indoor Sales', is_system: true },
    { id: 'r-proc', slug: 'procurement', name: 'Procurement', is_system: true },
    { id: 'r-tl', slug: 'team_leader', name: 'Team Leader', is_system: true },
    { id: 'r-mgr', slug: 'manager', name: 'Manager', is_system: true },
    { id: 'r-ceo', slug: 'ceo', name: 'CEO', is_system: true },
    { id: 'r-sa', slug: 'super_admin', name: 'Super Admin', is_system: true },
];

function mountForm(actorRole: string | null, locale: 'ar' | 'en' = 'en') {
    return mount(UserFormModal, {
        props: { open: true, editing: null, roles: ROLES, actorRole },
        global: {
            plugins: [createI18n({ legacy: false, locale, fallbackLocale: 'en', messages: { ar, en } })],
        },
    });
}

function optionSlugs(wrapper: ReturnType<typeof mountForm>): string[] {
    return wrapper.findAll('[data-testid="user-form-role"] option')
        .map((option) => option.attributes('value') ?? '')
        .filter((value) => value !== '')
        .map((id) => ROLES.find((role) => role.id === id)?.slug ?? id);
}

describe('the role dropdown', () => {
    it('offers a Manager exactly §3.11\'s four', () => {
        // §3.11's create-user cell: "Out.Sup · Out.Sales · Sales · Procurement
        // only". D-78 settled that against §3.12 rule 7's wider reading, which
        // names only Manager, CEO and Super Admin and would leave Team Leader
        // assignable. A Manager may not create a Team Leader.
        expect(optionSlugs(mountForm('manager'))).toEqual([...MANAGER_MAY_CREATE]);
    });

    it('does not offer a Manager the three §3.12 rule 7 forbids', () => {
        const offered = optionSlugs(mountForm('manager'));

        for (const slug of ['manager', 'ceo', 'super_admin', 'team_leader']) {
            expect(offered).not.toContain(slug);
        }
    });

    it('offers a Super Admin every role (§3.11: "✅ any role")', () => {
        expect(optionSlugs(mountForm('super_admin'))).toHaveLength(ROLES.length);
    });

    it.each(['team_leader', 'indoor_sales', 'procurement', 'ceo', 'outdoor_supervisor'])(
        'offers %s nothing, because §3.11 gives them no create-user row',
        (role) => {
            expect(optionSlugs(mountForm(role))).toEqual([]);
        },
    );

    it('offers nothing with no session', () => {
        expect(optionSlugs(mountForm(null))).toEqual([]);
    });

    it('says so rather than showing an empty dropdown', () => {
        expect(mountForm('indoor_sales').text()).toContain('no role you are permitted to assign');
    });
});

describe('assignableBy', () => {
    it('filters against what the server actually returned, not a hard-coded list', () => {
        // §3.12 rule 5 makes a ninth role a configuration change. A filter that
        // only knew the shipped eight would drop a role the database has.
        const available = ['indoor_sales', 'auditor'];

        expect(assignableBy('super_admin', available)).toEqual(available);
        expect(assignableBy('manager', available)).toEqual(['indoor_sales']);
    });
});

describe('the form', () => {
    it('renders its strings in both languages', () => {
        for (const locale of ['en', 'ar'] as const) {
            expect(mountForm('super_admin', locale).text()).not.toMatch(/users\.form\./);
        }
    });

    it('writes no physical inline property that would break RTL', () => {
        const html = mountForm('super_admin', 'ar').html();

        expect(html).not.toMatch(/class="[^"]*\b(?:ml|mr|pl|pr)-\d/);
        expect(html).not.toMatch(/\btext-(?:left|right)\b/);
    });
});
