<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Contracts;

/**
 * What another module may read about a user without an admin's grant: the
 * name behind an id (Module 7 Step 6 Q2 — the employee group's label on
 * `GET /quotations?group_by=employee`, which a Team Leader sees and
 * `GET /users` would refuse them).
 *
 * The facts seam, on {@see \App\Modules\Deals\Domain\Contracts\DealFactsInterface}'s
 * shape; {@see UserDirectoryInterface} is the administrator's surface and
 * stays behind `admin.create_user`.
 */
interface UserFactsInterface
{
    /**
     * User id => display name for one page's owners. The hidden Super Admin
     * (§3.1 "hidden from all user lists") and a `DB-01` soft-deleted account
     * have no entry, so the caller falls back to whatever it labelled before.
     * The empty list answers `[]` without a query.
     *
     * @param  list<string>  $userIds
     * @return array<string, string>
     */
    public function namesOf(array $userIds): array;

    /**
     * F-40 · 1.4 — `D-107` ruling (2): each user's optional job title, on the
     * terms of {@see namesOf()} (no hidden or deleted account). Untitled is
     * `null`, not absent.
     *
     * @param  list<string>  $userIds
     * @return array<string, array{en: string|null, ar: string|null}>
     */
    public function jobTitlesOf(array $userIds): array;

    /**
     * Module 13, first slice (`D-129`) — each **active** user's role slug, on
     * the terms of {@see namesOf()}: an inactive, hidden or deleted account has
     * no entry, so "is this an active Manager?" is one lookup.
     *
     * @param  list<string>  $userIds
     * @return array<string, string>
     */
    public function activeRolesOf(array $userIds): array;

    /**
     * Module 13, first slice (`D-127`) — the active, visible users whose role
     * holds `resource.action`, with the scopes it holds it at. Who those scopes
     * reach is the caller's rule; this says only who holds what. `created_at` is
     * the account's creation instant, UTC (`Y-m-d H:i:s`) — `D-137`'s J-05 floor.
     *
     * @return array<string, array{name: string, role: string, scopes: list<string>, created_at: string}>
     */
    public function holdersOf(string $resource, string $action): array;

    /**
     * Module 13 · 7.5c (`D-138`) — a Team Leader's team: the leader first, then
     * every user whose `team_leader_id` is theirs. A deactivated member stays in
     * the team (owner, 2026-10-18); a hidden or soft-deleted account does not,
     * nor does a lead role (`D-139`), whatever its `team_leader_id` says.
     * Computed per call, never cached.
     *
     * @return list<string>
     */
    public function teamMemberIdsOf(string $leaderId): array;

    /**
     * Module 16 · 2 (`D-153`, `D-138`) — each user's Team Leader, for an alert to
     * "the owner and the owner's Team Leader". Only an active, visible, live
     * leader is named; the user themself may be inactive. A user with no such
     * leader has no entry; the empty list answers `[]` without a query.
     *
     * @param  list<string>  $userIds
     * @return array<string, string> user id → leader id
     */
    public function teamLeadersOf(array $userIds): array;
}
