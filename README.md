# SaaS Subscription & Tenant Management API

A multi-tenant SaaS backend built with Laravel 11: companies (tenants), users,
role-based permissions, subscription plans with feature limits, customers,
dashboard analytics, Redis caching, and background jobs.

---

## 1. Setup Instructions

### Requirements
- Docker & Docker Compose (recommended), **or** PHP 8.2+, Composer, MySQL 8, Redis locally.

### Quick start (Docker)
```bash
cp .env.example .env
docker-compose up -d --build
docker-compose exec app composer install
docker-compose exec app php artisan key:generate
docker-compose exec app php artisan migrate --seed
```
API is now available at `http://localhost:8000/api`.

### Without Docker
```bash
cp .env.example .env
# edit .env: point DB_HOST/REDIS_HOST to localhost
composer install
php artisan key:generate
php artisan migrate --seed
php artisan serve
php artisan queue:work redis   # in a second terminal
```

### Running tests
```bash
docker-compose exec app php artisan test
# or: php artisan test
```

### Seeded data
- Roles: `admin`, `manager`, `staff` (Spatie permission, guard `api`)
- Plans: `Free` (3 users / 50 customers), `Pro` (15 users / 1000 customers), `Enterprise` (unlimited)

### Try it
```bash
curl -X POST http://localhost:8000/api/auth/register \
  -H "Content-Type: application/json" \
  -d '{
    "company_name": "Acme Inc",
    "company_slug": "acme",
    "company_email": "hello@acme.com",
    "admin_name": "Alice Admin",
    "admin_email": "alice@acme.com",
    "admin_password": "password123",
    "admin_password_confirmation": "password123"
  }'
```
See `docs/API.md` for the full endpoint reference with sample requests/responses.

---

## 2. Database Schema & Multi-Tenant Architecture

### Approach chosen: **Single database, shared tables, `tenant_id` discriminator column**

Compared against the three common multi-tenancy strategies:

| Strategy | Isolation | Ops overhead | Cost at scale | Chosen? |
|---|---|---|---|---|
| Database-per-tenant | Strongest | High (migrations run N times, connection pooling gets hard) | Expensive past a few hundred tenants | No |
| Schema-per-tenant | Strong | Medium (Postgres-specific, still N schemas to migrate) | Medium | No |
| **Shared DB + `tenant_id`** | Enforced in application layer | Low — one schema, one migration path | Scales to tens of thousands of tenants on one DB | **Yes** |

**Why this fits a SaaS management API specifically:** tenants here are companies
subscribing to a product, not enterprises requiring hard data-residency
guarantees. A shared-schema design keeps migrations, backups, and connection
pooling simple, and scales horizontally by sharding on `tenant_id` later if a
single database ever becomes a bottleneck — without a rewrite.

### How isolation is enforced (not just "by convention")
1. Every tenant-owned table (`users`, `customers`, `subscriptions`, `usage_logs`)
   has an indexed `tenant_id` foreign key.
2. `App\Traits\BelongsToTenant` + `App\Scopes\TenantScope` add a **global Eloquent
   scope** to every tenant-owned model. A developer would have to *explicitly*
   opt out (`withoutTenantScope()`) to leak cross-tenant data — the safe path
   is the default path, not something you have to remember.
3. `App\Http\Middleware\IdentifyTenant` resolves the tenant from the
   authenticated user on every request and binds it into the container before
   any controller code runs.
4. Route-model binding (`Route::apiResource('customers', ...)`) combined with
   the global scope means requesting another tenant's resource by ID returns
   `404`, not `403` — it doesn't even reveal the record exists (see
   `TenantIsolationTest`).

### Core tables
```
tenants          — company/organization record
users            — tenant_id nullable FK; platform login accounts; unique(tenant_id, email)
plans            — global, not tenant-scoped; JSON feature_limits
subscriptions    — tenant_id + plan_id; one "active" row per tenant at a time
customers        — tenant's own customers; unique(tenant_id, email)
usage_logs       — daily metrics per tenant, for analytics/growth reporting
roles/permissions — Spatie package tables, guard "api"
```

Full column-level definitions are in `database/migrations/`.

---

## 3. Caching Strategy & Invalidation

Redis is used for two distinct purposes, deliberately kept separate:

- **`CACHE_STORE=redis`** — response/query caching (this section)
- **`QUEUE_CONNECTION=redis`** — background job queue (section 5)

### What is cached, and why

| Cache key | Data | TTL | Reasoning |
|---|---|---|---|
| `plans:active` | Active subscription plans | 6h | Global, rarely changes, read on nearly every pricing/limit check |
| `tenant:{id}:active_plan` | Tenant's current plan + limits | 1h | Read on almost every write operation (limit checks); cheap to recompute but very frequently read |
| `tenant:{id}:dashboard:summary` | Aggregate counts (users, customers, usage vs. limits) | 5m | Expensive `COUNT()` queries; dashboard doesn't need second-level freshness |

### Invalidation strategy: **event-driven, not just TTL expiry**

Relying on TTL alone means users can see stale data for up to the TTL window
after a change. Instead, cache is proactively busted the moment underlying
data changes, using **Eloquent Observers** (`app/Observers/`):

- `CustomerObserver` → clears `tenant:{id}:dashboard:summary` on create/update/delete.
- `UserObserver` → clears `tenant:{id}:dashboard:summary` on create/update/delete.
- `PlanObserver` → clears the global `plans:active` cache on save/delete.
- `SubscriptionService::subscribe()` explicitly clears `tenant:{id}:active_plan`
  immediately after changing a tenant's plan (subscription changes are
  transactional and deliberate, so it's cleared inline rather than via an
  observer on a rarely-touched table).

This gives cache-hit performance most of the time, with correctness guaranteed
by invalidation rather than "hope the TTL is short enough." All cache keys are
**tenant-scoped by construction** (`tenant:{id}:...`), so there is no risk of
one tenant's cached data leaking into another tenant's response.

### Why not cache the customer list itself?
Paginated, filtered, sorted list endpoints have too many parameter
combinations to cache effectively (low hit rate, high invalidation
complexity) — caching there would add complexity without a real performance
win. Caching is applied to **aggregates and rarely-changing reference data**,
where the win is largest and invalidation is tractable.

---

## 4. Database Optimization & Indexing

| Table | Index | Query it serves |
|---|---|---|
| `users` | `unique(tenant_id, email)` | Per-tenant login lookups; also enforces per-tenant uniqueness (not global) |
| `users` | `index(tenant_id, status)` | "active users for this tenant" — used by limit checks and listings |
| `customers` | `unique(tenant_id, email)` | Duplicate-customer prevention scoped correctly per tenant |
| `customers` | `index(tenant_id, created_at)` | Pagination + date-range filtering, sorted by newest first |
| `subscriptions` | `index(tenant_id, status)` | "get the active subscription for tenant X" |
| `subscriptions` | `index(status, ends_at)` | The hourly `ExpireSubscriptions` job scans only active, expiring rows — avoids a full table scan as tenants grow |
| `usage_logs` | `unique(tenant_id, metric, logged_date)` | Upsert-safe daily metric writes; also serves time-series lookups |
| `tenants` | `unique(slug)` | Subdomain/tenant resolution |

**Query-level decisions:**
- All list/detail queries rely on the `tenant_id` global scope, which always
  hits the composite indexes above — there is no unscoped `SELECT *` path.
- `UserController::index()` eager-loads `roles` (`with('roles')`) to avoid
  N+1 queries when serializing role names for a paginated list.
- `SubscriptionController::current()` and `DashboardController::summary()`
  avoid recomputing counts on every request by going through the cache layer
  described in section 3 — the index above only matters on a cache miss.
- Repository classes (`app/Repositories/`) centralize query construction so
  filtering/sorting logic — and its index usage — lives in one reviewable
  place instead of being duplicated across controllers.

---

## 5. Background Jobs

Queue driver: Redis (`QUEUE_CONNECTION=redis`), run via `queue:work` (see
`docker-compose.yml`'s `queue` service).

- **`SendTenantWelcomeEmail`** — dispatched after registration. Email delivery
  is a slow, failure-prone I/O operation with no reason to block the API
  response; retried up to 3 times with backoff.
- **`ExpireSubscriptions`** — scheduled hourly (`routes/console.php`). Scans
  `subscriptions` using the `(status, ends_at)` index, flips expired rows to
  `status = expired`, and busts the relevant tenant caches. Runs as a job
  (not an inline scheduled closure) so it benefits from queue retries and
  doesn't block the scheduler process.

---

## 6. Architecture & Design Patterns (SOLID)

- **Repository pattern** (`app/Repositories/`) — used where a resource has
  non-trivial filtering/sorting/pagination logic worth isolating from the
  controller (`CustomerRepositoryInterface` / `EloquentCustomerRepository`).
  Bound via `RepositoryServiceProvider`, satisfying **Dependency Inversion**:
  controllers depend on the interface, not the Eloquent implementation, so
  the data layer can be swapped (e.g., for a test double) without touching
  controller code. Simpler resources (e.g. `Plan`) query the model directly —
  repositories are added where they earn their complexity, not everywhere.
- **Service layer** (`app/Services/SubscriptionService.php`) — all
  subscription/plan-limit business rules live here, independent of HTTP. This
  is what `SubscriptionServiceTest` (a Unit test) exercises directly, with no
  HTTP layer involved — a direct benefit of keeping business logic out of
  controllers (**Single Responsibility**).
- **Observer pattern** (`app/Observers/`) — decouples "a model changed" from
  "the cache must be invalidated." Adding a new tenant-affecting model later
  means adding an observer, not editing existing controllers (**Open/Closed**).
- **Global Scope + Trait** (`TenantScope` / `BelongsToTenant`) — tenant
  filtering is cross-cutting behavior applied declaratively to any model that
  opts in, rather than repeated `where('tenant_id', ...)` calls scattered
  through the codebase.
- **Form Requests** (`app/Http/Requests/`) — validation rules are colocated
  per-action and reusable, keeping controllers free of inline validation
  logic.
- **API Resources** (`app/Http/Resources/`) — response shaping is decoupled
  from the model's raw attributes, so internal columns are never accidentally
  exposed and the response contract can evolve independently of the schema.
- **Custom exception + `render()`** (`PlanLimitExceededException`) — business
  rule violations map to a clean HTTP response without `if/else` branching in
  every controller that enforces a limit.

---

## 7. System Design Notes / Key Decisions

- **Why Sanctum over Passport:** this is a first-party SPA/API client
  scenario, not a third-party OAuth integration — Sanctum's token model is
  simpler to operate and sufficient for the stated requirements.
- **Why email is unique per-tenant, not globally:** two different companies
  should be able to have an "admin@company.com"-style user without collision.
  This is a deliberate schema decision (`unique(tenant_id, email)`), not an
  oversight — covered explicitly by a test in `AuthTest`.
- **Why rate limits scale with plan tier:** `RouteServiceProvider`'s `api`
  limiter reads the tenant's current plan's `api_rate_limit` feature, so
  throttling is itself a monetizable, plan-aware feature rather than a flat
  global limit — consistent with the "feature limits" requirement.
  Auth endpoints (`login`, `register`) use separate, stricter, IP-based
  limiters to slow down credential-stuffing/abuse independent of any tenant.
- **Where this would go next at real scale:** shard `tenant_id` ranges across
  read replicas or separate physical databases once a single primary becomes
  the bottleneck (the schema was deliberately kept shard-friendly from day
  one — no cross-tenant foreign keys or joins anywhere in the codebase);
  move `usage_logs` writes off the request path via a queued job if
  usage-tracking volume grows; add a search-oriented read store (e.g.
  Elasticsearch) if customer search outgrows indexed `LIKE` queries.

---

## 8. Project Structure
```
app/
  Exceptions/        Custom domain exceptions (PlanLimitExceededException)
  Http/
    Controllers/Api/  Thin controllers — orchestration only
    Middleware/        IdentifyTenant
    Requests/          Form Request validation classes
    Resources/         API response shaping
  Jobs/                Queued background jobs
  Models/              Eloquent models
  Observers/           Cache-invalidation side effects
  Providers/           App/Repository/Route service providers
  Repositories/        Contracts + Eloquent implementations
  Scopes/              TenantScope (global scope)
  Services/            Business logic (SubscriptionService)
  Traits/              BelongsToTenant
database/
  migrations/          Schema, fully indexed
  seeders/             Roles, permissions, default plans
  factories/           Test data factories
docs/
  API.md               Full endpoint reference
tests/
  Feature/             HTTP-level tests, incl. tenant isolation
  Unit/                Service-layer business logic tests
docker/                Dockerfiles + nginx config
docker-compose.yml      app, queue, scheduler, nginx, mysql, redis
```
