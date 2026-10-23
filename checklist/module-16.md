> Frozen history of Module 16, cut verbatim from `CHECKLIST.md` on 2026-10-23 (commit `7b87c0c`).
> Open boxes here are stale copies — the live ones are in `CHECKLIST.md`. Do not tick here.

## Module 16 — Alerts

> As a user, I want to be told when something of mine needs attention, so that nothing expires or breaks unnoticed.

**Tables** `alerts` — one open row per kind and record, addressed to a user · `backup_runs` (`J-10`'s record) · jobs `J-10`, `J-14`, `J-16`

**Acceptance criteria**
- [x] A user never sees an alert addressed to someone else *(2026-10-23 — `AlertsEndpointTest`: another's alert is neither listed nor counted, and marking it read answers 404 (`SEC-09`); every role reads only its own)*
- [x] A quotation inside the window raises one alert for its owner and one for the owner's Team Leader; running `J-16` again adds none *(2026-10-23 — `RaiseQuotationExpiryAlertsTest`: owner and leader, none for an inactive person or an unowned deal; a later run adds nothing, raises no counter, unreads nothing (§18.3); `ExpiringQuotationsTest` on real quotations)*
- [x] Each alert clears itself when its cause is fixed *(2026-10-23 — quotation out of the window or a new owner (`J-16`), lock ended and failure record gone (`J-17`), storage back under the limit (`J-14`), the next good backup (`J-10`); each cleared row kept — one test per kind; a debt row below: clearing waits for the next run)*
- [x] Marking an alert read lowers the bell's count; nothing is ever deleted *(2026-10-23 — `AlertsEndpointTest` read one / read all and no delete route; `alerts` has no `deleted_at`; `AlertBell.spec`; the browser: «تعليم الكل كمقروء» left six rows, none unread)*
- [x] A failed backup, a locked account, a failed job or storage over its limit each reaches every Super Admin *(2026-10-23 — `BackupFailedAlertsTest`, `AccountLockedAlertsTest` (five real wrong passwords), `JobFailedAlertsTest` (a real job failing through `queue:work`), `StorageThresholdAlertsTest`; hidden Super Admins included, nobody else; on dev a real `RunBackupJob` failure reached the bell ×2)*
- [x] ❌ Not in this module: push, email, priorities, the daily budget, quiet hours (`D-33`, §18.3) *(nothing built for them, `D-33`)*

### Point list — approved 2026-10-22 (mohanadmm, in chat; `D-153`)

Each point is a red turn, then a green turn, on its own branch.

- [x] **0** `D-153` written; this section and the ownership row; the agent guides' next action *(2026-10-22, #421 — `D-153` and `J-16` in the master, this section, the guides and their Arabic twins; the 53 test files reading them 1420 passed)*
- [x] **1** The `Alerts` module and its `alerts` table; `GET /alerts`, the unread count, mark read; the bell and its list in ar/en *(2026-10-22, #422 — `alerts` with D-153's kinds and one open alert per user, kind and record; four endpoints, auth only, no delete; the bell on the context bar's first row; full PHP suite 4391 passed alone, vitest 1590; browser ar/en × 1280/375 × light/dark: a long job name ran under its button and Escape stopped closing after «تعليم كمقروء» — both fixed, the second with a test)*
- [x] **2** Quotation expiring soon: `quotations.expiry_alert_days`, `J-16` with its catch-up, owner + Team Leader, clearing *(2026-10-22, #424 — raised once (§18.3), cleared on the run after the quotation leaves the window; `ExpiringQuotationsInterface`, `teamLeadersOf`; the setting on the limits screen; full PHP suite 4422 passed alone, vitest 1593; browser: the limits intro's fixed "five of the seven" now counts (test added); on dev the scheduler's catch-up ran J-01 then J-16)*
- [x] **3** Account locked and job failed, to every Super Admin *(2026-10-22, #425 — on Identity's `AccountLocked` and Laravel's `JobFailed`, per account and per job class, a repeat counted and unread again; `J-17` hourly clears them (approved by the owner 2026-10-22, §15 row); full PHP suite 4433 passed alone; dev workers restarted onto the listener)*
- [x] **4** Storage over limit: `J-14` and its limit setting *(2026-10-23, #430 — `limits.storage_alert_percent` (percent, 1–99, 80 unset; the owner's reading of "the limit"), `StorageUsageInterface` on the files volume, raised once and cleared back under; full PHP suite 4452 passed alone; dev: 8.7 % in use, nothing raised; browser: the «٪» unit and «7 من 9»)*
- [x] **5** Minimal backups (`J-10`: database + files, checksum, success/failure record) and the backup-failed alert *(2026-10-23, #431 — `postgresql-client-17` in the PHP image and the `crm-backups` volume (owner's approval 2026-10-23); `pg_dump -Fc` + the files as tar.gz, all or nothing, one a day at 02:00 and on start; the writer in Storage (its filesystem guard); full PHP suite 4471 passed alone; dev: checksums match on disk, `pg_restore --list` reads 68 tables' data)*
- [x] **6** Every criterion ticked with its test; the manual test list; the debt rows; the browser check ar/en × 1280/375 × light/dark; the module archived to `checklist/module-16.md` *(2026-10-23 — criteria ticked with evidence; the Arabic manual test list given to the owner; two debt rows added; browser ar/en × 1280/375 × light/dark as Super Admin with all five kinds: `alerts.subject_id` (64) was shorter than two job class names, so their failures would have lost their alert — widened to 255 in `7b87c0c`'s branch with a test; a wrapping link's centre missed its own tap target — the link is a 44px block; on dev a real J-10 failure (catch-up before its migration) reached the bell — debt row; archived to `checklist/module-16.md`)*
