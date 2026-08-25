/**
 * The shape of a role's machine key, client-side.
 *
 * ⚠️ **A deliberate duplicate of `CreateRoleRequest::SLUG_PATTERN`**, pinned by
 * `RoleSlugMirrorTest`, which reads both files and asserts the two strings are
 * identical. The precedent is `PasswordPolicyMirrorTest` (Point 5.4): the
 * server owns the rule and refuses regardless, and this copy exists only so
 * §6.6's "an error is explained before submission" does not require a round
 * trip that can end only in a 422.
 *
 * The pattern itself is §3.1's own slugs, measured rather than invented:
 * `super_admin`, `outdoor_supervisor`, `team_leader`. Lowercase, starting with
 * a letter, and at most 64 characters — which is what `roles.slug` holds.
 */
export const SLUG_PATTERN = /^[a-z][a-z0-9_]{1,63}$/;

export function isSlugShaped(slug: string): boolean {
    return SLUG_PATTERN.test(slug);
}
