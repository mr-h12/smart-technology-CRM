import { describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import { createI18n } from 'vue-i18n';
import en from '@/locales/en.json';
import ar from '@/locales/ar.json';
import RoleFormModal from '@/components/roles/RoleFormModal.vue';

/**
 * Point 4.2 — §13 screen 3's "create new roles" form.
 *
 * §3.12 rule 5 · §6.1 · §6.6 · `CLAUDE.md`'s Arabic-and-English rule. The
 * server owns every refusal (`CustomRoleManagementTest`); these are about what
 * the form collects and what it refuses to send.
 */
function mountModal(props: Record<string, unknown> = {}, locale: 'ar' | 'en' = 'en') {
    return mount(RoleFormModal, {
        props: { open: true, busy: false, errorKey: null, ...props },
        global: {
            plugins: [createI18n({ legacy: false, locale, fallbackLocale: 'en', messages: { ar, en } })],
        },
    });
}

async function fill(
    wrapper: ReturnType<typeof mountModal>,
    values: { slug?: string; name?: string; nameAr?: string; description?: string },
): Promise<void> {
    if (values.slug !== undefined) {
        await wrapper.find('[data-testid="role-form-slug"]').setValue(values.slug);
    }

    if (values.name !== undefined) {
        await wrapper.find('[data-testid="role-form-name"]').setValue(values.name);
    }

    if (values.nameAr !== undefined) {
        await wrapper.find('[data-testid="role-form-name-ar"]').setValue(values.nameAr);
    }

    if (values.description !== undefined) {
        await wrapper.find('[data-testid="role-form-description"]').setValue(values.description);
    }

    await wrapper.find('[data-testid="role-form-submit"]').trigger('submit');
}

describe('what it collects', () => {
    it('renders nothing at all while closed', () => {
        expect(mountModal({ open: false }).find('[data-testid="role-form-modal"]').exists()).toBe(false);
    });

    it('emits the slug and both labels, trimmed', async () => {
        const wrapper = mountModal();

        await fill(wrapper, { slug: '  auditor  ', name: '  Auditor  ', nameAr: '  مدقّق  ', description: ' Reviews ' });

        expect(wrapper.emitted('submit')?.[0]?.[0]).toEqual({
            slug: 'auditor',
            name: 'Auditor',
            name_ar: 'مدقّق',
            description: 'Reviews',
        });
    });

    it('sends null rather than an empty Arabic label', async () => {
        // A stored `""` would make `RoleView::label()` treat the Arabic name as
        // present and render a role with no visible name on the Arabic screen.
        const wrapper = mountModal();

        await fill(wrapper, { slug: 'auditor', name: 'Auditor', nameAr: '   ' });

        expect(wrapper.emitted('submit')?.[0]?.[0]).toMatchObject({ name_ar: null, description: null });
    });
});

describe('what it refuses to send (§6.6)', () => {
    it('does not submit a malformed slug, and says which field is wrong', async () => {
        const wrapper = mountModal();

        await fill(wrapper, { slug: 'Not A Slug', name: 'Auditor' });

        expect(wrapper.emitted('submit')).toBeUndefined();
        expect(wrapper.find('[data-testid="role-form-slug-error"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="role-form-name-error"]').exists()).toBe(false);
    });

    it('does not submit without an English label', async () => {
        // The Arabic one is optional because the server falls back; something
        // has to render, so this one is not.
        const wrapper = mountModal();

        await fill(wrapper, { slug: 'auditor', name: ' ' });

        expect(wrapper.emitted('submit')).toBeUndefined();
        expect(wrapper.find('[data-testid="role-form-name-error"]').exists()).toBe(true);
    });

    it('shows no error before the first submission', () => {
        // §6.6: a field that is red before it has been filled in is noise, not
        // guidance.
        const wrapper = mountModal();

        expect(wrapper.find('[data-testid="role-form-slug-error"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="role-form-name-error"]').exists()).toBe(false);
    });
});

describe('the controls', () => {
    it('emits cancel rather than closing itself', async () => {
        const wrapper = mountModal();

        await wrapper.find('[data-testid="role-form-cancel"]').trigger('click');

        expect(wrapper.emitted('cancel')).toHaveLength(1);
        expect(wrapper.find('[data-testid="role-form-modal"]').exists()).toBe(true);
    });

    it('cancels on Escape (§6.1), and not while a request is in flight', async () => {
        const wrapper = mountModal();

        document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }));
        expect(wrapper.emitted('cancel')).toHaveLength(1);

        await wrapper.setProps({ busy: true });
        document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }));
        expect(wrapper.emitted('cancel')).toHaveLength(1);

        wrapper.unmount();
    });

    it('stops listening for Escape once unmounted', () => {
        const wrapper = mountModal();
        wrapper.unmount();

        expect(() => document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }))).not.toThrow();
        expect(wrapper.emitted('cancel')).toBeUndefined();
    });

    it('disables the submit button while the request is in flight', () => {
        expect(mountModal({ busy: true }).find('[data-testid="role-form-submit"]').attributes('disabled')).toBeDefined();
    });

    it('renders the server refusal it was handed', () => {
        const wrapper = mountModal({ errorKey: 'roles.error.slugTaken' });

        expect(wrapper.find('[data-testid="role-form-error"]').text()).toBe(en.roles.error.slugTaken);
    });

    it('clears the fields when it is reopened', async () => {
        const wrapper = mountModal({ open: false });

        await wrapper.setProps({ open: true });
        await wrapper.find('[data-testid="role-form-slug"]').setValue('auditor');
        await wrapper.setProps({ open: false });
        await wrapper.setProps({ open: true });

        expect((wrapper.find('[data-testid="role-form-slug"]').element as HTMLInputElement).value).toBe('');
    });
});

describe('Arabic', () => {
    it('renders the Arabic form, not raw keys', () => {
        const wrapper = mountModal({}, 'ar');

        expect(wrapper.find('[data-testid="role-form-submit"]').text()).toBe(ar.roles.create.submit);
        expect(wrapper.text()).not.toContain('roles.create');
    });

    it('marks the Arabic field as Arabic, so the caret sits on the right side', () => {
        // The form itself follows the reader's locale; this one input is always
        // Arabic content regardless of which language the page is in.
        const field = mountModal().find('[data-testid="role-form-name-ar"]');

        expect(field.attributes('dir')).toBe('rtl');
        expect(field.attributes('lang')).toBe('ar');
    });
});
