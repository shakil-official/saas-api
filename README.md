# SaaS Subscription & Tenant Management API

A production-style **multi-tenant SaaS backend** built with Laravel 11 —
companies (tenants), users, role-based permissions, subscription plans with
feature limits, customers, Redis caching, background jobs, and a fully
tenant-isolated REST API.

This single file covers: setup, architecture, the complete API reference
(payload → response for every endpoint), the Postman collection, the full
request workflow, caching/indexing strategy, and known gaps.

---

## Table of Contents
1. [Tech Stack](#1-tech-stack)
2. [Setup Instructions (Docker)](#2-setup-instructions-docker)
3. [Postman Collection](#3-postman-collection)
4. [Project Structure](#4-project-structure)
5. [Multi-Tenancy Architecture](#5-multi-tenancy-architecture)
6. [Database Schema & Indexing](#6-database-schema--indexing)
7. [Caching Strategy & Invalidation](#7-caching-strategy--invalidation)
8. [Background Jobs](#8-background-jobs)
9. [Full API Reference (payload → response)](#9-full-api-reference-payload--response)
10. [Full Request Workflow](#10-full-request-workflow-step-by-step)
11. [System Design Decisions](#11-system-design-decisions)
12. [Running Tests](#12-running-tests)
13. [Troubleshooting](#13-troubleshooting)
14. [Known Gaps / Still To Add](#14-known-gaps--still-to-add)

---

## 1. Tech Stack

| Layer | Choice |
|---|---|
| Framework | Laravel 11 (PHP 8.2) |
| Auth | Laravel Sanctum (token-based) |
| Roles/Permissions | Spatie Laravel Permission (guard: `api`) |
| Database | MySQL 8 |
| Cache / Queue | Redis 7 (via Predis) |
| Web server | Nginx (php-fpm behind it) |
| Containerization | Docker Compose (app, queue, scheduler, nginx, mysql, redis, adminer) |
| Tests | PHPUnit |

---

## 2. Setup Instructions (Docker)

### Prerequisites
- Docker + Docker Compose installed
- Port `8000` (API), `3307` (MySQL, remapped to avoid clashing with a local
  MySQL on `3306`), `6380` (Redis, remapped from `6379`), and `8081`
  (Adminer) free on your host

### Step-by-step

```bash
# 1. Extract the project and enter it
cd saas-api

# 2. Copy the environment file
cp .env.example .env

# 3. Build and start every container (app, queue, scheduler, nginx, mysql, redis, adminer)
docker-compose up -d --build

# 4. Generate the app encryption key
docker-compose exec app php artisan key:generate

# 5. Run migrations AND seed reference data (roles, plans) — both are required
docker-compose exec app php artisan migrate --seed
```

**`--seed` is not optional.** Without it, `roles`/`permissions` and `plans`
tables are empty, and registration will fail with
`"There is no role named 'admin' for guard 'api'"`, and `GET /plans` will
return an empty list. If you ever run `migrate:fresh` (which wipes all
tables), you must re-seed:
```bash
docker-compose exec app php artisan migrate:fresh --seed
```

### Verify it's working
```bash
curl http://localhost:8000/api/plans
```
Should return the seeded **Free / Pro / Enterprise** plans. If you get
`{"data":[]}`, the seed step above did not run — re-run it.

### If you built the project via `install.sh` (scaffolding a fresh Laravel skeleton)
`install.sh` is only needed if you're assembling this repo's custom `app/`,
`database/`, `routes/` code onto a fresh `laravel new` skeleton (this
sandbox couldn't reach Packagist to do that step itself). Run it once, with
internet access, from the project root:
```bash
chmod +x install.sh
./install.sh
```
It scaffolds Laravel, merges in the custom code, and installs Composer
packages (Sanctum, Spatie Permission, Swagger, Predis). If you were handed
a project that already has a working `vendor/` folder, **skip this
entirely** and go straight to the Docker steps above.

### Permission issues (`storage/framework/views: Permission denied`)
The Docker image's entrypoint script (`docker/php/entrypoint.sh`) fixes
`storage/` and `bootstrap/cache/` ownership on every container start. If
you still hit a permission error (e.g. after manually editing files as
root on the host):
```bash
docker-compose exec app chmod -R 775 storage bootstrap/cache
docker-compose exec app chown -R www-data:www-data storage bootstrap/cache
```

### Seeded reference data
| Roles (guard `api`) | Plans |
|---|---|
| `admin`, `manager`, `staff` | `Free` (3 users / 50 customers), `Pro` (15 / 1000), `Enterprise` (unlimited) |

### Adminer (DB browser)
Open `http://localhost:8081`. Server field: **`mysql`** (the Docker service
name — not `127.0.0.1`, since Adminer runs inside the same Docker network).
Username `saas_user`, password `saas_password`, database `saas_api`.

### Auto-restart after a PC reboot
Every service has `restart: unless-stopped`. If Docker Desktop is set to
launch on login, all containers come back up automatically — no manual
`docker-compose up` needed, unless you previously ran `docker-compose down`
(which removes containers entirely; in that case, run `up -d` once more,
`--build` is not needed unless the Dockerfile changed).

---

## 3. Postman Collection

### File locations (inside this repo)
```
docs/postman/SaaS-Subscription-API.postman_collection.json   ← the collection (all requests)
docs/postman/SaaS-API-Local.postman_environment.json         ← the environment (base_url + variables)
```
**Both files are required.** The collection alone will not work — it
references `{{base_url}}`, `{{token_a}}`, etc., which only exist once the
environment file is also imported and selected.

### How to import — step by step

1. Open the **Postman** desktop app or web app.
2. Click the **Import** button (top-left corner of the app, or
   `File → Import` in the menu bar).
3. A dialog opens with a drag-and-drop area. Either:
    - Drag **both** JSON files from `docs/postman/` into that area at once, or
    - Click **"Choose Files"** and select both files (hold Ctrl/Cmd to
      multi-select) from `docs/postman/`.
4. Postman will show a preview listing what it detected:
    - **"SaaS Subscription & Tenant Management API"** → recognized as a *Collection*
    - **"SaaS API - Local"** → recognized as an *Environment*
5. Click **Import** to confirm. You'll now see:
    - The collection in the left sidebar under **Collections**
    - The environment in the environment list (usually accessed via the
      dropdown in the top-right corner of the app, or the "Environments"
      tab in the left sidebar)
6. **Activate the environment**: click the environment dropdown in the
   top-right of the Postman window (it says "No Environment" by default)
   and select **"SaaS API - Local"**. This step is easy to miss — if
   skipped, every request will fail because `{{base_url}}` won't resolve
   to anything.
7. Confirm `base_url` is correct: click the eye icon 👁 next to the
   environment dropdown to preview its variables. It should show
   `base_url = http://localhost:8000/api`. If your API runs on a
   different host/port, edit it here (click the environment name → edit
   the value → Save).

### Running requests

**Option A — one at a time:** open the collection in the sidebar, click
into a folder (e.g. "2. Auth"), click a request (e.g. "Register Tenant
A"), then click the blue **Send** button. The response appears in the
lower panel; the **Test Results** tab next to it shows pass/fail for that
request's built-in assertions.

**Option B — the whole collection at once:** right-click the collection
name in the sidebar → **Run collection** (or use the "Runner" button at
the top of the collection view). This opens the Collection Runner, which
lets you pick which folders/requests to include (leave everything checked
for a full run), then click **Run SaaS Subscription & Tenant Management
API**. It executes every request top-to-bottom in order and shows a
pass/fail summary — this is the Postman equivalent of running
`./test-full-api.sh`.

### Recommended run order
Requests are grouped into 8 folders that are meant to be run **in this
order** (top to bottom in the sidebar), since later requests depend on
data/tokens created by earlier ones:
```
1. Public                    → no auth needed, sanity-checks the API is up
2. Auth                      → Register Tenant A, Register Tenant B, Login, Me, Logout
3. Tenant                    → uses {{token_a}}
4. Subscription               → uses {{token_a}}
5. Customers                  → uses {{token_a}}; saves {{customer_id}}
6. Users                      → uses {{token_a}}; saves {{user_id}}
7. Dashboard                  → uses {{token_a}}
8. Tenant Isolation Checks     → uses {{token_b}} against {{customer_id}} created by Tenant A
```

### Auto-token capture (no manual copy-pasting)
Running **"Register Tenant A"** (folder 2) automatically saves these into
the active environment via its Tests script:
| Variable | Set by |
|---|---|
| `{{token_a}}` | Register Tenant A |
| `{{tenant_a_id}}` | Register Tenant A |
| `{{admin_a_email}}` | Register Tenant A |
| `{{token_b}}` | Register Tenant B |
| `{{tenant_b_id}}` | Register Tenant B |
| `{{customer_id}}` | Create Customer |
| `{{user_id}}` | Create User |
| `{{first_plan_id}}` / `{{pro_plan_id}}` | List Plans |

Every other request in the collection references these variables instead
of hardcoded values — so as long as you run folder 2 first, everything
downstream just works.

### What's inside each request
Every request has a **Tests** tab with assertions (status code, and key
response fields) that run automatically and show green ✔ / red ✘ in the
response panel. Several requests (Register, Login, List Plans, Create
Customer, etc.) also have **saved example responses** — click the small
dropdown arrow next to the Send button, under "Examples", to view a
sample response without needing a live server at all.

### Common import issues

| Problem | Fix |
|---|---|
| "Could not import" / blank preview | Make sure you selected the `.json` files directly, not a folder |
| Requests show `{{base_url}}` literally in the URL bar unresolved | The environment isn't selected — check the top-right dropdown |
| 401 on every request after import | Run "Register Tenant A" or "Login" first to populate `{{token_a}}` |
| Variables not saving between requests | Confirm you're using the **same environment** for every request (check the dropdown hasn't reset to "No Environment") |

---

## 4. Project Structure

```
app/
  Exceptions/PlanLimitExceededException.php   Custom exception → HTTP 403
  Http/
    Controllers/Api/    Thin controllers (orchestration only)
    Middleware/          IdentifyTenant.php
    Requests/            Form Request validation (per-action rules)
    Resources/           API response shaping (CustomerResource)
  Jobs/                  SendTenantWelcomeEmail, ExpireSubscriptions
  Models/                Tenant, User, Plan, Subscription, Customer, UsageLog
  Observers/             CustomerObserver, UserObserver, PlanObserver (cache busting)
  Providers/             AppServiceProvider, RouteServiceProvider, RepositoryServiceProvider
  Repositories/          Contracts/ + Eloquent/ (Customer's filter/sort/paginate logic)
  Scopes/TenantScope.php Global scope — the core of multi-tenancy
  Services/SubscriptionService.php   All plan-limit / subscription business logic
  Traits/BelongsToTenant.php         Applies TenantScope + auto-fills tenant_id
database/
  migrations/    Fully indexed schema
  seeders/       RolePermissionSeeder, PlanSeeder
  factories/     Test data factories
docs/
  API.md, WORKFLOW.md, postman/       (this README supersedes/summarizes both)
tests/
  Feature/   AuthTest, TenantIsolationTest, CustomerControllerTest,
             UserControllerTest, DashboardCacheTest
  Unit/      SubscriptionServiceTest
docker/      Dockerfile (with entrypoint.sh permission fix), nginx config
docker-compose.yml   app, queue, scheduler, nginx, mysql, redis, adminer
test-full-api.sh              End-to-end bash smoke test (27 checks)
test-tenant-isolation.sh      Focused tenant-isolation bash test
```

---

## 5. Multi-Tenancy Architecture

**Approach: single database, shared tables, `tenant_id` discriminator
column** (chosen over database-per-tenant or schema-per-tenant — simpler
migrations/backups, scales to tens of thousands of tenants on one DB, and
fits a SaaS-subscription API where tenants don't need hard data-residency
guarantees).

**Enforced in three layers, not just convention:**
1. Every tenant-owned table has an indexed `tenant_id` FK.
2. `BelongsToTenant` trait + `TenantScope` global scope — every Eloquent
   query on `User`, `Customer`, `Subscription` is automatically filtered
   by `tenant_id`. A developer would have to explicitly call
   `withoutTenantScope()` to bypass it.
3. `IdentifyTenant` middleware resolves `tenant_id` from the authenticated
   user on every request and binds it into the container before any
   controller runs.

**Route-model-binding hardening:** Laravel's `SubstituteBindings`
middleware (auto-added to the `api` group) resolves `{customer}`/`{user}`
route parameters *before* our custom middleware in some priority orderings.
Two defenses are in place:
- `bootstrap/app.php` explicitly declares middleware priority
  (`Authenticate → IdentifyTenant → ThrottleRequests → SubstituteBindings`).
- `BelongsToTenant::resolveRouteBinding()` independently re-checks the
  resolved model's `tenant_id` against the authenticated user's tenant,
  returning `null` (→ Laravel's normal 404) on any mismatch — regardless of
  middleware ordering.

**Result:** `GET /api/customers/{id}` for a customer belonging to a
different tenant returns `404`, not `403` — the row is indistinguishable
from "doesn't exist" to a foreign tenant.

---

## 6. Database Schema & Indexing

```
tenants          company/organization record
users            tenant_id nullable FK; unique(tenant_id, email) — email is
                 unique PER TENANT, not globally
plans            global (not tenant-scoped); JSON feature_limits column
subscriptions    tenant_id + plan_id; one "active" row per tenant at a time
customers        tenant's own customers; unique(tenant_id, email)
usage_logs       daily metrics per tenant (present in schema; not yet
                 populated by any job — see section 14)
roles/permissions  Spatie package tables, guard "api"
```

| Table | Index | Serves |
|---|---|---|
| `users` | `unique(tenant_id, email)` | Per-tenant login lookup + uniqueness |
| `users` | `index(tenant_id, status)` | Active-user counts for limit checks |
| `customers` | `unique(tenant_id, email)` | Duplicate prevention, scoped correctly |
| `customers` | `index(tenant_id, created_at)` | Pagination + date-range filtering |
| `subscriptions` | `index(tenant_id, status)` | "current subscription for tenant X" |
| `subscriptions` | `index(status, ends_at)` | Hourly expiry job avoids a full scan |
| `tenants` | `unique(slug)` | Tenant resolution by slug |

Query-level optimizations: `UserController::index()` eager-loads `roles`
(`with('roles')`) to avoid N+1; dashboard/plan/subscription reads go
through the cache layer (section 7) before ever hitting these indexes.

---

## 7. Caching Strategy & Invalidation

| Cache key | TTL | What / Why |
|---|---|---|
| `plans:active` | 6h | Global plan list — rarely changes, read on nearly every request |
| `tenant:{id}:active_plan` | 1h | Read on almost every write (limit checks, rate limiting) |
| `tenant:{id}:dashboard:summary` | 5m | Expensive `COUNT()` aggregates |

**Invalidation is event-driven, not just TTL expiry:**
- `CustomerObserver` / `UserObserver` clear `tenant:{id}:dashboard:summary`
  on create/update/delete (registered in `AppServiceProvider::boot()`).
- `PlanObserver` clears `plans:active` on any Plan save/delete.
- `SubscriptionService::subscribe()` clears `tenant:{id}:active_plan`
  inline immediately after a plan change.
- `ExpireSubscriptions` job clears both `active_plan` and
  `dashboard:summary` when a subscription expires.

Proven (not just documented) by `tests/Feature/DashboardCacheTest.php`,
which asserts the cache key is actually cleared after a mutation.

**Why customer *lists* aren't cached:** too many filter/sort/pagination
combinations for a good hit rate — caching is applied to aggregates and
rarely-changing reference data instead, where the win is real.

---

## 8. Background Jobs

Redis-backed queue, processed by the `queue` container
(`php artisan queue:work redis`); scheduled tasks run via the `scheduler`
container (`schedule:run` every 60s).

- **`SendTenantWelcomeEmail`** — dispatched after registration so the API
  response doesn't wait on SMTP. Retries 3x, 30s backoff.
- **`ExpireSubscriptions`** — hourly (`routes/console.php`). Flips
  `status → expired` for subscriptions past `ends_at`, using the
  `(status, ends_at)` index; busts the relevant caches.

---

## 9. Full API Reference (payload → response)

Base URL: `http://localhost:8000/api`. All authenticated requests need
`Authorization: Bearer {token}` and `Accept: application/json`.

### Public

**`POST /auth/register`**
```json
// Request
{
  "company_name": "Acme Inc",
  "company_slug": "acme",
  "company_email": "hello@acme.com",
  "admin_name": "Alice Admin",
  "admin_email": "alice@acme.com",
  "admin_password": "password123",
  "admin_password_confirmation": "password123"
}
```
```json
// 201 Response
{
  "message": "Company registered successfully.",
  "tenant": { "id": 1, "name": "Acme Inc", "slug": "acme", "status": "active" },
  "user": { "id": 1, "tenant_id": 1, "name": "Alice Admin", "email": "alice@acme.com" },
  "token": "1|OU5v28d1syKjym9P5l6JhUSwJFhaxSgZQgV3VIozd56864e9"
}
```
Validation failure (duplicate slug/email, weak password) → `422`.

**`POST /auth/login`**
```json
// Request
{ "email": "alice@acme.com", "password": "password123" }
```
```json
// 200 Response
{ "user": { "id": 1, "tenant_id": 1, "name": "Alice Admin", "email": "alice@acme.com" },
  "token": "2|xyz..." }
```
Wrong password → `422`: `{"message":"The provided credentials are incorrect.","errors":{"email":[...]}}`

**`GET /plans`**
```json
// 200 Response (cached 6h)
{
  "data": [
    { "id": 1, "name": "Free", "slug": "free", "price": "0.00",
      "feature_limits": { "max_users": 3, "max_customers": 50, "api_rate_limit": 30 } },
    { "id": 2, "name": "Pro", "slug": "pro", "price": "29.00",
      "feature_limits": { "max_users": 15, "max_customers": 1000, "api_rate_limit": 120 } },
    { "id": 3, "name": "Enterprise", "slug": "enterprise", "price": "99.00",
      "feature_limits": { "max_users": null, "max_customers": null, "api_rate_limit": 600 } }
  ]
}
```

**`GET /plans/{id}`** → `200` single plan object, or `404`.

---

### Authenticated — Account

**`GET /auth/me`** → `200`
```json
{ "id": 1, "tenant_id": 1, "name": "Alice Admin", "email": "alice@acme.com",
  "roles": [ { "id": 1, "name": "admin" } ] }
```
No/invalid token → `401 {"message":"Unauthenticated."}`

**`POST /auth/logout`** → `200 {"message":"Logged out."}` (revokes the current token)

---

### Authenticated — Tenant

**`GET /tenant`** → `200` — your own company record.

**`PATCH /tenant`** (admin only)
```json
// Request
{ "name": "Acme Incorporated", "settings": { "timezone": "Asia/Dhaka" } }
```
→ `200` updated tenant object. Non-admin → `403`.

---

### Authenticated — Subscription

**`GET /subscription`** → `200`
```json
{ "plan": { "id": 1, "name": "Free", "feature_limits": {"max_users":3,"max_customers":50} },
  "usage": { "users": 2, "customers": 17 } }
```

**`POST /subscription`** (admin only)
```json
// Request
{ "plan_id": 2 }
```
```json
// 201 Response
{ "message": "Subscription updated.",
  "subscription": { "id": 5, "tenant_id": 1, "plan_id": 2, "status": "active",
    "plan": { "id": 2, "name": "Pro" } } }
```

---

### Authenticated — Customers (any role)

**`GET /customers?status=&search=&from=&to=&sort_by=&sort_dir=&per_page=`**
```json
// 200 Response — always includes pagination envelope
{
  "data": [ { "id": 1, "name": "John Doe", "email": "john@example.com", "status": "active" } ],
  "links": { "first": "...", "last": "...", "prev": null, "next": null },
  "meta": { "current_page": 1, "per_page": 15, "total": 1 }
}
```

**`POST /customers`**
```json
// Request
{ "name": "John Doe", "email": "john@example.com", "phone": "+8801700000000" }
```
```json
// 201 Response — flat, not wrapped in "data"
{ "id": 1, "name": "John Doe", "email": "john@example.com",
  "phone": "+8801700000000", "status": "active", "created_at": "2026-09-17T08:00:00+00:00" }
```
Plan limit reached → `403`:
```json
{ "message": "The 'max_customers' limit (50) for your current plan has been reached.",
  "error": "plan_limit_exceeded" }
```
Duplicate email (within same tenant) → `422`.

**`GET /customers/{id}`** → `200` (flat object), or `404` if it belongs to another tenant.

**`PATCH /customers/{id}`** — any subset of `{name, email, phone, status}` → `200` updated object.

**`DELETE /customers/{id}`** → `200 {"message":"Customer deleted."}` (soft-deleted)

---

### Authenticated — Users (admin only)

**`GET /users?per_page=`** → `200` — paginated, roles eager-loaded.

**`POST /users`**
```json
// Request
{ "name": "Bob Manager", "email": "bob@acme.com", "password": "password123", "role": "manager" }
```
→ `201` user object with `roles`. Plan `max_users` limit hit → `403 plan_limit_exceeded`.
Non-admin caller → `403`.

**`PATCH /users/{id}`**
```json
// Request
{ "status": "disabled", "role": "staff" }
```
→ `200` updated user.

**`DELETE /users/{id}`** → `200 {"message":"User removed."}`

---

### Authenticated — Dashboard

**`GET /dashboard/summary`** → `200` (cached 5 min, tenant-scoped)
```json
{ "plan": "Free", "total_users": 2, "total_customers": 17, "active_customers": 15,
  "limits": { "max_users": 3, "max_customers": 50 },
  "generated_at": "2026-09-17T10:15:00+00:00" }
```

---

### Error format reference

| Status | Meaning | Body shape |
|---|---|---|
| 401 | No/invalid token | `{"message":"Unauthenticated."}` |
| 403 | Wrong role, or plan limit exceeded | `{"message":"...","error":"plan_limit_exceeded"}` for limits; plain message for role checks |
| 404 | Resource not found (incl. cross-tenant access) | `{"message":"..."}` |
| 422 | Validation failure | `{"message":"...","errors":{"field":["..."]}}` |
| 429 | Rate limit exceeded | `{"message":"Too Many Attempts."}` |

### Rate limits
`/auth/login` 5/min per IP · `/auth/register` 3/min per IP · everything
else scales with the tenant's plan `api_rate_limit` (Free 30/min, Pro
120/min, Enterprise 600/min), keyed per authenticated user.

---

## 10. Full Request Workflow (step by step)

Every authenticated request flows through this pipeline:
```
Nginx → auth:sanctum (resolve token → User)
      → identify.tenant (bind tenant_id into the container)
      → throttle:api (plan-based rate limit)
      → role:admin (if the route requires it)
      → SubstituteBindings (resolve {customer}/{user} route params —
         tenant-checked independently, see section 5)
      → Controller (thin orchestration)
      → FormRequest (validation)
      → Service / Repository (business logic + tenant-scoped queries)
      → API Resource (response shaping)
      → JSON response
```

**Registration workflow:**
```
POST /auth/register
  → DB::transaction {
      1. Create Tenant
      2. Create admin User (tenant_id auto-filled by BelongsToTenant)
      3. assignRole('admin')
      4. SubscriptionService::subscribe(tenant, freePlan)
    }  ← all-or-nothing; if any step fails, nothing is committed
  → SendTenantWelcomeEmail::dispatch()  ← queued, doesn't block the response
  → Sanctum token issued
  → 201 response
```

**Plan-limit enforcement workflow:**
```
POST /customers
  → SubscriptionService::assertWithinLimit(tenant, 'max_customers', fn () => Customer::count())
  → reads currentPlan() from cache
  → if limit reached: throws PlanLimitExceededException
  → exception's own render() method converts it to HTTP 403 automatically
  → otherwise: Customer::create() proceeds, tenant_id auto-filled,
    CustomerObserver fires → busts dashboard cache
```

**Background job workflow:** `queue` container continuously drains Redis's
job queue (welcome emails); `scheduler` container ticks every 60s and
triggers `ExpireSubscriptions` once per hour.

---

## 11. System Design Decisions

- **Sanctum over Passport** — first-party API client, not third-party OAuth; simpler to operate.
- **Email unique per-tenant, not globally** — two companies can each have `admin@company.com` without collision. Verified by `AuthTest::test_two_tenants_can_use_the_same_admin_email_independently`.
- **Rate limits scale with plan tier** — throttling is itself a monetizable, plan-aware feature, not a flat global limit.
- **Repository pattern only where it earns its complexity** — `Customer` has non-trivial filter/sort/paginate logic worth isolating; `Plan`/`Tenant` are simple enough to query directly via Eloquent.
- **At real scale, next steps would be:** shard `tenant_id` ranges across read replicas (schema deliberately has no cross-tenant joins, so this stays feasible); move `usage_logs` writes to a queued job if usage-tracking volume grows; add a search-oriented store if customer search outgrows indexed `LIKE` queries.

---

## 12. Running Tests

```bash
docker-compose exec app php artisan test
```

| Test file | Covers |
|---|---|
| `AuthTest` | Registration, per-tenant email uniqueness, login failure |
| `TenantIsolationTest` | Cross-tenant data leak prevention (list + direct-ID access + route-model-binding hardening) |
| `CustomerControllerTest` | CRUD, duplicate-email validation, plan-limit 403, pagination/search |
| `UserControllerTest` | RBAC (admin-only), plan-limit 403, role/status updates |
| `DashboardCacheTest` | Cache actually populates AND actually invalidates (not just documented) |
| `SubscriptionServiceTest` | Plan-limit business logic in isolation (unit-level) |

Also available: `./test-full-api.sh` (27-step bash smoke test against a
running server) and `./test-tenant-isolation.sh` (focused isolation check).

---

## 13. Troubleshooting

| Symptom | Fix |
|---|---|
| `GET /plans` returns `{"data":[]}` | Seeders never ran: `docker-compose exec app php artisan db:seed` |
| Registration fails: "no role named admin" | Same as above — roles table is empty |
| `storage/framework/views: Permission denied` | `docker-compose exec app chmod -R 775 storage bootstrap/cache && chown -R www-data:www-data storage bootstrap/cache` |
| `Connection refused` on migrate right after `up` | MySQL wasn't ready yet — `docker-compose.yml` now has a healthcheck; wait ~15s and retry |
| `MissingRateLimiterException` | `bootstrap/providers.php` must list `RouteServiceProvider::class` (Laravel 11 doesn't auto-load it) |
| 401 on a request you expect to succeed | Check the token hasn't been logged-out / expired |
| 404 for something you just created | Almost always correct — you're authenticated as a different tenant than the owner |
| Port conflict (3306/6379 already in use) | This project maps MySQL to host `3307` and Redis to `6380` — use those, not the defaults |
| `no configuration file provided: not found` | You ran `docker-compose` from the wrong directory — `cd` into the folder with `docker-compose.yml` |

---

## 14. Known Gaps / Still To Add

Honest list — not hidden:
1. **`usage_logs` table is unused** — schema and model exist, but no job/observer writes to it yet. Either populate it for real trend analytics, or remove it.
2. **No resource-level Policy classes** — authorization is role-level (`role:admin`) only. A `manager` can edit any customer in their tenant; there's no per-record ownership check.
3. **No interactive Swagger/OpenAPI UI** — `l5-swagger` is in `composer.json` but has no `@OA` annotations yet. This README + Postman collection are the current documentation.
4. **Not yet pushed to a GitHub repository** — the assessment asks for a repo link; this has been delivered as a zip so far.
5. **Tenant `status` (suspended/cancelled) isn't enforced** — the column exists but nothing currently blocks a suspended tenant's users from logging in.
