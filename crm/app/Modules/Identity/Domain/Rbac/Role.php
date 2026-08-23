<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Rbac;

/**
 * §3.1's eight roles, in the document's order.
 *
 * These are the roles the system ships with, not the roles it is limited to:
 * `§3.12` rule 5 puts the matrix in the database, so a ninth role is a
 * configuration change. What this enum fixes is the set the seeded matrix
 * describes, so that a column in §3 always has somewhere to land.
 */
enum Role: string
{
    /** The developer. Hidden from every list (§3.12 rule 6), scope All. */
    case SuperAdmin = 'super_admin';

    /** External observer — read-only. */
    case Ceo = 'ceo';

    /** Highest operational authority. */
    case Manager = 'manager';

    /** Team owner. */
    case TeamLeader = 'team_leader';

    /** Monitors visits only, up to customer handover. */
    case OutdoorSupervisor = 'outdoor_supervisor';

    /** Field visits, then continues as Indoor Sales. */
    case OutdoorSales = 'outdoor_sales';

    /** Full deal cycle with the customer. */
    case IndoorSales = 'indoor_sales';

    /** Negotiation and cost reduction. */
    case Procurement = 'procurement';

    /**
     * The display name §3.1 gives this role, in English.
     *
     * Separate from the case value because the slug is a machine key that must
     * never change and never be translated, while this is prose that §14.2
     * will eventually want in Arabic too — at which point this becomes the
     * translation key's default rather than the only string. Transcribed from
     * the §3.1 table, and `RoleLabelTest` reads that table back out of the
     * documentation rather than trusting this list.
     */
    public function label(): string
    {
        return match ($this) {
            self::SuperAdmin => 'Super Admin',
            self::Ceo => 'CEO',
            self::Manager => 'Manager',
            self::TeamLeader => 'Team Leader',
            self::OutdoorSupervisor => 'Outdoor Supervisor',
            self::OutdoorSales => 'Outdoor Sales',
            self::IndoorSales => 'Indoor Sales',
            self::Procurement => 'Procurement',
        };
    }

    /**
     * §3.12 rule 6. Never listed in any user list, for any role.
     *
     * A property of the role rather than a filter in one query: the rule says
     * *every* list, and a rule spelled out per screen is a rule that holds
     * until somebody adds a screen.
     */
    public function isHidden(): bool
    {
        return $this === self::SuperAdmin;
    }

    /**
     * Whether the role holds every permission regardless of the matrix.
     *
     * True only for Super Admin (§3.1, scope All). This is why §3.3–§3.10 have
     * no Super Admin column to transcribe — the answer is not in the cells.
     */
    public function hasUnconditionalAccess(): bool
    {
        return $this === self::SuperAdmin;
    }

    /**
     * §3.7's "All operational roles" column.
     *
     * The catalog table has two columns — that one and CEO — so the set is
     * everybody who works in the system: the eight minus the read-only
     * observer and minus the hidden developer, who is covered by
     * hasUnconditionalAccess() instead.
     *
     * @return list<self>
     */
    public static function operational(): array
    {
        return [
            self::Manager,
            self::TeamLeader,
            self::OutdoorSupervisor,
            self::OutdoorSales,
            self::IndoorSales,
            self::Procurement,
        ];
    }
}
