# Code Review — `reachweb/statamic-keila-integration`

Senior Laravel/Statamic review of the addon that forwards Statamic form submissions to a self‑hosted Keila newsletter instance.

**Method.** The Keila API was verified against the official docs *and* the actual Elixir source (`github.com/pentacent/keila`, ~v0.19) — endpoints, auth, body envelope, status enum, error shapes, and double‑opt‑in behaviour. Every finding below was then re‑checked against the real code; two candidate findings were dropped as false positives (see end). Review only — no code was changed.

**Bottom line.** The architecture is sound and most of the integration is provably correct (see *Verified correct*). There is **one load‑bearing correctness bug** (the duplicate‑email upsert is dead against real Keila and the test masks it) and **one consent/abuse gap**. The rest is polish.

---

## Critical

### C1 — Duplicate‑email detection is dead against real Keila; the upsert race silently drops the opt‑in
**`src/Support/KeilaClient.php:128‑137`** (and `:139‑152`, job `src/Jobs/SyncContactToKeila.php:71‑83`)

`isAlreadyExists()` short‑circuits on the wrong status codes:

```php
if (! in_array($response->status(), [409, 422], true)) {
    return false;   // line 130
}
```

Verified against Keila's source: a **duplicate email returns HTTP `400`**, never 409/422. Keila routes *all* contact changeset/validation errors through `Errors.send_changeset_error → put_status(400)`, with body `{"errors":[{"status":"400","title":"Validation failed","detail":"has already been taken",...}]}`. Because `400` isn't whitelisted, `isAlreadyExists()` returns `false` before the substring check on line 136 — which greps for exactly `"has already been taken"` — is ever reached. That substring logic is correct but **unreachable**.

Consequence on the lookup→create race (`find()` 404, then `create()` 400 because the contact appeared in between): `create()` never throws `KeilaContactExistsException`, so the upsert fallback at job `:77‑83` never runs. Instead `throwForStatus()` classifies the `400` as `KeilaPermanentException` (line 151), `handle()` catches it at `:66‑68` and logs `[keila] sync dropped (permanent error)` — **the contact's opt‑in is silently lost.** The whole `isAlreadyExists`/`KeilaContactExistsException`/upsert mechanism, built specifically for this race, is non‑functional in production.

It's hidden because `tests/Jobs/SyncContactToKeilaTest.php:183` fakes a `422` for the duplicate POST — a status Keila never emits — so `test_create_conflict_falls_back_to_update` passes against a fiction.

> Blast radius: the create path only runs after a `find()` miss, so this bites on concurrent/duplicate submits or a contact created out‑of‑band in the TOCTOU window — not on every returning subscriber (those go straight to `update()`). But when it hits, the deliberately‑built safety net does nothing and the failure is silent.

**Fix.** Add `400` to the whitelist on line 130:
```php
if (! in_array($response->status(), [400, 409, 422], true)) {
    return false;
}
```
The substring guard on lines 134‑136 already separates a duplicate‑email `400` from other `400`s (malformed body, custom data > 8 KB, invalid email), so non‑duplicate `400`s still fall through to `throwForStatus()` as permanent — no regression. Then update the test fixture at `SyncContactToKeilaTest.php:183` to return `400` (not `422`) so the test exercises real Keila behaviour, and fix the now‑wrong doc comment on lines 124‑127 (see L1).

---

## High

### H1 — A public form can subscribe or silently re‑activate an arbitrary third party's email, with no proof of consent recorded
**`src/Jobs/SyncContactToKeila.php:89‑106`** (dispatch path `src/Listeners/ForwardSubmissionToKeila.php:76‑83`)

The pipeline is unauthenticated (`SubmissionCreated` fires on any public submission) and single‑opt‑in (no Keila confirmation email — API creates bypass Keila's double‑opt‑in, *verified*). `optInAccepted()` only proves the **submitter ticked a box**, not that they **own the email**. So anyone can enter `victim@example.com` with the toggle on and:

```php
$status = $existing['status'] ?? 'active';
$newStatus = $status === 'unreachable' ? null : 'active';   // line 95
```

An existing **`unsubscribed`** contact is flipped back to `active` (`match` labels it `'reactivated'`, line 102) — i.e. a previous explicit **unsubscribe (a withdrawal of consent) is silently reversed by a third party.** And no proof of consent is captured: the dispatched payload and `context()` record only form handle, masked email, mapped fields, tags and source — never the submitter's IP or a consent timestamp. That's a real GDPR/CAN‑SPAM exposure (no audit trail, and consent enforcement is 100% the addon's responsibility on the API path).

**Fix (two independent gaps):**
1. **Don't auto‑resurrect a withdrawn consent.** At line 95 treat `'unsubscribed'` like `'unreachable'` — pass `null` so a third party can't flip an unsubscribed contact back to `active`. If reactivation is a wanted feature, gate it behind a real confirmation step (the API path bypasses Keila's double‑opt‑in, so you must implement it yourself), not a bare form submit.
2. **Record proof of consent.** Capture `request()->ip()` + a UTC ISO‑8601 timestamp **in the listener** (`process()`, while the request is alive — *not* in the job, which runs `afterResponse()`/on a worker where `request()->ip()` is null), thread them into the payload, and write them as `data.consent_ip` / `data.consent_at` / `data.consent_source` in `attributes()`. Set `consent_at` only on **create** and merge‑preserve the original on later re‑submits. Document the residual single‑opt‑in limitation in the README.

---

## Medium

None confirmed. Two candidates (worst‑case timeout budget; unmasked listener error log) were investigated and rejected as false positives — see *Considered and dismissed*.

---

## Low

### L1 — Comments / PHPDoc / README cite wrong Keila status codes
**`src/Support/KeilaClient.php:124‑127`, `src/Exceptions/KeilaPermanentException.php:7‑10`, `README.md:95`**
The comment claims a conflict "surfaces as a 409, or a 422"; the PHPDoc lists "401 / 403 / 422"; the README says "Permanent errors (401/422)". Verified: Keila returns **`400`** for duplicates/validation and **`403`** (not 401) on auth failure. Runtime is fine (`throwForStatus` treats any non‑5xx/non‑408/429 4xx as permanent), so these are docs‑only — but they're the same wrong assumption that produced C1 and will mislead the next maintainer. **Fix:** rewrite the comment to "HTTP 400 with detail 'has already been taken'"; change the PHPDoc to "4xx such as 400 / 403"; change README:95 to "Permanent errors (400/403)".

### L2 — `isAlreadyExists()` decodes then re‑encodes JSON just to substring‑match
**`src/Support/KeilaClient.php:134`**
`$body = Str::lower(json_encode($response->json() ?? []) ?: '');` parses the body then re‑serializes it solely to run `Str::contains()`. The re‑encode can also reorder keys / escape unicode, making the match marginally less robust. **Fix:** `$body = Str::lower($response->body());` — the searched substrings appear verbatim in the raw body, and the `?? []` / `?: ''` guards become unnecessary.

### L3 — Email masking exposes the whole local part for 1–2 char addresses
**`src/Support/Email.php:16‑20`**
`$first.'***@'.$domain` always keeps the first char + a fixed `***`. For `a@example.com` → `a***@example.com` discloses 100% of the local part; the `***` is decorative, not redaction. **Fix:** mask proportionally — `<=1` char → `*@domain`; `2` → first char + `*`; else first char + `str_repeat('*', len-1)`. (Domain stays visible for log correlation, which is fine.)

### L4 — Under `QUEUE_CONNECTION=sync`, `$tries`/`backoff()` are inert; README oversells retries
**`src/Jobs/SyncContactToKeila.php:25‑47`, `README.md:95‑96`**
`$tries = 3` + `backoff()` are read only by a queue **worker**. Dispatched with `->afterResponse()` under `sync`, the job runs **once** via `dispatchSync` (no retry loop), so a transient Keila 5xx/429/timeout is logged via `failed()` and the contact dropped until the next submission. **Fix (docs‑first):** state in the README that 3 tries + backoff apply only with a real worker; under `sync` the job runs once. Optional hardening: pin a configured non‑sync connection, or add a small bounded in‑handle `retry()` on `KeilaTransientException` for single‑server sync deployments (don't silently force a connection the host hasn't provisioned).

### L5 — `backoff()` third element is dead given `$tries = 3`
**`src/Jobs/SyncContactToKeila.php:25, 44‑47`**
With 3 tries only `backoff[0]` and `backoff[1]` are ever used; the `60` would gate a 4th attempt that never happens. Cosmetic but misleads reliability tuning. **Fix:** `return [10, 30];` (or bump `$tries` to 4 if a third retry is genuinely wanted). Keep the array length at `$tries − 1`.

### L6 — 429 retries ignore any `Retry-After` hint
**`src/Support/KeilaClient.php:147‑149`** (backoff at job `:44‑47`)
`429` maps to `KeilaTransientException` (good), but nothing reads `Retry-After`; the fixed `[10,30]` could retry inside the rate‑limit window and burn the budget. **Fix (only if needed):** carry a nullable `$retryAfter` on the exception from `$response->header('Retry-After')` and use a dynamic backoff/`release`. *Caveat:* the verified Keila contacts endpoints return only 400/403/404 — a `429 + Retry-After` on these endpoints is unconfirmed, so this may guard a case your Keila never produces. Verify against your version before investing. (Note: after exhausting tries the job *logs* via `failed()`, it isn't silently dropped.)

### L7 — No test pins the email‑mask logging contract
**`tests/Jobs/SyncContactToKeilaTest.php`**
The README guarantees "Emails are masked in logs (`j***@example.com`)" but no test fakes the logger or references `Email::mask`, so a regression in `mask()`/`context()` would pass CI. **Fix:** add a focused unit test for `Email::mask` (normal, single‑char local, missing `@`, blank/null, multibyte) and optionally a job‑level test asserting the logged `email` equals `Email::mask($email)`. (Skip a "token absent from logs" assertion — `context()` never references the token and `Http::withToken()` can't leak it, so it would pin nothing.)

### L8 — README version floor is wrong and composer.json declares none of its own
**`README.md:9`, `composer.json:13‑15`**
README says "Statamic 6, Laravel 11+, PHP 8.2+", but `statamic/cms ^6.0` pins `laravel/framework ^12.40 || ^13` (PHP floor `^8.3`) — Laravel 11 and PHP 8.2 can't even resolve. Meanwhile `composer.json` `require` lists only `statamic/cms: ^6.0` with no `php`/`laravel` key, so the package self‑enforces nothing. **Fix:** correct README:9 to "Statamic 6, Laravel 12.40+ or 13, PHP 8.3+", and add `"php": "^8.3"` to `require` so the floor is enforced by the package, not just transitively.

### L9 — `phpunit.xml` uses the PHPUnit 9.3 schema while 11+ is installed
**`phpunit.xml:2‑3`**
`xsi:noNamespaceSchemaLocation=".../9.3/phpunit.xsd"`, 9.x‑era root attributes, and an empty `<coverage/>` — but `orchestra/testbench ^10.8` pulls PHPUnit 11+. It auto‑migrates at runtime, so it still runs. **Fix:** `vendor/bin/phpunit --migrate-configuration` to rewrite the schema, drop the empty `<coverage/>`, and relocate legacy attributes.

### L10 — Leftover generator scaffolding
**`tests/ExampleTest.php` (1‑15), `tests/__fixtures__/dev-null/`**
`ExampleTest` only asserts `assertTrue(true)`; the `dev-null` fixture dir (one empty `.gitkeep`) is referenced nowhere (`grep` confirms). Real coverage lives in `tests/Jobs`, `tests/Listeners`, `tests/Feature`. **Fix:** with your approval (CLAUDE.md forbids removing tests unprompted), delete both — nothing points at either.

---

## Verified correct (checked against the real Keila API — no change needed)

- **Auth & transport:** Bearer token via `Http::withToken()`, base path `/api/v1`. Token sourced only from `env('KEILA_API_TOKEN')`, never hardcoded, never logged (it lives only in the `Authorization` header; `context()` logs only form + masked email + status‑based exception messages). ✔
- **Request/response envelope:** the `{"data": {...}}` wrapper, top‑level `email`/`first_name`/`last_name`/`external_id` with custom fields under nested `data`, and `unwrap()` of the response `data` key — all match Keila exactly. ✔
- **Endpoints & `id_type=email`:** `POST /contacts`, `PUT /contacts/{email}?id_type=email`, `GET /contacts/{email}?id_type=email` with `rawurlencode` — correct; `id_type=email` is real since Keila 0.17.0 (README requirement accurate). ✔
- **Status enum & transitions:** `active`/`unsubscribed`/`unreachable` are the real values; status is settable on create *and* update; omitting `status` on update leaves it untouched, so the "never resurrect `unreachable`" logic (job `:95`) genuinely works. ✔
- **Single opt‑in:** API creates bypass Keila's double‑opt‑in, so setting `status: active` with no confirmation email is intended and works (the consent caveat is H1, not a bug here). ✔
- **Tags as `data.tags` + segment:** Keila has no native tags field, so this is the idiomatic approach; the client‑side merge in `attributes()` correctly preserves existing custom data before the full‑document `PUT`. ✔
- **Non‑interfering guarantee:** the listener's swallow‑all + `afterResponse()` dispatch keep Keila entirely off the request path; `FormSubmissionTest` proves the native submission and notification emails are unaffected. ✔
- **Config & wiring:** `SubmissionCreated` via `$listen`, and config auto‑merged/auto‑published by slug (so the README's `--tag=statamic-keila-integration-config` is valid despite not being declared in `ServiceProvider`). ✔ No N+1 (no DB; 2 HTTP calls/submission is inherent).

## Considered and dismissed (false positives)

- **"Worst‑case 4×10s HTTP calls blow the 45s job timeout."** Arithmetic is 40s < 45s at defaults, *and* the 4‑call conflict path is unreachable on real Keila anyway (it depends on the same 409/422 assumption that C1 shows never fires — the job stops after `find`→`create`). Tuning observation gated behind an env knob and a dead branch, not a defect.
- **"Listener swallow‑all logs the raw exception message without masking."** No PII demonstrably reaches it: the only exceptions escaping `process()` carry static, status‑based messages (the job swallows its own Keila exceptions first), and the premise that this is "the one unmasked log site" is false — `context()` also logs `$e->getMessage()` raw, so the listener is consistent, not an outlier. At most a low‑value hardening nit.
