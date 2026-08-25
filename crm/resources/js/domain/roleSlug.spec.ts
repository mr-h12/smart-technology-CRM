import { describe, expect, it } from 'vitest';
import { SLUG_PATTERN, isSlugShaped } from '@/domain/roleSlug';

/**
 * Point 4.2 — the client half of the role slug's shape.
 *
 * `RoleSlugMirrorTest` asserts this file and `CreateRoleRequest::SLUG_PATTERN`
 * are the same pattern. These are the cases that say what the pattern *means*,
 * which a string comparison cannot.
 */
describe('isSlugShaped', () => {
    it('accepts every slug §3.1 already uses', () => {
        for (const slug of [
            'super_admin', 'ceo', 'manager', 'team_leader',
            'outdoor_supervisor', 'outdoor_sales', 'indoor_sales', 'procurement',
        ]) {
            expect(isSlugShaped(slug)).toBe(true);
        }
    });

    it('rejects what a machine key must never be', () => {
        // A slug appears in URLs and in `Role::tryFrom()`; a capital or a space
        // in it is a defect that shows up somewhere far away.
        for (const bad of ['Auditor', 'audit or', 'audit-or', '9auditor', '_auditor', 'a', '', 'مدقق']) {
            expect(isSlugShaped(bad)).toBe(false);
        }
    });

    it('stops at the width of the column', () => {
        expect(isSlugShaped('a'.repeat(64))).toBe(true);
        expect(isSlugShaped('a'.repeat(65))).toBe(false);
    });

    it('is anchored, so a slug hiding inside a sentence is not one', () => {
        expect(SLUG_PATTERN.test('my role auditor here')).toBe(false);
    });
});
