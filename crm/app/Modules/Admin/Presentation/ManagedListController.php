<?php

declare(strict_types=1);

namespace App\Modules\Admin\Presentation;

use App\Modules\Admin\Application\Reference\AddListEntry;
use App\Modules\Admin\Domain\Contracts\ManagedListRepositoryInterface;
use App\Modules\Admin\Domain\Listing\ListingQuery;
use App\Modules\Admin\Domain\Reference\ListEntry;
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
        $page = $lists->page(self::listNamed($list), ListingQuery::fromQueryString($request->query()));

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
