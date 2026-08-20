# Runbook — startup

`DEV-11` requires written runbooks for restore, startup, deployment and
rollback. This is the startup one. Restore, deployment and rollback are still
owed and are on the deployment-debt register.

`ST-09` asks for a startup checklist **executed and recorded after each boot**.
A checklist a person reads and ticks is neither reliably executed nor recorded,
so the checklist here is a script.

---

## Start the stack

```bash
docker compose up -d
```

Brings up PostgreSQL, Redis, php-fpm and nginx in the `ST-02` order, each gated
on the previous one reporting healthy (`ST-03`). Reachable at
`https://localhost:8443` — the certificate is self-signed, so a browser will
warn.

Three services are behind profiles and do **not** start by default:

```bash
docker compose --profile search up -d        # Meilisearch — Module 15
docker compose --profile workers up -d       # queue workers — from step 1 on
docker compose --profile verify up boot-order-probe
```

## Verify a boot

```bash
./scripts/verify-boot.sh          # tears down, boots cold, asserts, records
./scripts/verify-boot.sh --keep   # same, but leaves the stack running
```

Appends a dated `PASS`/`FAIL` line to `runbooks/boot-log.md` (`ST-04`, `ST-09`)
and exits non-zero on failure, so it can gate a deployment.

It asserts: nothing survives teardown · the `ST-02` order holds with health
gating · every service reports healthy · HTTPS serves and plain HTTP redirects
(`SEC-14`) · PostgreSQL and Redis both survive a restart (`D-54`, `ST-06`).

## Stop

```bash
# Every profile must be named — `down` ignores services in inactive profiles,
# and a survivor looks exactly like a freshly started container.
docker compose --profile search --profile workers --profile verify down
```

Add `-v` to delete the volumes as well. That destroys the database, the search
index, the uploaded files and the TLS certificate.

---

## When something is wrong

| Symptom | Cause seen before | Check |
|---|---|---|
| `nginx` restart-loops, exit `127` | a command missing from the image | `docker logs crm-nginx` |
| HTTPS returns `502` | php-fpm unreachable — wrong `fastcgi_pass` port, or php unhealthy | `docker compose ps`, then `docker logs crm-php` |
| A service never leaves `starting` | its healthcheck never passes; the dependant is correctly refusing to start | `docker inspect -f '{{json .State.Health}}' <container>` |
| `required variable … is missing` | `.env` absent or incomplete | compare against `.env.example`; CI asserts they match |
| A container is running that you did not start | it belongs to an inactive profile and survived `down` | tear down naming every profile |
| Chromium fails to start | `HOME` not writable by the runtime user | `docker compose exec php sh -c 'test -w $HOME'` |

## Logs

```bash
docker compose logs -f            # everything
docker compose logs -f php        # one service
```

PHP errors go to stderr and are collected by the container runtime, in every
environment — the image sets `log_errors=On` and `error_log=/proc/self/fd/2`
because PHP's built-in default logs nowhere at all.

---

## What this runbook does not cover

Local only. Every item below is on the deployment-debt register in
`CHECKLIST.md` and is unproven until it has run on the real server.

- **`ST-01`** services starting at host power-on. `restart: unless-stopped`
  models it; it is not the same thing.
- **`ST-05`** missed-job catch-up after downtime (`D-55`) — needs the scheduler.
- **`ST-08`** a `/health` endpoint reporting each service — belongs to the
  application, not to the placeholder document root.
- **Restore, deployment and rollback runbooks** — the other three `DEV-11` asks
  for.
- **Backups** (`BK-01`…`BK-08`), including a real restore test.
- **The external heartbeat** (`OBS-07`) — a server that is down cannot report
  that it is down.
