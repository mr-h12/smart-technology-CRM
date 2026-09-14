<?php

declare(strict_types=1);

namespace App\Modules\Quotations\Application\Writing;

use App\Modules\Audit\Domain\AuditEvent;
use App\Modules\Audit\Domain\Contracts\AuditRecorderInterface;
use App\Modules\Deals\Domain\Contracts\DealFactsInterface;
use App\Modules\Identity\Domain\Rbac\AuthorizationRefused;
use App\Modules\Quotations\Domain\Access\QuotationRowScope;
use App\Modules\Quotations\Domain\Contracts\QuotationDirectoryInterface;
use App\Modules\Quotations\Domain\Writing\QuotationDraft;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * §6's create, and §5's pricing made real — `CreateSupplierQuotation`'s shape
 * (Module 6 Point 2.1), with the one difference §5 forces: every line price is
 * the **backend's**, read from the supplier and converted, never the caller's.
 *
 * ── `DB-11`: the whole quotation, or none of it ────────────────────────────
 *
 * The header, the `document_sequences` allocation its code comes from, both
 * child tables and the `QUOTATION_CREATED` audit row commit together. A line
 * that cannot be priced does not leave a half-written quotation: the block is a
 * throw, before the first write, and the transaction rolls back.
 *
 * ── The pricing lives in {@see PriceQuotation} ────────────────────────────
 *
 * §5.1's lines, §5.2's totals, `D-09`'s captured rate, `D-63`'s derived tax
 * and §5.6's block-or-warn were this class's until Point 3.6 gave the edit the
 * same needs; they moved out so there is one implementation, not two.
 *
 * ── §3.5's create scope is a constraint on the deal (Point 3.4) ────────────
 *
 * `create` is `All · Team · Own` and a quotation has no owner column, so —
 * on `SaveDeal`'s reading that "a scope on `create` is not a `WHERE`" — the
 * only thing it can constrain is **which deal** is quoted. The owner ruled
 * (2026-09-11) that a quotation's "own" is its deal's `owner_id`, not the
 * tracking field `created_by`: an `own` caller quotes their own deals, `all`
 * quotes anybody's, `team` permits nothing until a team entity exists (the
 * gap {@see QuotationRowScope} records) and fails closed. The deal's owner and
 * customer come from {@see DealFactsInterface}; the same ruling holds the
 * request's `customer_id` to the deal's, since §6.2 carries both and a
 * quotation addressed to somebody other than its deal's customer is a defect.
 * Both checks run inside the transaction, before any line is priced.
 */
final readonly class CreateQuotation
{
    public function __construct(
        private QuotationDirectoryInterface $quotations,
        private PriceQuotation $pricer,
        private DealFactsInterface $deals,
        private AuditRecorderInterface $audit,
        private TermSuggestions $terms,
        private ConnectionInterface $connection,
    ) {}

    /**
     * @param  array<string, mixed>  $validated  already validated at the boundary
     * @param  list<string>  $heldScopes  §3.2 codes, as the authorisation decision reports them
     */
    public function create(array $validated, array $heldScopes, string $actorId): QuotationCreated
    {
        $scope = QuotationRowScope::resolve($heldScopes, $actorId);

        return $this->connection->transaction(function () use ($validated, $scope, $actorId): QuotationCreated {
            $this->guardDeal($validated, $scope);

            $priced = $this->pricer->price(
                $validated,
                self::string($validated['customer_id'] ?? null, 'customer_id'),
                now()->toDateTimeImmutable(),
            );

            $draft = QuotationDraft::forCreate($validated)
                ->withComputed($priced->computed)
                ->withLines($priced->items, $priced->additionalItems);

            $quotation = $this->quotations->create($draft, $actorId);

            // Point 6.8 — the saved terms become the actor's suggestions.
            $this->terms->remember($validated, $actorId);

            // No old values — absent on a create. The lines go beside the header
            // for `CreateSupplierQuotation`'s reason: "what was created?" answered
            // by the header alone is half the answer.
            $this->audit->record(
                AuditEvent::of('QUOTATION_CREATED'),
                'quotation',
                $quotation->id,
                null,
                [...$draft->attributes, 'items' => $priced->items, 'additional_items' => $priced->additionalItems],
            );

            return new QuotationCreated($quotation, $priced->quantityWarnings);
        });
    }

    /**
     * §3.5's create scope, applied to the one thing a create can be scoped by.
     *
     * Order matters and is the fail-closed order: a scope that permits no deal
     * refuses before the deal is even looked up (`team`, and any code the
     * resolver does not know); an unknown deal is the request's fault
     * (`deal_id`), not a permission; an owned deal outside the caller's reach
     * is a refusal that names nothing about the deal (§5.1's "do not reveal
     * which"); and only then is the addressee checked against the deal's.
     *
     * @param  array<string, mixed>  $validated
     */
    private function guardDeal(array $validated, QuotationRowScope $scope): void
    {
        if ($scope->permitsNothing()) {
            throw AuthorizationRefused::of('quotation', 'create');
        }

        $facts = $this->deals->factsOf(self::string($validated['deal_id'] ?? null, 'deal_id'));

        if ($facts === null) {
            throw ValidationException::withMessages([
                'deal_id' => [(string) __('quotations.validation.unknown_deal')],
            ]);
        }

        // `own`: the deal's owner must be one the caller may reach.
        if (! $scope->reaches($facts->ownerId)) {
            throw AuthorizationRefused::of('quotation', 'create');
        }

        if ($facts->customerId !== self::string($validated['customer_id'] ?? null, 'customer_id')) {
            throw ValidationException::withMessages([
                'customer_id' => [(string) __('quotations.validation.customer_not_the_deals')],
            ]);
        }
    }

    private static function string(mixed $value, string $what): string
    {
        if (! is_string($value)) {
            throw new InvalidArgumentException("{$what} must be a string.");
        }

        return $value;
    }
}
