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
use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Database\ConnectionInterface;
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
    }
}
