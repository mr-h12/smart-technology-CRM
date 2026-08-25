<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Eloquent;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One signed-in device (`SEC-05`), on the table Point 1.2 built.
 *
 * Not a session store: `SESSION_DRIVER=redis` owns the payload, and this row
 * exists so the device can be listed and revoked. Revoking is the soft delete.
 *
 * @property string $id
 * @property string $user_id
 * @property string|null $impersonator_id
 * @property string $session_id
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property \Illuminate\Support\Carbon $last_activity_at
 */
final class UserSession extends Model
{
    use HasUuids, SoftDeletes;

    protected $table = 'user_sessions';

    /** @var list<string> */
    protected $fillable = ['user_id', 'impersonator_id', 'session_id', 'ip_address', 'user_agent', 'last_activity_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['last_activity_at' => 'datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * `D-29`: eight hours idle. The boundary is passed in rather than computed
     * here, because "now" is a decision and this class does not make those.
     *
     * @param  Builder<self>  $query
     */
    public function scopeIdleSince(Builder $query, \DateTimeInterface $boundary): void
    {
        $query->where('last_activity_at', '<', $boundary);
    }
}
