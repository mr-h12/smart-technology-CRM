<?php

declare(strict_types=1);

namespace Tests\Feature\Reports;

use App\Modules\Identity\Domain\Rbac\Role as RoleName;
use App\Modules\Identity\Infrastructure\Eloquent\Role;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Modules\Reports\Domain\ReportNotFound;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * An RPT-MN draft placed straight in the table, as J-07 drafts it (`D-140`), and the moves its reach
 * tests share (`D-141`, `OpenAPI §3.2`): a user moved to another role, a grant revoked, a use case
 * refused as not found — for `MonthlyReportsGenerationTest`, `ReportPdfEndpointTest` and
 * `ReportPdfGenerationTest` (Module 13 · 8.2).
 */
trait InsertsMonthlyReport
{
    /** The calendar month starting `$periodStart`, coded in insert order (RPT-MN-2026-0001, -0002, …). */
    private function monthlyRow(User $author, ?string $createdAt = null, string $periodStart = '2026-09-01'): string
    {
        $createdAt ??= now()->toDateTimeString();
        $id = (string) Str::uuid7();
        DB::table('reports')->insert([
            'id' => $id, 'code' => sprintf('RPT-MN-2026-%04d', DB::table('reports')->where('type', 'monthly')->count() + 1),
            'type' => 'monthly', 'status' => 'draft', 'author_id' => $author->id,
            'period_start' => $periodStart, 'period_end' => date('Y-m-t', (int) strtotime($periodStart)),
            'created_at' => $createdAt, 'updated_at' => $createdAt,
        ]);

        return $id;
    }

    private function moveTo(User $user, RoleName $role): void
    {
        DB::table('users')->where('id', $user->id)->update(['role_id' => Role::query()->where('slug', $role->value)->firstOrFail()->id]);
    }

    private function revoke(RoleName $role, string $resource, string $action, string $scope): void
    {
        $removed = DB::table('role_permissions')
            ->where('role_id', Role::query()->where('slug', $role->value)->firstOrFail()->id)
            ->whereIn('permission_id', DB::table('permissions')->where(['resource' => $resource, 'action' => $action, 'scope' => $scope])->pluck('id'))
            ->delete();
        self::assertSame(1, $removed, "The {$role->value} role held no {$resource}.{$action}.{$scope} to revoke.");
    }

    /** `$refusal` names what must have been refused, e.g. "D-141: a deactivated author's read". */
    private function assertThrowsNotFound(callable $call, string $refusal): void
    {
        try {
            $call();
        } catch (ReportNotFound) {
            $this->addToAssertionCount(1);

            return;
        }
        self::fail("{$refusal} was not refused as not found.");
    }
}
