<?php

declare(strict_types=1);

namespace Tests\Feature\Audit;

use App\Modules\Admin\Infrastructure\DatabaseSettingsRepository;
use App\Modules\Admin\Infrastructure\DatabaseSystemLimitRepository;
use App\Modules\Audit\Domain\AuditEvent;
use App\Modules\Audit\Domain\Contracts\AuditRecorderInterface;
use App\Modules\Audit\Infrastructure\DatabaseAuditEntries;
use App\Modules\Customers\Application\Assignment\AssignCustomer;
use App\Modules\Customers\Application\Writing\SaveCustomer;
use App\Modules\Identity\Application\Administration\UpdateUser;
use App\Modules\Identity\Infrastructure\EloquentRoleDirectory;
use App\Modules\Storage\Infrastructure\DatabaseFileRepository;
use App\Modules\Suppliers\Application\Writing\SaveSupplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

/**
 * Point 6.5 — the check that makes an unaudited mutation a build failure.
 *
 * `AUD-01` wants every create, update, delete, approve and transfer recorded.
 * Points 6.1 to 6.4 made that *possible*; nothing made it *unavoidable*, and a
 * requirement that depends on fourteen future modules each remembering is a
 * requirement with a half-life.
 *
 * ── What this test actually enforces, stated plainly ───────────────────────
 *
 * It cannot prove a write was audited — that is a claim about behaviour at
 * runtime across code nobody has written yet. What it can do, and does, is
 * remove the *silent* option:
 *
 * 1. **Every class in `app/Modules` that writes to the database is discovered**
 *    by scanning, not by being declared. The set is then compared against the
 *    register below. A new writer fails this test until somebody says, in this
 *    file, whether it is audited or why it is not.
 * 2. **A writer claiming to be audited must actually reach the recorder** —
 *    the claim is checked against the file, not taken.
 * 3. **A writer that is not audited must carry a reason and an owner.** Debt
 *    is allowed; unrecorded debt is not.
 * 4. **`app/Http`, `app/Support` and `routes` may not write at all.** A
 *    controller that reaches the database directly is outside every boundary
 *    this file can police, and Coding Standards §5 already says controllers
 *    validate, invoke a use case and serialise.
 *
 * And separately, behaviourally: a module needs nothing from the audit module
 * but the interface — no registration, no listener, no edit to
 * `app/Modules/Audit`.
 */
final class AuditEnforcementTest extends TestCase
{
    use RefreshDatabase;

    /** The class is the audit; auditing it would recurse. */
    private const IS_THE_AUDIT = 'IS_THE_AUDIT';

    /** The class reaches AuditRecorderInterface — asserted, not trusted. */
    private const AUDITED = 'AUDITED';

    /**
     * Every class under `app/Modules` that writes to the database, and what it
     * does about `AUD-01`.
     *
     * **Adding a row here is a decision, not a formality.** Anything other than
     * AUDITED or IS_THE_AUDIT is debt, and the string is what the next reader
     * gets instead of an explanation.
     *
     * A method rather than a constant, and not for style: PHPStan reads a
     * constant array's values as literal types, so `$disposition !== AUDITED`
     * became "always true" — correct about today's register and wrong about
     * every future one, which is a level-10 error that would have to be
     * suppressed the moment the first audited writer landed. An explicit
     * `array<class-string, string>` return widens it back.
     *
     * @return array<class-string, string>
     */
    private static function register(): array
    {
        return [
            DatabaseAuditEntries::class => self::IS_THE_AUDIT,

            // Point 3.2. Caught by the scanner on `->update(` beside an
            // imported `ConnectionInterface`: the use case does not touch a
            // table itself — `UserDirectoryInterface` does — but it owns the
            // transaction the write happens in, which is the right place for
            // the register to point. It writes USER_UPDATED and, on a role
            // change, the ROLE_CHANGED entry §3.12 rule 4 makes mandatory.
            UpdateUser::class => self::AUDITED,

            // Module 3 Point 3.3, and the scanner caught it on `->update(`
            // beside an imported `ConnectionInterface` — the same two signals
            // that caught UpdateUser. It owns the transaction and writes
            // CUSTOMER_CREATED and CUSTOMER_UPDATED, which AUD-01 names
            // explicitly among "create/update/delete/approve/transfer".
            //
            // ⚠️ Its persistence adapter, EloquentCustomerDirectory, gained
            // `->save(` in the same point and is **not** listed — because this
            // test still cannot see it. A repository writing purely through a
            // module-aliased Eloquent model carries none of the four signals
            // scan() looks for, exactly as EloquentManagedListRepository does.
            // That hole was six classes at Module 2 Point 3.4; this makes it
            // seven, and it is still owed its own point.
            //
            // ⚠️ Point 3.4 makes it eight. `ArchiveCustomer` owns the archive
            // and restore transaction and writes CUSTOMER_ARCHIVED and §3.12
            // rule 4's ARCHIVE_RESTORED — and is **not** here, because it is
            // not seen either: it calls `setArchived(`, `find(` and `record(`,
            // none of which is a DML verb. Measured, not assumed: this test
            // passes with it unlisted. So the sibling that *is* listed above is
            // listed by the luck of a method name, which is the whole argument
            // for giving this hole its own point.
            SaveCustomer::class => self::AUDITED,

            // Module 3 Point 3.5 — and the hole is exactly as narrow as the
            // note above says. This one **is** seen, because it calls
            // `->update(` beside an imported `ConnectionInterface`, while its
            // sibling ArchiveCustomer calls `->setArchived(` and is not. Two
            // classes doing the same kind of work, one visible on the spelling
            // of a method name. Measured by this test failing on the unlisted
            // class, after a comment here claimed the opposite.
            //
            // It owns Flow 10's transfer transaction and writes §3.12 rule 4's
            // CUSTOMER_REASSIGNED — "transfer" is one of the verbs AUD-01 names.
            //
            // ⚠️ Point 3.6 puts the hole back on show: `ImportCustomers` writes
            // one CUSTOMER_CREATED per imported row and is **not** here, because
            // it is not seen — `->create(`, `->record(` and `->handle(` are none
            // of them the verbs scan() matches. Measured: this test passes with
            // it unlisted, and listing it would fail the identity assertion
            // above instead. So does `EloquentImportBatches`, which writes
            // `import_batches` through a module-aliased Eloquent model.
            AssignCustomer::class => self::AUDITED,

            // Module 4 Point 2.2, and seen for the same two signals as the
            // three above: `->update(` beside an imported `ConnectionInterface`.
            // It owns the create/edit transaction and writes SUPPLIER_CREATED
            // and SUPPLIER_UPDATED, which `AUD-01` names explicitly.
            //
            // `D-45` is why this one is load-bearing rather than routine:
            // §3.7 opens catalog and supplier editing to *every* employee, and
            // the documented mitigation is "every edit is written to the audit
            // log" plus a monthly Team Leader review. The audit is the control.
            //
            // ⚠️ Its persistence adapter, EloquentSupplierDirectory, gained
            // `->save(` in the same point and is **not** listed, for the reason
            // EloquentCustomerDirectory is not: a repository writing purely
            // through a module-aliased Eloquent model carries none of the four
            // signals scan() looks for. Measured, not assumed — this test
            // passes with it unlisted. The hole was eight classes; this makes
            // it nine, and it is still owed its own point.
            SaveSupplier::class => self::AUDITED,

            // Point 4.1. Found by this test, and the register was wrong before
            // it ran: SyncRolePermissions was listed as the writer because it
            // owns the transaction, but the scanner does not see it — it calls
            // `->transaction(`, which is not a DML verb. The grants are written
            // here, so this is the class the register must name.
            //
            // Not AUDITED, because that disposition asserts the class names the
            // recorder and this one must not: the audit write belongs in the
            // use case, inside the same transaction (DB-11), where a second
            // entry point would find it. Every write below reaches the database
            // only through SyncRolePermissions::handle(), which records
            // ROLE_PERMISSIONS_UPDATED with the old and new triple sets — grep
            // `syncGrants(` to check that this is still the only caller.
            EloquentRoleDirectory::class => 'AUD-01 is satisfied one layer out: SyncRolePermissions owns the '
                    .'transaction and records ROLE_PERMISSIONS_UPDATED. This is a persistence adapter with '
                    .'no actor and no event vocabulary, and giving it the recorder would put the audit '
                    .'decision behind an interface any future adapter could answer differently.',

            // Module 2 Point 3.1. The repository writes and does not record;
            // AUD-01 is satisfied one layer out, in UpdateSettings, which owns
            // the transaction and records SETTINGS_UPDATED with the old and new
            // value for each field. Same disposition and same reason as
            // EloquentRoleDirectory below: a persistence adapter has no actor
            // and no event vocabulary, and handing it the recorder would put the
            // audit decision behind an interface a future adapter could answer
            // differently. Grep `->put(` to check UpdateSettings is still the
            // only caller.
            DatabaseSettingsRepository::class => 'AUD-01 is satisfied one layer out: UpdateSettings owns the '
                    .'transaction and records SETTINGS_UPDATED with the old and new value per field. This '
                    .'is a persistence adapter with no actor and no event vocabulary.',

            // Module 2 Point 3.4, and this test found it on its first full run
            // — exactly as it found UpdateSettings in Point 3.1. Same shape and
            // same disposition as the row above: UpdateSystemLimits owns the
            // transaction and records SYSTEM_LIMITS_UPDATED per field.
            //
            // ⚠️ Its sibling this point, EloquentManagedListRepository, gained
            // an insert too and is **not** listed here — because this test
            // cannot see it. A repository writing purely through a
            // module-aliased Eloquent model carries none of the four signals
            // scan() looks for. That hole was measured in Point 3.2 with five
            // classes falling through it; this point makes it six, and it is
            // still owed its own point.
            DatabaseSystemLimitRepository::class => 'AUD-01 is satisfied one layer out: UpdateSystemLimits owns '
                    .'the transaction and records SYSTEM_LIMITS_UPDATED with the old and new value per field. '
                    .'This is a persistence adapter with no actor and no event vocabulary.',

            // Found by this test on its first run, which is the point of it.
            // recordScan() flips files.scan_status from `pending` to `clean` or
            // `infected` (SEC-15, Point 5.5) — a security-relevant state change on
            // a business entity, and AUD-01 lists update among what is recorded.
            // It is not audited, and it is not audited yet rather than by
            // decision: there is no upload endpoint, no actor to record until
            // Module 1, and no event name for it in §3.12. Owner: whoever wires
            // attachments in Module 5, who must either record FILE_SCANNED or say
            // here why a scan result is not an auditable change.
            DatabaseFileRepository::class => 'SEC-15 scan results are written unaudited; owed by Module 5, when an upload '
                    .'endpoint and an actor exist. See CHECKLIST deployment/step debt.',
        ];
    }

    /**
     * A database write, in the query builder or in Eloquent.
     *
     * `create(` is deliberately absent: it is the most common method name in
     * any codebase — `EnsureAuditPartitions` calls `$this->partitions->create()`
     * on a DDL port — and a scanner that flags it teaches people to ignore
     * this test. Eloquent's static `Model::create` is caught by the model
     * signal instead.
     */
    private const DML = [
        '->insert(', '->insertGetId(', '->insertOrIgnore(', '->upsert(',
        '->update(', '->updateOrInsert(', '->delete(', '->forceDelete(',
        '->truncate(', '->increment(', '->decrement(', '->save(',
    ];

    /**
     * What makes a DML verb a *database* write.
     *
     * Without this, `LocalStorageService::delete()` — which removes a file from
     * a disk — is indistinguishable from a row deletion. Measured: it was the
     * scanner's first false positive.
     */
    private const DATABASE = [
        'ConnectionInterface', '->table(', 'DB::', 'Eloquent\\Model',
    ];

    // ────────────────────────────────────────── the register is the boundary

    public function test_every_database_writer_in_a_module_is_accounted_for(): void
    {
        $found = $this->writers();

        self::assertNotSame([], $found,
            'The scanner found no writers at all, which means it is not scanning.');

        $registered = array_keys(self::register());
        sort($registered);
        sort($found);

        self::assertSame($registered, $found,
            'AUD-01: every module class that writes to the database must be listed in '
            ."AuditEnforcementTest::WRITERS, marked AUDITED or given a reason it is not.\n"
            .'Unlisted: '.implode(', ', array_diff($found, $registered))."\n"
            .'Listed but no longer a writer: '.implode(', ', array_diff($registered, $found)));
    }

    public function test_a_writer_claiming_to_be_audited_actually_reaches_the_recorder(): void
    {
        // The AUDITED branch is vacuous today — nothing writes a business row
        // yet, so nothing claims to be audited. A loop over an empty array
        // passes forever and proves nothing, so it was verified by marking
        // DatabaseFileRepository AUDITED on purpose, which produced:
        //   "App\Modules\Storage\Infrastructure\DatabaseFileRepository is
        //    registered AUDITED but never names the recorder."
        //
        // The file check below runs on every entry, so this test does real work
        // in the meantime: it catches a register that has drifted from a
        // renamed or moved class, which is how a register quietly stops
        // describing anything.
        foreach (self::register() as $class => $disposition) {
            self::assertFileExists(self::fileFor($class),
                "The register names {$class}, which has no file.");

            if ($disposition !== self::AUDITED) {
                continue;
            }

            // The claim, checked. A register entry is a note; this is whether
            // the note is true.
            self::assertStringContainsString(
                'AuditRecorderInterface',
                (string) file_get_contents(self::fileFor($class)),
                "{$class} is registered AUDITED but never names the recorder.",
            );
        }
    }

    public function test_every_unaudited_writer_carries_a_reason(): void
    {
        self::assertNotSame([], self::register(),
            'An empty register makes every assertion in this file vacuous.');

        foreach (self::register() as $class => $disposition) {
            if ($disposition === self::AUDITED || $disposition === self::IS_THE_AUDIT) {
                continue;
            }

            // Debt is allowed. Debt nobody wrote down is how AUD-01 stops
            // being true without anyone deciding that it should.
            self::assertGreaterThan(40, strlen($disposition),
                "{$class} is exempt from AUD-01 with a reason too short to be one.");
        }
    }

    public function test_nothing_outside_a_module_writes_to_the_database(): void
    {
        $offenders = [];

        foreach (['app/Http', 'app/Support', 'routes'] as $directory) {
            foreach (self::phpFilesIn(self::root().'/'.$directory) as $file) {
                $source = (string) file_get_contents($file);

                if (self::isDatabaseWriter($source)) {
                    $offenders[] = str_replace(self::root().'/', '', $file);
                }
            }
        }

        self::assertSame([], $offenders,
            'A write outside app/Modules is outside every boundary this test can police. '
            .'Coding Standards §5: controllers validate, invoke a use case, and serialise.');
    }

    // ─────────────────────────────────────── the wiring, proven behaviourally

    public function test_a_new_module_needs_nothing_from_the_audit_module_but_the_interface(): void
    {
        // The acceptance criterion for this step, read literally: a module is
        // audited "without touching audit code". This class is the stand-in for
        // a module that does not exist yet — it asks the container for the
        // interface and records. Nothing was registered, no listener was added,
        // and app/Modules/Audit was not edited to make this pass.
        $module = new class($this->app->make(AuditRecorderInterface::class))
        {
            public function __construct(private readonly AuditRecorderInterface $audit) {}

            /** @param array<string, string> $before */
            public function reassign(string $customerId, array $before, string $toOwner): void
            {
                $this->audit->record(
                    AuditEvent::customerReassigned(),
                    'customer',
                    $customerId,
                    $before,
                    ['owner_id' => $toOwner],
                );
            }
        };

        $customer = (string) Uuid::uuid7();
        $module->reassign($customer, ['owner_id' => 'previous'], 'next');

        /** @var object{event: string, entity_id: string}|null $row */
        $row = DB::table('audit_log')->first();

        self::assertNotNull($row);
        self::assertSame('CUSTOMER_REASSIGNED', $row->event);
        self::assertSame($customer, $row->entity_id);
    }

    public function test_the_recorder_resolves_without_a_module_registering_anything(): void
    {
        self::assertInstanceOf(
            AuditRecorderInterface::class,
            $this->app->make(AuditRecorderInterface::class),
        );
    }

    // ────────────────────────────────────────────────────────────── scanning

    /**
     * Every class under `app/Modules` that writes to the database.
     *
     * @return list<class-string>
     */
    private function writers(): array
    {
        $writers = [];
        $seen = 0;

        foreach (self::phpFilesIn(self::root().'/app/Modules') as $file) {
            $seen++;
            $source = (string) file_get_contents($file);

            if (self::isDatabaseWriter($source)) {
                $writers[] = self::classIn($source);
            }
        }

        // A glob that matches nothing passes every assertion built on it,
        // forever. Point 5.2 shipped exactly that scanner before it was caught.
        self::assertGreaterThan(15, $seen, 'The scanner read almost nothing.');

        return array_values(array_filter($writers));
    }

    private static function isDatabaseWriter(string $source): bool
    {
        $writes = false;

        foreach (self::DML as $verb) {
            if (str_contains($source, $verb)) {
                $writes = true;
                break;
            }
        }

        if (! $writes) {
            return false;
        }

        foreach (self::DATABASE as $signal) {
            if (str_contains($source, $signal)) {
                return true;
            }
        }

        return false;
    }

    /** @return class-string|null */
    private static function classIn(string $source): ?string
    {
        if (preg_match('/^namespace\s+([^;]+);/m', $source, $namespace) !== 1) {
            return null;
        }

        if (preg_match('/^(?:final\s+)?(?:readonly\s+)?class\s+(\w+)/m', $source, $class) !== 1) {
            return null;
        }

        /** @var class-string $fqcn */
        $fqcn = trim($namespace[1]).'\\'.$class[1];

        return $fqcn;
    }

    private static function fileFor(string $class): string
    {
        return self::root().'/app/'.str_replace(
            ['App\\', '\\'],
            ['', '/'],
            $class,
        ).'.php';
    }

    /** @return list<string> */
    private static function phpFilesIn(string $directory): array
    {
        if (! is_dir($directory)) {
            return [];
        }

        $files = [];

        /** @var \SplFileInfo $file */
        foreach (new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
        ) as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }

    private static function root(): string
    {
        return dirname(__DIR__, 3);
    }
}
