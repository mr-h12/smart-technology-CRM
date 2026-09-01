<?php

declare(strict_types=1);

namespace App\Modules\Admin\Presentation;

use App\Modules\Admin\Application\Reference\AddListEntry;
use App\Modules\Admin\Application\Reference\ArchiveListEntry;
use App\Modules\Admin\Application\Reference\RestoreListEntry;
use App\Modules\Admin\Domain\Contracts\ManagedListRepositoryInterface;
use App\Modules\Admin\Domain\Listing\ListingQuery;
use App\Modules\Admin\Domain\Reference\ListEntry;
use App\Modules\Admin\Domain\Reference\ListEntryAlreadyExists;
use App\Modules\Admin\Domain\Reference\ManagedList;
use App\Support\Http\ApiEnvelope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * `DB-05`'s four lists — read by every screen that offers a choice, written
 * from settings.
 *
 * **The two verbs answer to two different authorities.** §3.11 has no row for
 * managed lists at all, so the read is guarded by authentication alone and the
 * write by `admin.system_settings`. `routes/api.php` carries the reasoning.
 *
 * Thin by rule: parse, invoke, serialise.
 */
final class ManagedListController
{
    public function index(Request $request, string $list, ManagedListRepositoryInterface $lists): JsonResponse
    {
        return $this->collection($request, $list, $lists, archived: false);
    }

    /**
     * The withdrawn set — the collection a restore is chosen from.
     *
     * **A route rather than a `filter[archived]`,** because `ListingQuery` is
     * shared with the FX-rate history and refuses `filter[...]` outright: §6.2
     * has each resource declare its own allowed filters, and teaching the
     * shared parser one filter that only one of its two resources may use is a
     * worse answer than a second collection. §6.2 constrains query parameters,
     * not how many collections a resource has.
     *
     * **It carries the write permission, unlike the live list.** The live read
     * is open because §8 puts Customers on six roles' screens and Catalog on
     * five and not one of them renders without a sector or a unit; nothing
     * renders from the archived set at all. It exists to serve the restore, so
     * it answers to the authority the restore does.
     */
    public function archived(Request $request, string $list, ManagedListRepositoryInterface $lists): JsonResponse
    {
        return $this->collection($request, $list, $lists, archived: true);
    }

    private function collection(Request $request, string $list, ManagedListRepositoryInterface $lists, bool $archived): JsonResponse
    {
        $page = $lists->page(self::listNamed($list), ListingQuery::fromQueryString($request->query()), $archived);

        return ApiEnvelope::collection($request, array_map(self::payload(...), $page->items), $page->meta());
    }

    public function store(AddListEntryRequest $request, string $list, AddListEntry $add): JsonResponse
    {
        $entry = $add->handle(self::listNamed($list), new ListEntry(
            $request->string('code')->value(),
            $request->string('label_en')->value(),
            $request->string('label_ar')->value(),
            $request->integer('position'),
        ));

        return ApiEnvelope::single($request, ['entry' => self::payload($entry)], 201);
    }

    public function destroy(Request $request, string $list, string $code, ArchiveListEntry $archive): JsonResponse
    {
        if (! $archive->handle(self::listNamed($list), $code)) {
            // An entry already archived and one that never existed are the same
            // answer — `OpenAPI §5.1`: "do not reveal which case applies".
            throw new NotFoundHttpException;
        }

        // 200 and not 204, and `archived` and not `deleted`, both for the
        // reasons `RoleController::destroy` writes out: §3.3 puts a request id
        // on every response and §4.1 puts it in `meta`, which a 204 has no body
        // to carry; and `DB-01` soft-deletes, so a field saying `deleted` would
        // be the API telling the SPA something untrue about storage.
        return ApiEnvelope::single($request, ['archived' => true, 'list' => $list, 'code' => $code]);
    }

    public function restore(Request $request, string $list, string $code, RestoreListEntry $restore): JsonResponse
    {
        try {
            // An entry already live and one that never existed are the same
            // answer — `OpenAPI §5.1`: "do not reveal which case applies".
            if (! $restore->handle(self::listNamed($list), $code)) {
                throw new NotFoundHttpException;
            }
        } catch (ListEntryAlreadyExists) {
            // `OpenAPI §5.1` — 409 `state_transition_invalid`: "Requested state
            // change violates the documented workflow." The request is
            // well-formed and names a real archived entry; it is the world that
            // moved, because the code it wants back was taken while it was
            // away. Not a 422: no field the caller sent is wrong, and there is
            // no field to name in `details`.
            return ApiEnvelope::error(
                $request,
                409,
                'state_transition_invalid',
                (string) __('admin.managed_list.duplicate_code'),
            );
        }

        return ApiEnvelope::single($request, ['restored' => true, 'list' => $list, 'code' => $code]);
    }

    /**
     * `ManagedList`'s four cases are the set, and a fifth list is a migration
     * rather than a setting — the enum's own docblock says why. A name outside
     * them is a 404 for the reason `CurrencyController` gives: the path names a
     * resource that is not there.
     */
    private static function listNamed(string $list): ManagedList
    {
        $managed = ManagedList::tryFrom($list);

        if (! $managed instanceof ManagedList) {
            throw new NotFoundHttpException;
        }

        return $managed;
    }

    /** @return array<string, mixed> */
    private static function payload(ListEntry $entry): array
    {
        return [
            'code' => $entry->code(),

            // Both, always. §14.2 requires Arabic and English from the first
            // release and the caller renders whichever the locale asks for —
            // there is no version of this response that omits one.
            'label_en' => $entry->labelEn(),
            'label_ar' => $entry->labelAr(),
            'position' => $entry->position(),
        ];
    }
}
