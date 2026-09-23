<?php

declare(strict_types=1);

namespace App\Providers;

use App\Modules\Admin\Domain\Contracts\CurrencyRepositoryInterface;
use App\Modules\Admin\Domain\Contracts\FxRateRepositoryInterface;
use App\Modules\Admin\Domain\Contracts\ManagedListRepositoryInterface;
use App\Modules\Admin\Domain\Contracts\SettingsCacheInterface;
use App\Modules\Admin\Domain\Contracts\SettingsRepositoryInterface;
use App\Modules\Admin\Domain\Contracts\SystemLimitRepositoryInterface;
use App\Modules\Admin\Infrastructure\DatabaseSettingReader;
use App\Modules\Admin\Infrastructure\DatabaseSettingsRepository;
use App\Modules\Admin\Infrastructure\DatabaseSystemLimitRepository;
use App\Modules\Admin\Infrastructure\EloquentCurrencyRepository;
use App\Modules\Admin\Infrastructure\EloquentFxRateRepository;
use App\Modules\Admin\Infrastructure\EloquentManagedListRepository;
use App\Modules\Admin\Infrastructure\SettingsCache;
use App\Modules\Audit\Application\AuditRecorder;
use App\Modules\Audit\Domain\Contracts\AuditContextResolverInterface;
use App\Modules\Audit\Domain\Contracts\AuditEntryReaderInterface;
use App\Modules\Audit\Domain\Contracts\AuditEntryWriterInterface;
use App\Modules\Audit\Domain\Contracts\AuditPartitionsInterface;
use App\Modules\Audit\Domain\Contracts\AuditRecorderInterface;
use App\Modules\Audit\Infrastructure\DatabaseAuditEntries;
use App\Modules\Audit\Infrastructure\DatabaseAuditEntryReader;
use App\Modules\Audit\Infrastructure\PostgresAuditPartitions;
use App\Modules\Audit\Infrastructure\RequestAuditContext;
use App\Modules\Catalog\Application\Writing\ProvisionCatalogProduct;
use App\Modules\Catalog\Domain\Contracts\CatalogItemDirectoryInterface;
use App\Modules\Catalog\Domain\Contracts\CatalogItemLabelsInterface;
use App\Modules\Catalog\Domain\Contracts\CatalogProductProvisionerInterface;
use App\Modules\Catalog\Infrastructure\EloquentCatalogItemDirectory;
use App\Modules\Catalog\Infrastructure\EloquentCatalogItemLabels;
use App\Modules\Customers\Domain\Contracts\CustomerDirectoryInterface;
use App\Modules\Customers\Domain\Contracts\CustomerNamesInterface;
use App\Modules\Customers\Domain\Contracts\CustomerStatusWriterInterface;
use App\Modules\Customers\Domain\Contracts\CustomerTaxStatusInterface;
use App\Modules\Customers\Domain\Contracts\ImportBatchesInterface;
use App\Modules\Customers\Infrastructure\EloquentCustomerDirectory;
use App\Modules\Customers\Infrastructure\EloquentCustomerNames;
use App\Modules\Customers\Infrastructure\EloquentCustomerStatusWriter;
use App\Modules\Customers\Infrastructure\EloquentCustomerTaxStatus;
use App\Modules\Customers\Infrastructure\EloquentImportBatches;
use App\Modules\Deals\Application\Access\DealAttachmentPermission;
use App\Modules\Deals\Application\Approval\RecordQuotationOutcome;
use App\Modules\Deals\Domain\Contracts\DealDirectoryInterface;
use App\Modules\Deals\Domain\Contracts\DealFactsInterface;
use App\Modules\Deals\Domain\Contracts\DealOutcomeInterface;
use App\Modules\Deals\Domain\Contracts\DealTitlesInterface;
use App\Modules\Deals\Infrastructure\EloquentDealDirectory;
use App\Modules\Deals\Infrastructure\EloquentDealFacts;
use App\Modules\Idempotency\Domain\IdempotencyStoreInterface;
use App\Modules\Idempotency\Infrastructure\DatabaseIdempotencyStore;
use App\Modules\Identity\Application\Rbac\AuthorizeAction;
use App\Modules\Identity\Domain\Authentication\AccountLocked;
use App\Modules\Identity\Domain\Authentication\PasswordChallengeIssued;
use App\Modules\Identity\Domain\Contracts\AccountDirectoryInterface;
use App\Modules\Identity\Domain\Contracts\PasswordChallengeStoreInterface;
use App\Modules\Identity\Domain\Contracts\PermissionRepositoryInterface;
use App\Modules\Identity\Domain\Contracts\ProfileReaderInterface;
use App\Modules\Identity\Domain\Contracts\RoleDirectoryInterface;
use App\Modules\Identity\Domain\Contracts\SessionStoreInterface;
use App\Modules\Identity\Domain\Contracts\UserDirectoryInterface;
use App\Modules\Identity\Domain\Contracts\UserFactsInterface;
use App\Modules\Identity\Infrastructure\BearerSessionResolver;
use App\Modules\Identity\Infrastructure\CachePasswordChallengeStore;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Modules\Identity\Infrastructure\EloquentAccountDirectory;
use App\Modules\Identity\Infrastructure\EloquentPermissionRepository;
use App\Modules\Identity\Infrastructure\EloquentProfileReader;
use App\Modules\Identity\Infrastructure\EloquentRoleDirectory;
use App\Modules\Identity\Infrastructure\EloquentSessionStore;
use App\Modules\Identity\Infrastructure\EloquentUserDirectory;
use App\Modules\Identity\Infrastructure\EloquentUserFacts;
use App\Modules\Identity\Infrastructure\Notifications\NotifySuperAdminOfLockout;
use App\Modules\Identity\Infrastructure\Notifications\SendPasswordChallenge;
use App\Modules\Identity\Presentation\RbacGateRegistrar;
use App\Modules\Pdf\Domain\Contracts\PdfRendererInterface;
use App\Modules\Pdf\Infrastructure\BrowsershotPdfRenderer;
use App\Modules\Quotations\Domain\Contracts\QuotationDirectoryInterface;
use App\Modules\Quotations\Domain\Contracts\QuotationReaderInterface;
use App\Modules\Quotations\Infrastructure\EloquentQuotationDirectory;
use App\Modules\Storage\Application\ParentAwareAttachmentPermission;
use App\Modules\Storage\Domain\AttachmentParent;
use App\Modules\Storage\Domain\Contracts\AttachmentPermissionInterface;
use App\Modules\Storage\Domain\Contracts\FileRepositoryInterface;
use App\Modules\Storage\Domain\Contracts\FileWriterInterface;
use App\Modules\Storage\Domain\Contracts\StorageServiceInterface;
use App\Modules\Storage\Domain\Contracts\UploadValidatorInterface;
use App\Modules\Storage\Domain\Contracts\VirusScannerInterface;
use App\Modules\Storage\Infrastructure\ClamAvScanner;
use App\Modules\Storage\Infrastructure\DatabaseFileRepository;
use App\Modules\Storage\Infrastructure\DatabaseFileWriter;
use App\Modules\Storage\Infrastructure\EicarSignatureScanner;
use App\Modules\Storage\Infrastructure\FinfoUploadValidator;
use App\Modules\Storage\Infrastructure\LocalStorageService;
use App\Modules\SupplierQuotations\Application\Access\SupplierQuotationAttachmentPermission;
use App\Modules\SupplierQuotations\Domain\Contracts\SupplierItemPricingInterface;
use App\Modules\SupplierQuotations\Domain\Contracts\SupplierItemQuantityInterface;
use App\Modules\SupplierQuotations\Domain\Contracts\SupplierQuotationDirectoryInterface;
use App\Modules\SupplierQuotations\Infrastructure\EloquentSupplierItemPricing;
use App\Modules\SupplierQuotations\Infrastructure\EloquentSupplierItemQuantity;
use App\Modules\SupplierQuotations\Infrastructure\EloquentSupplierQuotationDirectory;
use App\Modules\Suppliers\Domain\Contracts\SupplierDirectoryInterface;
use App\Modules\Suppliers\Domain\Contracts\SupplierLookupInterface;
use App\Modules\Suppliers\Infrastructure\EloquentSupplierDirectory;
use App\Modules\Suppliers\Infrastructure\EloquentSupplierLookup;
use App\Support\Database\StandardColumns;
use App\Support\Database\TestingDatabaseGuard;
use App\Support\Search\PostgresSearchDriver;
use App\Support\Search\SearchService;
use App\Support\Settings\SettingReader;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // AUD-01's seam. Every module records through the interface, so the
        // day a mutation stops being audited it is one binding that changed
        // rather than a call site somebody forgot.
        //
        // bind, not singleton, and for a measured reason: the resolver reads
        // the *current* request, and a singleton recorder would capture
        // whichever request happened to be in flight when it was first built.
        // Point 5.4 already paid for that lesson once — Route::getController()
        // memoised a controller and its injected policy outlived the request,
        // so a denial answered 200 because the previous allow was still in
        // scope.
        $this->app->bind(
            AuditContextResolverInterface::class,
            fn (): RequestAuditContext => new RequestAuditContext($this->app),
        );

        $this->app->bind(
            AuditEntryWriterInterface::class,
            fn (): DatabaseAuditEntries => new DatabaseAuditEntries(
                $this->app->make(ConnectionInterface::class),
            ),
        );

        // Module 5 Point 5.1 — the read half. `AUD-03` refuses UPDATE, DELETE
        // and TRUNCATE on this table; it has never refused a SELECT, and
        // §3.4's `deal.view_timeline` has been seeded to seven roles with no
        // route able to check it since Module 1.
        $this->app->bind(
            AuditEntryReaderInterface::class,
            fn (): DatabaseAuditEntryReader => new DatabaseAuditEntryReader(
                $this->app->make(ConnectionInterface::class),
            ),
        );

        $this->app->bind(
            AuditRecorderInterface::class,
            fn (): AuditRecorder => new AuditRecorder(
                $this->app->make(AuditContextResolverInterface::class),
                $this->app->make(AuditEntryWriterInterface::class),
            ),
        );

        // J-15's only seam. Bound rather than newed in the command, so the
        // decision half (EnsureAuditPartitions) never names PostgreSQL and can
        // be exercised without one.
        $this->app->bind(
            AuditPartitionsInterface::class,
            fn (): PostgresAuditPartitions => new PostgresAuditPartitions(
                $this->app->make(ConnectionInterface::class),
            ),
        );

        // §14.2 puts the local file system behind an abstraction layer, and the
        // binding is the layer's only seam: callers ask for the interface, so
        // swapping the driver later is one line here rather than a search
        // through every module that ever stored a file.
        //
        // The disk is resolved through the Factory contract rather than the
        // Storage facade, because StorageServiceTest forbids that facade
        // everywhere outside the driver — including here.
        // Module 3 Point 3.2. Plain `bind`: the directory is stateless and its
        // two collaborators are resolved per request anyway, so a singleton
        // would buy nothing and would outlive a swapped `SearchService` in a
        // test.
        $this->app->bind(
            CustomerDirectoryInterface::class,
            fn (): EloquentCustomerDirectory => new EloquentCustomerDirectory(
                $this->app->make(SearchService::class),
                $this->app->make(UserDirectoryInterface::class),
            ),
        );

        // Module 3 Point 3.6. `bind` for the same reason as the directory
        // above: stateless, and a singleton would outlive nothing useful.
        $this->app->bind(ImportBatchesInterface::class, EloquentImportBatches::class);

        // Module 5 Point 3.1. `bind` for the same reason as its siblings:
        // stateless, and this write is a single conditional `UPDATE` with no
        // per-request collaborator to resolve.
        $this->app->bind(CustomerStatusWriterInterface::class, EloquentCustomerStatusWriter::class);

        // Module 7 Point 3.3. `bind` for the same reason: stateless, a single
        // column read by primary key. Module 7 reads `customers.is_tax_exempt`
        // through this to derive a quotation's tax line (`D-63`).
        $this->app->bind(CustomerTaxStatusInterface::class, EloquentCustomerTaxStatus::class);

        // F-07 · 1.2 (`D-83`). `bind` for the same reason: stateless, one
        // `whereIn` on one table. Module 7 labels its rows through this —
        // the name only, unfiltered by archive, deletion or scope.
        $this->app->bind(CustomerNamesInterface::class, EloquentCustomerNames::class);

        // Module 7 Point 3.7. `bind` for the same reason: stateless, three
        // statements on one table. `OpenAPI §9.1`'s store, consumed by the
        // `idempotency` route middleware and by no module directly.
        $this->app->bind(IdempotencyStoreInterface::class, DatabaseIdempotencyStore::class);

        // Module 4 Point 2.1. `bind` for the same reasons again, and with one
        // collaborator rather than two: §3.7 gives suppliers no row scope, so
        // there is no Identity contract to ask about owners.
        $this->app->bind(
            SupplierDirectoryInterface::class,
            fn (): EloquentSupplierDirectory => new EloquentSupplierDirectory(
                $this->app->make(SearchService::class),
            ),
        );

        // F-10 · 1.4 — `D-86`'s supplier cell, published for Catalog's import.
        $this->app->bind(SupplierLookupInterface::class, EloquentSupplierLookup::class);

        // Module 4 Point 3.1. `bind` for the same reasons as the supplier
        // directory above, and with the same single collaborator: §3.7 covers
        // the catalog and its suppliers alike, and gives neither a row scope.
        $this->app->bind(
            CatalogItemDirectoryInterface::class,
            fn (): EloquentCatalogItemDirectory => new EloquentCatalogItemDirectory(
                $this->app->make(SearchService::class),
            ),
        );

        // Module 6 Point 3.1 — `D-22`'s automatic product add, published by
        // Catalog because the product is added to the catalog. `bind` for the
        // reason every directory here is bound: stateless, and a singleton
        // would outlive nothing useful. The concrete class is resolved rather
        // than constructed by hand so `SaveCatalogItem`'s five collaborators
        // stay its own business.
        $this->app->bind(CatalogProductProvisionerInterface::class, ProvisionCatalogProduct::class);

        // F-16 · 1.1 — a quotation line's product name, read by Quotations.
        $this->app->bind(CatalogItemLabelsInterface::class, EloquentCatalogItemLabels::class);

        // Module 5 Points 2.2–2.3. `bind` for the reason
        // `CustomerDirectoryInterface` is: stateless, and a singleton would
        // outlive nothing useful. `ConnectionInterface` added with Point 2.3 —
        // `document_sequences`' atomic upsert (§4.7) is issued from here.
        $this->app->bind(
            DealDirectoryInterface::class,
            fn (): EloquentDealDirectory => new EloquentDealDirectory(
                $this->app->make(SearchService::class),
                $this->app->make(ConnectionInterface::class),
            ),
        );

        // Module 6 Point 1.3. `bind` for the reason every directory above is
        // bound rather than shared: stateless, and a singleton would outlive
        // nothing useful. **One collaborator, not two** — `ConnectionInterface`
        // for `document_sequences`' atomic upsert (§4.7), and no `SearchService`
        // because nothing searches supplier quotations until Step 4.
        $this->app->bind(
            SupplierQuotationDirectoryInterface::class,
            fn (): EloquentSupplierQuotationDirectory => new EloquentSupplierQuotationDirectory(
                $this->app->make(ConnectionInterface::class),
            ),
        );

        // Module 7 Point 3.3. The read §5.6 forces on customer quotations — one
        // supplier line's price, by its id. `bind` for the reason above, and
        // `ConnectionInterface` alone because it is a query-builder read over
        // two tables, not a hydrated model.
        $this->app->bind(
            SupplierItemPricingInterface::class,
            fn (): EloquentSupplierItemPricing => new EloquentSupplierItemPricing(
                $this->app->make(ConnectionInterface::class),
            ),
        );

        // F-05 · 1.3 (`D-81`). The write Module 10's `accepted` transition will
        // make — one supplier line's balance, by its id. Same shape as the read
        // above, for the same reason.
        $this->app->bind(
            SupplierItemQuantityInterface::class,
            fn (): EloquentSupplierItemQuantity => new EloquentSupplierItemQuantity(
                $this->app->make(ConnectionInterface::class),
            ),
        );

        // Module 7 Point 3.4. The read a scoped `quotation.create` forces on a
        // deal — its owner (the owner's 2026-09-11 ruling: a quotation's "own"
        // is its deal's `owner_id`) and its customer, which the quotation's own
        // `customer_id` must match. `bind`, as for the supplier price above:
        // columns by primary key. `SearchService` joined with `D-88` (F-13 · 1.2)
        // for the code fragment, which passes through it like every search.
        $this->app->bind(
            DealFactsInterface::class,
            fn (): EloquentDealFacts => new EloquentDealFacts(
                $this->app->make(ConnectionInterface::class),
                $this->app->make(SearchService::class),
            ),
        );

        // Module 9 · 2.3: the same reader answers `DealTitlesInterface` — one
        // class over `deals`, a separate contract so Module 6's fakes of
        // `DealFactsInterface` keep working.
        $this->app->bind(
            DealTitlesInterface::class,
            fn (): EloquentDealFacts => $this->app->make(DealFactsInterface::class),
        );

        // Module 10 · 1.2 (`D-90`): the two deal moves a quotation causes.
        $this->app->bind(DealOutcomeInterface::class, RecordQuotationOutcome::class);

        // Module 7 Point 6.4 (Step 6 Q2). The name behind a deal owner's id for
        // `group_by=employee`'s label — `bind` and `ConnectionInterface` alone,
        // as for the deal facts above: two columns by primary key.
        $this->app->bind(
            UserFactsInterface::class,
            fn (): EloquentUserFacts => new EloquentUserFacts(
                $this->app->make(ConnectionInterface::class),
            ),
        );

        // Module 7 Point 1.7. `bind` and one collaborator, for the reasons the
        // two directories above give: stateless, so a singleton would outlive
        // nothing useful, and `ConnectionInterface` alone because the only
        // thing this directory needs beyond its own model is
        // `document_sequences`' atomic upsert (§4.7, Point 1.6). `SearchService`
        // for the purchase orders' `q` (Module 10 · 2.2).
        $this->app->bind(
            QuotationDirectoryInterface::class,
            fn (): EloquentQuotationDirectory => new EloquentQuotationDirectory(
                $this->app->make(ConnectionInterface::class),
                $this->app->make(DealFactsInterface::class),
                $this->app->make(CurrencyRepositoryInterface::class),
                $this->app->make(SearchService::class),
            ),
        );

        // F-14 · 1.1. The read-only half, resolved through the directory's own
        // binding above so a module granted only `QuotationsContract` gets the
        // same `find()` rather than a second implementation of it.
        $this->app->bind(
            QuotationReaderInterface::class,
            fn (): QuotationDirectoryInterface => $this->app->make(QuotationDirectoryInterface::class),
        );

        $this->app->singleton(
            StorageServiceInterface::class,
            fn (): LocalStorageService => new LocalStorageService(
                $this->app->make(FilesystemFactory::class)->disk('secure_uploads'),
            ),
        );

        // bind, not singleton: the ceiling is configuration (D-71, AP-08), and a
        // singleton would freeze whatever it read the first time. Module 2 moves
        // the value into the settings table, where it changes while the process
        // is running — a cached copy would then be silently stale.
        $this->app->bind(
            UploadValidatorInterface::class,
            fn (): FinfoUploadValidator => new FinfoUploadValidator(
                // ->integer() rather than ->get() with a cast: the cast is where a
                // misconfigured string quietly becomes 0, and a ceiling of 0
                // rejects every upload with a message about size.
                $this->app->make(ConfigRepository::class)->integer('files.max_size_bytes'),
            ),
        );

        // `D-48`'s seam. **Binding is the whole point of this line**: Module 15
        // replaces the driver here and every caller — which asked for the
        // interface — keeps working. `singleton`, because the driver holds only
        // the connection and decides nothing that can go stale.
        $this->app->singleton(
            SearchService::class,
            fn (): PostgresSearchDriver => new PostgresSearchDriver(
                $this->app->make(ConnectionInterface::class),
            ),
        );

        $this->app->bind(
            FileRepositoryInterface::class,
            fn (): DatabaseFileRepository => new DatabaseFileRepository(
                $this->app->make(ConnectionInterface::class),
            ),
        );

        $this->app->bind(
            FileWriterInterface::class,
            fn (): DatabaseFileWriter => new DatabaseFileWriter(
                $this->app->make(ConnectionInterface::class),
            ),
        );

        // §5.3's currencies and their rounding units, read from the table.
        // bind and not singleton, for PermissionRepositoryInterface's reason:
        // AP-08 makes the unit configuration, and an instance memoised for the
        // life of the process is a deployment wearing a different name.
        // PRF-08's cache. **singleton, and it is the one binding in this module
        // that may be** — it holds no answer of its own, only the cache store's
        // handle, so memoising it memoises nothing. The values behind it are
        // still invalidated on every write.
        $this->app->singleton(SettingsCacheInterface::class, SettingsCache::class);

        $this->app->bind(CurrencyRepositoryInterface::class, EloquentCurrencyRepository::class);

        // §13 screen 5's rate history. bind and not singleton for the reason
        // above, with one of its own: `AP-06` makes this table append-only, so
        // a memoised instance is a process answering with a history that has
        // since grown.
        $this->app->bind(FxRateRepositoryInterface::class, EloquentFxRateRepository::class);

        // DB-05's lists, read from the table. Same reasoning: the acceptance
        // criterion is a new sector appearing without a deployment, which is
        // false the moment anything answers this from ManagedLists.
        $this->app->bind(ManagedListRepositoryInterface::class, EloquentManagedListRepository::class);

        // §13 screen 4's fields. bind for the same reason as the rest: a
        // settings screen whose answers are memoised is a settings screen that
        // needs a deployment to take effect.
        $this->app->bind(
            SettingsRepositoryInterface::class,
            fn (): DatabaseSettingsRepository => new DatabaseSettingsRepository(
                $this->app->make(ConnectionInterface::class),
                $this->app->make(SettingsCache::class),
            ),
        );

        // §13 screen 6's limits. A separate binding from the settings one
        // because §3.11 makes them two permissions and Point 1.1 made them two
        // tables; bind and not singleton for the reason above.
        $this->app->bind(
            SystemLimitRepositoryInterface::class,
            fn (): DatabaseSystemLimitRepository => new DatabaseSystemLimitRepository(
                $this->app->make(ConnectionInterface::class),
                $this->app->make(SettingsCache::class),
            ),
        );

        // D-75's limit reader. bind, not singleton — AP-08 makes the value
        // changeable without a deployment, and an instance holding an answer
        // for the life of the process is a deployment by another name.
        $this->app->bind(
            SettingReader::class,
            fn (): DatabaseSettingReader => new DatabaseSettingReader(
                $this->app->make(SystemLimitRepositoryInterface::class),
                $this->app->make(ConfigRepository::class),
            ),
        );

        // D-38, for the first of the four parents to exist. `DenyAllAttachmentPermission`
        // shipped because the permission matrix was Module 1 and every parent
        // entity was still Modules 5, 6, 10 and 13 — its own docblock named
        // "replacing this line" as how a parent module switches the download
        // endpoint on. Deals Point 4.1 is that replacement: `DealAttachmentPermission`
        // answers for `AttachmentParent::Deal` and still denies the other three,
        // which do not exist yet — a failing download for one of those remains
        // the honest report of an unfinished feature.
        // Module 6 Point 5.1 is the second parent, and the crossing
        // `DealAttachmentPermission`'s docblock left open: "Whoever builds the
        // second parent's permission decides then whether this class grows a
        // `match` or a composite replaces it." A composite, because a `match`
        // would put §3.6's rule inside Module 5. Each module keeps its own
        // answer; this map is the only place that has to know about both, and
        // it is already outside every module boundary.
        //
        // `PurchaseOrder` and `Report` have no entry and are refused by the
        // composite — `DenyAllAttachmentPermission`'s deny-by-default kept for
        // the two parents whose modules are still `.gitkeep`.
        $this->app->bind(
            AttachmentPermissionInterface::class,
            fn (): AttachmentPermissionInterface => new ParentAwareAttachmentPermission([
                AttachmentParent::Deal->value => $this->app->make(DealAttachmentPermission::class),
                AttachmentParent::SupplierQuotation->value => $this->app->make(SupplierQuotationAttachmentPermission::class),
            ]),
        );

        // SEC-15. `bind` and not `singleton` for the same reason as the
        // validator: the choice is configuration, and a cached instance would
        // freeze whichever driver the first resolution happened to see.
        $this->app->bind(VirusScannerInterface::class, function (): VirusScannerInterface {
            $config = $this->app->make(ConfigRepository::class);

            if ($config->string('files.scanner') === 'clamav') {
                return new ClamAvScanner(
                    $config->string('files.clamav.host'),
                    $config->integer('files.clamav.port'),
                    $config->integer('files.clamav.timeout'),
                );
            }

            return new EicarSignatureScanner;
        });

        // Module 1's three seams. `bind` and not `singleton` for the reason
        // the audit recorder is bound that way: each reads live rows, and an
        // instance cached across requests is an authorisation answer that
        // stops changing when the matrix does (§3.12 rule 5).
        $this->app->bind(AccountDirectoryInterface::class, EloquentAccountDirectory::class);
        $this->app->bind(SessionStoreInterface::class, EloquentSessionStore::class);
        $this->app->bind(ProfileReaderInterface::class, EloquentProfileReader::class);

        // SEC-07's matrix reader. `bind`, and this one matters more than the
        // rest: the repository memoises within a request, so a singleton would
        // hold a permission answer for the life of the process — §3.12 rule 5
        // says removing a grant takes effect without a deployment, and a cached
        // instance is a deployment wearing a different name.
        $this->app->bind(
            PermissionRepositoryInterface::class,
            fn (): EloquentPermissionRepository => new EloquentPermissionRepository(
                $this->app->make(ConnectionInterface::class),
            ),
        );

        // §3.11's administration reads and writes. bind and not singleton: it
        // holds no state worth keeping, and a listing memoised across requests
        // is a deactivation that has not happened yet as far as the next caller
        // can tell.
        $this->app->bind(UserDirectoryInterface::class, EloquentUserDirectory::class);

        // §3.11's "create / edit role · permissions", and §3.12 rule 5's
        // promise that changing the matrix takes effect without a deployment.
        // bind and not singleton, and here that is the requirement rather than
        // a preference: a directory that cached its reads would answer the
        // request after a grant change with the matrix from before it, which is
        // exactly the "wait instead of a deployment" rule 5 rules out.
        $this->app->bind(RoleDirectoryInterface::class, EloquentRoleDirectory::class);

        // SEC-04's outstanding challenge. The cache and not a table: a
        // fifteen-minute secret fits neither DB-01's soft delete nor DB-02's
        // audit columns, and Redis expires it without a sweeper job. See
        // PasswordChallengeStoreInterface for the trade-off that accepts.
        $this->app->bind(PasswordChallengeStoreInterface::class, CachePasswordChallengeStore::class);

        // Module 9, Point 2.1 — the PDF renderer (D-57). Only the pdf image has
        // the browser it points at; anywhere else it refuses by name.
        $this->app->bind(
            PdfRendererInterface::class,
            fn (): PdfRendererInterface => new BrowsershotPdfRenderer(
                chromePath: $this->app->make(ConfigRepository::class)->string('pdf.chrome_path'),
                nodeModulesPath: $this->app->make(ConfigRepository::class)->string('pdf.node_modules_path'),
                timeoutSeconds: $this->app->make(ConfigRepository::class)->integer('pdf.timeout_seconds'),
            ),
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // First, before anything can touch a connection. DEV-01 keeps the
        // environments apart; Laravel's silent .env fallback does not, so a
        // testing-mode boot aimed at the development database stops here.
        TestingDatabaseGuard::enforce($this->app);

        // DB-01 and DB-02 apply to every business table, so the columns are a
        // macro rather than something each migration remembers to repeat.
        StandardColumns::register();

        $this->registerBearerSessionGuard();

        // SEC-07 behind Laravel's own Gate, so `$user->can('customer.view')`
        // answers from `role_permissions`. Resolved from the container at call
        // time rather than captured, for the reason Point 5.4 measured.
        RbacGateRegistrar::register(
            $this->app->make(Gate::class),
            $this->app->make(AuthorizeAction::class),
        );

        $this->registerLoginRateLimiter();
        $this->registerPasswordChallengeRateLimiter();

        // SEC-03's second half. Registered explicitly because Laravel discovers
        // listeners in app/Listeners and nowhere else, and AP-02 keeps a
        // module's listeners inside the module — the same reason every module
        // command is named in bootstrap/app.php.
        Event::listen(
            AccountLocked::class,
            fn (AccountLocked $event): null => $this->app->make(NotifySuperAdminOfLockout::class)->handle($event),
        );

        // SEC-04's mail. Synchronous on purpose — PasswordChallengeIssued
        // carries the plaintext code, and a queued listener would write it into
        // a job payload sitting in Redis.
        Event::listen(
            PasswordChallengeIssued::class,
            fn (PasswordChallengeIssued $event): null => $this->app->make(SendPasswordChallenge::class)->handle($event),
        );
    }

    /**
     * `OpenAPI §3.1`'s "server-issued bearer credential", as the `api` guard.
     *
     * `viaRequest` rather than a hand-written Guard class: the whole of the
     * decision is "which user does this request belong to", which is one
     * method, and `RequestGuard` already supplies the rest of the contract —
     * including `actingAs()`, which every later test depends on.
     *
     * The callback is resolved from the container on each request rather than
     * captured, for the reason Point 5.4 measured: a service captured once
     * outlives the request that built it, and an authorisation-bearing object
     * frozen at the first request is a permission check that stops changing.
     */
    private function registerBearerSessionGuard(): void
    {
        Auth::viaRequest(
            'crm-bearer-session',
            fn (Request $request): ?User => $this->app->make(BearerSessionResolver::class)->forRequest($request),
        );
    }

    /**
     * `SEC-11` — "Rate limiting on login and the API".
     *
     * Keyed on IP **and** submitted address together. IP alone locks a whole
     * office out from behind one NAT address; address alone is defeated by a
     * rotating source. The limit itself is configuration (`OpenAPI §10`:
     * "configurable system settings, not client constants"), which Module 2
     * moves into the settings table.
     */
    /**
     * `SEC-11` on `SEC-04`'s challenge endpoint.
     *
     * Keyed by the **authenticated account**, not by IP. The caller has already
     * proved who they are, so the account is the unit worth bounding; an IP key
     * would let one person in the office exhaust everyone else's allowance from
     * behind the same NAT address. Falling back to the IP covers the case the
     * route makes impossible — an unauthenticated request — rather than keying
     * every such request together under one bucket.
     */
    private function registerPasswordChallengeRateLimiter(): void
    {
        RateLimiter::for('password-challenge', function (Request $request): Limit {
            $config = $this->app->make(ConfigRepository::class);

            $user = $request->user();
            $id = $user?->getAuthIdentifier();

            return Limit::perMinutes(
                $config->integer('identity.rate_limit.password_challenge.decay_minutes'),
                $config->integer('identity.rate_limit.password_challenge.attempts'),
            )->by(is_string($id) || is_int($id) ? (string) $id : ($request->ip() ?? 'unknown'));
        });
    }

    private function registerLoginRateLimiter(): void
    {
        RateLimiter::for('login', function (Request $request): Limit {
            $config = $this->app->make(ConfigRepository::class);

            $email = $request->input('email');

            return Limit::perMinutes(
                $config->integer('identity.rate_limit.login.decay_minutes'),
                $config->integer('identity.rate_limit.login.attempts'),
            )->by(($request->ip() ?? 'unknown').'|'.(is_string($email) ? mb_strtolower($email) : ''));
        });
    }
}
