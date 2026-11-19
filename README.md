# Pixelforge image generator

A small Laravel app where signed-in users generate images through the Pixelforge API, see an estimated price before they submit and the exact price afterwards, and are charged exactly once per successful generation.

Laravel 13, Vue 3 and Inertia (the official Vue starter kit), PostgreSQL. The webhook signature check, the pricing maths and the status state machine are written by hand: no third-party package is involved in any of the three.

## What it does

- Sign up and sign in (the starter kit's Fortify screens; email verification is not required).
- **Generate** page: prompt, size, quality and up to four reference image URLs. The form shows a price range before you submit.
- **History** page: every generation of the signed-in user, with live status (polling the app's own endpoint), the estimate range, and, once finished, the price charged. Provider cost and rates are stored but not shown to users.
- Pixelforge reports results by webhook. A scheduled command covers lost webhooks and lost jobs.

## Quick start (local)

Requirements: PHP 8.3 with the `pdo_pgsql` extension, Composer 2, Node 22, PostgreSQL. A Pixelforge API key and its webhook signing secret.

```bash
# 1. Two databases: one for the app, one for the tests.
createdb pixelforge
createdb pixelforge_test

# 2. Dependencies. The JS install never runs dependency scripts.
composer install
npm ci --ignore-scripts

# 3. Configuration.
cp .env.example .env
php artisan key:generate
```

Edit `.env`:

| Key                          | Value                                                                                                                               |
| ---------------------------- | ----------------------------------------------------------------------------------------------------------------------------------- |
| `DB_USERNAME`, `DB_PASSWORD` | A Postgres role that can use both databases. `DB_DATABASE` is `pixelforge`; the tests use `pixelforge_test` (set in `phpunit.xml`). |
| `PIXELFORGE_API_KEY`         | Your key. One key per environment.                                                                                                  |
| `PIXELFORGE_WEBHOOK_SECRET`  | The signing secret of that key.                                                                                                     |
| `PIXELFORGE_BASE_URL`        | Defaults to `https://pixelforge.promptedit.dev/v1`; only set it to change that.                                                     |

```bash
# 4. Schema and assets.
php artisan migrate
npm run build
```

Run three processes (three terminals):

```bash
php artisan serve              # the web app
php artisan queue:work         # sends generations to Pixelforge
php artisan schedule:work      # runs the reconcile command every minute
```

**Webhooks need a public URL.** Pixelforge rejects `localhost`. Start any tunnel that points at the local server (Cloudflare Tunnel, ngrok, Expose), then set the webhook URL in the Pixelforge dashboard to `https://<your-tunnel-host>/webhooks/pixelforge` and copy the signing secret it shows into `PIXELFORGE_WEBHOOK_SECRET`. If saving the URL rotates the secret, update `.env` and restart the server.

Tests and checks:

```bash
php artisan test        # PHPUnit; needs the pixelforge_test database
composer test           # Pint (style), PHPStan, then the tests
npm run check           # JS formatting and lint
npm run types:check     # vue-tsc
```

The tests never reach the real API: `Http::preventStrayRequests()` is on, and every call is faked.

## How money is computed

- **Integer picodollars.** 1 USD = 10^12 picodollars, stored in `bigInteger` columns. There is no float anywhere in the money path. Decimal strings from Pixelforge (`"5.00"`, `"0.046125000"`) are read digit by digit. Dollars are formatted only when shown.
- **Rates come from the generation object.** Pixelforge fixes the rates when a generation is created. The first generation object the app sees (the POST answer or a webhook, whichever comes first) supplies them, and they are stored on the row. Rates are USD per million tokens, so one token costs `rate × 10^6` picodollars; a rate with more than four significant decimals is refused rather than rounded.
- **Provider cost** = text tokens × text rate + image tokens × image rate + output tokens × output rate + reasoning tokens × reasoning rate, all in picodollars, from the `usage` of the finished generation.
- **User price** = provider cost × 5 / 4 (cost plus 25%). With rates of at most four decimals the result is always a whole picodollar; if it ever were not, the code throws instead of rounding.
- **Cost and price live only on the `charges` table** (one row per generation, unique `generation_id`). The `generations` table keeps only the estimate range.
- **Estimate before submitting.** The form shows a range. Exact parts: output tokens (from the quality × size table) and the fixed token count per reference image. Uncertain parts: text tokens (prompt characters divided by the characters-per-token value, rounded up, then ± the variance, 15% in the current pricing answer) and reasoning tokens (the minimum and maximum for the chosen quality). Bounds are rounded outward, so the real number is never outside the range. The table and the rates are read from `GET /v1/pricing` and cached for five minutes; if Pixelforge's pricing cannot be read, the page says so instead of guessing. The server recomputes the estimate on submit and never trusts the number the browser showed.

Worked example (the succeeded example from the Pixelforge docs, rates 5.00 / 10.00 / 40.00 / 12.50 per million):

| Part                   | Tokens | Rate (USD per 1M) | Cost (USD)    |
| ---------------------- | ------ | ----------------- | ------------- |
| Text                   | 12     | 5.00              | 0.00006       |
| Image                  | 765    | 10.00             | 0.00765       |
| Output                 | 6,450  | 40.00             | 0.258         |
| Reasoning              | 3,184  | 12.50             | 0.0398        |
| **Provider cost**      |        |                   | **0.305510**  |
| **User price (× 5/4)** |        |                   | **0.3818875** |

Stored as integers: 305,510,000,000 and 381,887,500,000 picodollars.

## Robustness

- **Webhook verification.** The route is outside CSRF. The signature is HMAC-SHA256 over `"<timestamp>.<raw body>"` using the raw request bytes, compared with `hash_equals`, accepting several `v1` values and rejecting timestamps more than 300 seconds from now in either direction. An empty secret makes the endpoint refuse to run. A bad signature is a 400 and nothing is written. `ping` and unknown event types get a 200 and are not recorded.
- **Each event is processed once.** The event id from the signed body goes into `webhook_events` (unique `event_id`) with `insertOrIgnore`. Zero rows inserted means a repeat: return 200 and do nothing. The insert and the generation change are in one database transaction, so an exception rolls back both and returns a 5xx, which makes Pixelforge redeliver. Bodies are never logged (they hold a signed image URL).
- **One function applies every Pixelforge object.** The POST answer, webhooks and the reconcile command all call it. It locks the row (`SELECT … FOR UPDATE`), moves status forward only (queued → processing → succeeded or failed, whatever order events arrive in), fills the Pixelforge id and rates if empty, and on `succeeded` writes the charge. A final row is never changed again. A succeeded object without usage or completion time throws and changes nothing.
- **At most one charge per generation.** `charges.generation_id` is unique and the charge is written with `insertOrIgnore`, inside the same transaction as the status change. A failed generation never gets a charge.
- **Unknown outcomes are never turned into failures.** If a POST times out, gets no readable answer, gets a 5xx other than `overloaded`, or gets a 409, Pixelforge may have created (and may bill) the generation. From then on our code never writes `failed` for that row; only a Pixelforge object with status `failed` can finish it.
- **Retries depend on the error code.**

    | Answer                                                                            | What happens                                                                                                                                                                                                                                                                                                                               |
    | --------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
    | `concurrency_limit_exceeded`                                                      | Wait for `Retry-After` and try again, for up to ten minutes. Waiting does not use attempts.                                                                                                                                                                                                                                                |
    | `rate_limit_exceeded`, `overloaded`, any other 429, timeouts, 5xx                 | Retry with growing backoff (2, 4, 8, 16 seconds, or `Retry-After` if longer), five attempts at most. After that the row is failed with a clear message only if no attempt (this one included) had an unknown outcome; timeouts and 5xx other than `overloaded` therefore never fail the row, which stays queued for the reconcile command. |
    | `daily_limit_exceeded`, 400 (including `content_policy_violation`), 401, 404, 422, any other 4xx except 409 and 429 | Failed at once with a readable message, but only if no earlier attempt for that row had an unknown outcome; otherwise the row stays queued and the reconcile command re-sends it every five minutes (same key, same body) until a Pixelforge object finishes it. The raw provider text goes to the log only. |
    | 409                                                                               | Never re-sent. The row stays queued; the reconcile command or its webhook finishes it.                                                                                                                                                                                                                                                     |

- **Idempotency.** The row's uuid is sent as the `Idempotency-Key` and as `metadata.local_id`. The body is rebuilt from the row on every attempt, so it is identical each time (a changed body under the same key would be a 409). `SubmitGeneration` is unique per generation, so only one job per row is alive.
- **Double submit.** The form carries a `submission_id` (a uuid) that becomes the row id. A second post of the same form hits `insertOrIgnore`, creates no row and no job. The submit route is also throttled.
- **Reconcile command** (`pixelforge:reconcile`, every minute, no overlap). It never writes `failed` itself. Rows that have a Pixelforge id and no news for two minutes are asked about with `GET /generations/{id}`. Rows without an id are re-dispatched after five minutes (same key, same body; a replay is not billed). A 409 row is looked for in the newest three pages of `GET /generations` by `metadata.local_id`. Each run makes at most 20 requests, oldest first. Rows whose answer could not be stored (`apply_failed`) are re-sent for at most 24 hours.

## Decisions

| Topic            | Decision                                                                                                                              | Why                                                                      |
| ---------------- | ------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------ |
| Money            | Integer picodollars, strings parsed digit by digit                                                                                    | Exact totals that match `GET /v1/usage`; no rounding anywhere            |
| Rates            | From the first generation object, stored on the row                                                                                   | Pixelforge fixes them at creation; `/v1/pricing` may change later        |
| Estimate         | A range, with bounds rounded outward                                                                                                  | Text and reasoning tokens are only known after completion                |
| Webhooks         | Processed inline in one transaction, not through a queue                                                                              | The exactly-once guarantee is one transaction to explain                 |
| Status           | One apply function, forward only                                                                                                      | The three sources (POST, webhook, reconcile) cannot disagree             |
| Failures         | Never fail a row after an unknown outcome                                                                                             | Failing a generation Pixelforge bills would break "charged exactly once" |
| Database         | PostgreSQL for local, tests and CI; `insertOrIgnore` instead of catching unique violations                                            | A caught unique violation aborts a Postgres transaction                  |
| Time             | `completed_at` and the image expiry are `timestamptz`, the connection runs in UTC, and `completed_at` comes from Pixelforge's payload | Billing days are UTC days of completion                                  |
| Images           | Store Pixelforge's URL and its expiry; show a placeholder after expiry                                                                | The URL expires                                                          |
| Dependencies     | No package for signature verification, pricing or the state machine                                                                   | The core logic stays readable                                            |
| Deleted accounts | Generations and charges are kept with a null user                                                                                     | The money records must survive so totals still match                     |

## Admin report

Admins get a report at `/admin/report`. Make an existing user an admin with:

```bash
php artisan app:make-admin <email>
```

What it shows, for a range of UTC days (default: the last 30 days, at most 92):

- **Per day and per user:** number of charges, provider cost, amount charged, and profit (charged minus provider cost). A generation belongs to the UTC day it **completed**, not the day it was created. Charges of deleted accounts are listed as "Deleted account", so the day totals stay complete.
- **Reconciliation with Pixelforge.** Next to each day, and in the totals, the report shows what Pixelforge says it billed for the same days (`GET /v1/usage`) and the **difference = Pixelforge's cost minus ours** in provider cost. A difference of zero means the books match. Rows that can explain a gap (still queued or processing, or unfinished after an attempt with an unknown outcome) are counted per day next to it.
- Everything is summed in SQL and in integer picodollars and formatted to dollars only at the end. Pixelforge's answer is cached for a minute; if it cannot be read, the report shows our numbers and says that Pixelforge's usage is unavailable.

The two sides only match for one API key: use a key whose generations all come from this database.

## Not built

- **Balances and credits** (holding the maximum estimate, capturing the price): not built.

## Known limits

- Rates with more than four significant decimals throw; the generation stays non-final (even a failed generation with such rates).
- Prompts are kept after account deletion. This is a product decision, and prompts may contain personal data.
- Open registration on a public URL lets anyone spend the account's shared daily quota.
- A 409 row older than the newest 300 generations waits for its webhook (the list search only covers three pages of 100).
- `apply_failed` rows stop being re-sent after 24 hours.
- A definite rejection (e.g. 400) after an earlier unknown outcome leaves the row queued; the reconcile command keeps re-sending it every five minutes with no time cap, which uses part of the shared 60/min.
- No password-reset email is sent (`MAIL_MAILER=log`).
- PHPStan runs with a 1G memory limit (CI runs it via `composer test`) and may crash locally when the project path contains non-ASCII characters.
- The row lock is tested by the shape of the SQL, not by a real race. The unique indexes (`charges.generation_id`, `webhook_events.event_id`) are the last guard.

## Deploy

The app ships as one Docker image that runs four services under Docker Compose:

- `web`: FrankenPHP (Caddy with PHP built in), plain HTTP on port 8080 inside the container. Put a reverse proxy that terminates TLS in front of it; private proxy addresses are trusted for `X-Forwarded-*`.
- `worker`: `php artisan queue:work`.
- `scheduler`: `php artisan schedule:work`.
- `postgres`: PostgreSQL 17 with a named volume.

```bash
docker build --platform linux/amd64 -t pixelforge-app:<tag> .
cp deploy/env.production.example .env     # fill in the empty values; never commit the result
docker compose up -d postgres
docker compose run --rm web php artisan migrate --force   # migrations are a one-off step, not part of start-up
docker compose up -d
```

The commands above assume one machine. On the real deployment the image is built off the server (`--platform linux/amd64`) and moved with `docker save | ssh … docker load`; the host never builds or pulls (`pull_policy: never` in `compose.yaml`).

`.env` sits next to `compose.yaml` and holds `IMAGE_TAG`, the app key, database values and the Pixelforge key and webhook secret of that deployment. It is never part of the image. After changing it, run `docker compose up -d` again (it recreates the containers); a plain restart does not re-read it. Container memory limits are set in `compose.yaml`.

A GitHub Actions workflow builds the image, checks that it contains no `.env` and no webhook secret, starts the whole stack with throwaway values, checks `/up` and `/login`, and stores the image as an artifact.
