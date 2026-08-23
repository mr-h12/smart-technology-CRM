<?php

declare(strict_types=1);

namespace App\Providers;

use App\Modules\Audit\Application\AuditRecorder;
use App\Modules\Audit\Domain\Contracts\AuditContextResolverInterface;
use App\Modules\Audit\Domain\Contracts\AuditEntryWriterInterface;
use App\Modules\Audit\Domain\Contracts\AuditPartitionsInterface;
use App\Modules\Audit\Domain\Contracts\AuditRecorderInterface;
use App\Modules\Audit\Infrastructure\DatabaseAuditEntries;
use App\Modules\Audit\Infrastructure\PostgresAuditPartitions;
use App\Modules\Audit\Infrastructure\RequestAuditContext;
use App\Modules\Identity\Domain\Authentication\AccountLocked;
use App\Modules\Identity\Domain\Contracts\AccountDirectoryInterface;
use App\Modules\Identity\Domain\Contracts\ProfileReaderInterface;
use App\Modules\Identity\Domain\Contracts\SessionStoreInterface;
use App\Modules\Identity\Infrastructure\BearerSessionResolver;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Modules\Identity\Infrastructure\EloquentAccountDirectory;
use App\Modules\Identity\Infrastructure\EloquentProfileReader;
use App\Modules\Identity\Infrastructure\EloquentSessionStore;
use App\Modules\Identity\Infrastructure\Notifications\NotifySuperAdminOfLockout;
use App\Modules\Storage\Domain\Contracts\AttachmentPermissionInterface;
use App\Modules\Storage\Domain\Contracts\FileRepositoryInterface;
use App\Modules\Storage\Domain\Contracts\StorageServiceInterface;
use App\Modules\Storage\Domain\Contracts\UploadValidatorInterface;
use App\Modules\Storage\Domain\Contracts\VirusScannerInterface;
use App\Modules\Storage\Infrastructure\ClamAvScanner;
use App\Modules\Storage\Infrastructure\DatabaseFileRepository;
use App\Modules\Storage\Infrastructure\DenyAllAttachmentPermission;
use App\Modules\Storage\Infrastructure\EicarSignatureScanner;
use App\Modules\Storage\Infrastructure\FinfoUploadValidator;
use App\Modules\Storage\Infrastructure\LocalStorageService;
use App\Support\Database\StandardColumns;
use App\Support\Database\TestingDatabaseGuard;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Config\Repository as ConfigRepository;
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

        $this->app->bind(
            FileRepositoryInterface::class,
            fn (): DatabaseFileRepository => new DatabaseFileRepository(
                $this->app->make(ConnectionInterface::class),
            ),
        );

        // D-38 cannot be answered yet: the permission matrix is Module 1 and the
        // parent entities are Modules 5, 6, 10 and 13. The binding that ships
        // therefore denies everything. Replacing this line is how those modules
        // switch the download endpoint on — and until one of them does, a
        // failing download is the honest report of an unfinished feature.
        $this->app->bind(AttachmentPermissionInterface::class, DenyAllAttachmentPermission::class);

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
        $this->registerLoginRateLimiter();

        // SEC-03's second half. Registered explicitly because Laravel discovers
        // listeners in app/Listeners and nowhere else, and AP-02 keeps a
        // module's listeners inside the module — the same reason every module
        // command is named in bootstrap/app.php.
        Event::listen(
            AccountLocked::class,
            fn (AccountLocked $event): null => $this->app->make(NotifySuperAdminOfLockout::class)->handle($event),
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
