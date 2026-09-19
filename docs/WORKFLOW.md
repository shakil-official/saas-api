# Project Workflow — SaaS Subscription & Tenant Management API

This document explains **how the whole project actually works end-to-end**:
what happens step by step when a request comes in, how the pieces
(middleware, controllers, services, cache, jobs) connect, and — for every
API endpoint — the exact request payload and the response you'll get back.

Read this alongside `README.md` (architecture/why-decisions) and
`docs/API.md` (pure endpoint reference). This file is the **narrative**
that ties them together.

---

## 1. High-Level Request Lifecycle

Every authenticated API request goes through the same pipeline before it
reaches your controller:

```
Client Request
     │
     ▼
┌─────────────────────┐
│ Nginx (port 8000)    │  routes the request to PHP-FPM (app container)
└─────────┬────────────┘
          ▼
┌─────────────────────┐
│ auth:sanctum          │  reads "Authorization: Bearer {token}",
│                       │  resolves it to a User row, rejects with 401 if invalid
└─────────┬────────────┘
          ▼
┌─────────────────────┐
│ identify.tenant       │  (App\Http\Middleware\IdentifyTenant)
│                       │  reads $user->tenant_id, binds it into the
│                       │  container as app('currentTenantId')
└─────────┬────────────┘
          ▼
┌─────────────────────┐
│ throttle:api          │  checks the tenant's plan api_rate_limit,
│                       │  rejects with 429 if exceeded
└─────────┬────────────┘
          ▼
┌─────────────────────┐
│ role:admin (if route  │  Spatie Permission — checks the user has the
│ requires it)          │  required role, rejects with 403 if not
└─────────┬────────────┘
          ▼
┌─────────────────────┐
│ Controller            │  thin — just orchestrates
└─────────┬────────────┘
          ▼
┌─────────────────────┐
│ FormRequest           │  validates input, returns 422 on failure
└─────────┬────────────┘
          ▼
┌─────────────────────┐
│ Service / Repository  │  actual business logic + DB queries
│                       │  (every query here is auto-filtered by
│                       │   TenantScope — see section 3)
└─────────┬────────────┘
          ▼
┌─────────────────────┐
│ API Resource           │  shapes the model into the JSON response
└─────────┬────────────┘
          ▼
     JSON Response
```

**Where this lives in code:**
- Middleware pipeline → `bootstrap/app.php` (aliases) + `routes/api.php` (which middleware applies to which routes)
- `IdentifyTenant` → `app/Http/Middleware/IdentifyTenant.php`
- Rate limiting rules → `app/Providers/RouteServiceProvider.php`
- Role checks → Spatie's `role:` middleware, checking roles assigned via `$user->assignRole(...)`

---

## 2. Tenant Registration Workflow (the entry point of everything)

```
POST /api/auth/register
     │
     ▼
AuthController::registerTenant()
     │
     ├─ DB::transaction() starts ─────────────────────────────┐
     │                                                         │
     │  1. Create Tenant row (companies table)                 │
     │  2. Bind app('currentTenantId') = new tenant's id        │
     │  3. Create User row (the admin), tenant_id auto-attached │
     │     via BelongsToTenant trait's "creating" hook           │
     │  4. $admin->assignRole('admin')  — Spatie                │
     │  5. Look up the "free" Plan, call                         │
     │     SubscriptionService::subscribe($tenant, $freePlan)    │
     │     → creates a Subscription row, status=active           │
     │                                                         │
     └─ transaction commits (all-or-nothing) ───────────────────┘
     │
     ▼
SendTenantWelcomeEmail::dispatch($tenant)   — pushed to Redis queue,
     │                                         does NOT block this response
     ▼
Sanctum token created for the new admin user
     │
     ▼
201 response: { tenant, user, token }
```

**Why a transaction:** if step 3 (create user) failed after step 1
(tenant created), you'd have an orphaned company with no login — the
transaction guarantees it's all-or-nothing.

**Why the email is a queued job, not sent inline:** registration
shouldn't wait on an SMTP round-trip. See section 6.

### Request payload
```json
POST /api/auth/register
Content-Type: application/json

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

### Response (201 Created)
```json
{
  "message": "Company registered successfully.",
  "tenant": {
    "id": 1, "name": "Acme Inc", "slug": "acme",
    "email": "hello@acme.com", "status": "active",
    "trial_ends_at": "2026-10-01T20:56:37.000000Z"
  },
  "user": {
    "id": 1, "tenant_id": 1, "name": "Alice Admin",
    "email": "alice@acme.com", "status": "active"
  },
  "token": "1|OU5v28d1syKjym9P5l6JhUSwJFhaxSgZQgV3VIozd56864e9"
}
```
Validation failure → `422` with per-field messages (e.g. slug already taken).

---

## 3. How Tenant Isolation Actually Works at Runtime

This is the mechanism behind "Tenant B can never see Tenant A's data" —
worth understanding precisely since it's the core of the whole system.

```
Any Eloquent query on a tenant-owned model, e.g.:
   Customer::where('status', 'active')->get();

                    │
                    ▼
   BelongsToTenant trait registered a Global Scope
   (TenantScope) when the model booted.
                    │
                    ▼
   TenantScope::apply() runs automatically, BEFORE
   the query executes:
       $builder->where('customers.tenant_id', app('currentTenantId'));
                    │
                    ▼
   Actual SQL sent to MySQL:
       SELECT * FROM customers
       WHERE tenant_id = 1        <- injected automatically
         AND status = 'active';
```

**The developer never wrote `tenant_id` anywhere in that controller
code.** That's the point — it's structurally impossible to forget.

For **route-model binding** (`Route::apiResource('customers', ...)`),
Laravel resolves `Customer::findOrFail($id)` internally — which is
also caught by the same global scope. So `GET /api/customers/5` for a
customer belonging to a different tenant returns `404`, because as far
as the scoped query is concerned, that row doesn't exist for you.

On **create**, the same trait auto-fills `tenant_id` before insert:
```php
static::creating(function ($model) {
    if (empty($model->tenant_id) && app()->bound('currentTenantId')) {
        $model->tenant_id = app('currentTenantId');
    }
});
```
So `CustomerController::store()` never has to write `tenant_id` into the
create array — it's filled in automatically at the model layer.

---

## 4. Login Workflow

```
POST /api/auth/login
     │
     ▼
AuthController::login()
     │
     ├─ User::withoutTenantScope()->where('email', ...)->first()
     │      ⚠ scope deliberately bypassed here — at login time we don't
     │        know the tenant yet, email is only unique PER tenant, so
     │        this could theoretically match multiple rows across
     │        tenants; in practice email+password narrows it to one.
     │
     ├─ Hash::check($password, $user->password)
     │      fails → 422 "credentials are incorrect"
     │
     └─ $user->createToken('api-token') → Sanctum token issued
     │
     ▼
200 response: { user, token }
```

### Request / Response
```json
POST /api/auth/login
{ "email": "alice@acme.com", "password": "password123" }
```
```json
200 OK
{ "user": { "id": 1, "tenant_id": 1, "name": "Alice Admin", ... },
  "token": "2|xyz..." }
```
Wrong password → `422`: `{ "message": "The provided credentials are incorrect.", "errors": {...} }`

---

## 5. Caching Workflow (Read Path + Invalidation Path)

### Read path — cache hit vs. miss
```
GET /api/dashboard/summary
     │
     ▼
Cache::remember("tenant:{id}:dashboard:summary", 5 min, fn () => ...)
     │
     ├─ Cache HIT  → Redis returns stored JSON instantly, no DB query at all
     │
     └─ Cache MISS → closure runs:
             - SubscriptionService::currentPlan($tenant)   [itself also cached]
             - User::count(), Customer::count(), etc.       [hits MySQL]
             - result stored in Redis with 5-minute TTL
             - result returned
```

### Invalidation path — what busts the cache
```
POST /api/customers  (create)
     │
     ▼
CustomerController::store() → Customer::create(...)
     │
     ▼
Eloquent fires the "created" event
     │
     ▼
CustomerObserver::created() runs automatically (registered in
AppServiceProvider::boot())
     │
     ▼
Cache::forget("tenant:{id}:dashboard:summary")
     │
     ▼
Next GET /api/dashboard/summary → cache MISS → fresh numbers computed
```

Same pattern for `UserObserver` (on User create/update/delete) and
`PlanObserver` (on global Plan changes, busts `plans:active`).
`SubscriptionService::subscribe()` busts `tenant:{id}:active_plan`
directly and inline (not via observer), since a plan change is a
single deliberate action, not a side effect of another model saving.

---

## 6. Background Job Workflow

Two separate processes run continuously alongside the web server:

```
docker-compose services:
  app        → serves HTTP requests (php-fpm behind nginx)
  queue      → php artisan queue:work redis   (processes jobs as they arrive)
  scheduler  → runs `php artisan schedule:run` every 60 seconds
```

### Job 1 — SendTenantWelcomeEmail
```
Registration happens → SendTenantWelcomeEmail::dispatch($tenant)
     │
     ▼
Job serialized, pushed onto the Redis "default" queue
     │                                    (HTTP response already returned
     │                                     to the client at this point)
     ▼
`queue` container's worker picks it up (usually within milliseconds)
     │
     ▼
Job::handle() runs — sends the email (or logs it, in this starter)
     │
     └─ on failure: retried up to 3 times, 30s apart (see $tries/$backoff)
```

### Job 2 — ExpireSubscriptions (scheduled)
```
Every hour (routes/console.php: Schedule::job(new ExpireSubscriptions)->hourly())
     │
     ▼
`scheduler` container's loop calls `artisan schedule:run`
     │
     ▼
ExpireSubscriptions::handle() runs
     │
     ├─ finds Subscription rows: status=active AND ends_at < now()
     │  (uses the (status, ends_at) index — cheap even at scale)
     │
     ├─ for each: status → 'expired'
     │
     └─ busts that tenant's active_plan and dashboard:summary caches
```

---

## 7. Plan Limit Enforcement Workflow

```
POST /api/customers   (or POST /api/users)
     │
     ▼
Controller calls:
   $subscriptionService->assertWithinLimit(
       $tenant, 'max_customers', fn () => Customer::count()
   );
     │
     ▼
SubscriptionService::assertWithinLimit()
     │
     ├─ currentPlan($tenant)  → cached lookup (section 5)
     ├─ $limit = $plan->limit('max_customers')   e.g. 50 for Free plan
     ├─ if $limit === null → unlimited, return immediately
     ├─ if currentCount() >= $limit → throw PlanLimitExceededException
     │
     ▼ (only reached if under limit)
Customer::create(...) proceeds normally
```

`PlanLimitExceededException` has its own `render()` method, so Laravel's
exception handler automatically converts it to:
```json
403 Forbidden
{ "message": "The 'max_customers' limit (50) for your current plan has been reached.",
  "error": "plan_limit_exceeded" }
```
No `if/else` for this exists in the controller — the exception's `render()`
method handles the HTTP mapping everywhere it's thrown.

---

## 8. Full Endpoint Reference (Payload → Result)

All authenticated requests need `Authorization: Bearer {token}` and
`Accept: application/json`. Base URL: `http://localhost:8000/api`.

### Public

| Endpoint | Payload | Result |
|---|---|---|
| `POST /auth/register` | See section 2 | `201` + tenant/user/token |
| `POST /auth/login` | `{email, password}` | `200` + user/token, or `422` |
| `GET /plans` | — | `200` — cached list of active plans |
| `GET /plans/{id}` | — | `200` — single plan, or `404` |

### Authenticated — Account

| Endpoint | Payload | Result |
|---|---|---|
| `GET /auth/me` | — | `200` — current user + roles |
| `POST /auth/logout` | — | `200` — current token revoked |

### Authenticated — Tenant

| Endpoint | Payload | Result |
|---|---|---|
| `GET /tenant` | — | `200` — your own company record |
| `PATCH /tenant` (admin) | `{name?, settings?}` | `200` — updated record |

### Authenticated — Subscription

| Endpoint | Payload | Result |
|---|---|---|
| `GET /subscription` | — | `200` — current plan + usage counts |
| `POST /subscription` (admin) | `{plan_id}` | `201` — new subscription, old one cancelled |

### Authenticated — Customers (any role)

| Endpoint | Payload | Result |
|---|---|---|
| `GET /customers?status=&search=&from=&to=&sort_by=&sort_dir=&per_page=` | — | `200` — paginated list |
| `POST /customers` | `{name, email, phone?}` | `201`, or `403` if plan limit hit, or `422` on bad/duplicate email |
| `GET /customers/{id}` | — | `200`, or `404` if not yours |
| `PATCH /customers/{id}` | any of `{name, email, phone, status}` | `200` |
| `DELETE /customers/{id}` | — | `200` — soft-deleted |

### Authenticated — Users (admin only)

| Endpoint | Payload | Result |
|---|---|---|
| `GET /users?per_page=` | — | `200` — paginated, roles eager-loaded |
| `POST /users` | `{name, email, password, role}` | `201`, or `403` if max_users hit |
| `PATCH /users/{id}` | any of `{name, status, role}` | `200` |
| `DELETE /users/{id}` | — | `200` |

### Authenticated — Dashboard

| Endpoint | Payload | Result |
|---|---|---|
| `GET /dashboard/summary` | — | `200` — cached 5 min, tenant-scoped counts |

Exact sample JSON bodies for every row above are in `docs/API.md` — this
table is the quick-reference; that file has the full copy-pasteable
examples.

---

## 9. Running the Whole Project, Start to Finish

```
1. Extract project, cd into it
2. cp .env.example .env
3. docker-compose up -d --build
     → builds app/queue/scheduler images (same Dockerfile, different CMD)
     → starts mysql (waits for healthcheck before app/queue/scheduler start)
     → starts redis, nginx
4. docker-compose exec app php artisan key:generate
5. docker-compose exec app php artisan migrate --seed
     → creates all tables
     → seeds: roles (admin/manager/staff), plans (Free/Pro/Enterprise)
6. curl http://localhost:8000/api/plans
     → confirms the whole stack is wired correctly
7. ./test-full-api.sh
     → exercises the entire flow described in this document and reports
       pass/fail for each step
```

At this point:
- `nginx` (port 8000) is the single entry point for HTTP
- `app` handles each request per section 1
- `queue` is continuously waiting for jobs (welcome emails)
- `scheduler` ticks every 60s, triggering `ExpireSubscriptions` hourly
- `mysql` and `redis` are the two stateful backing services

---

## 10. Where To Look When Something Breaks

| Symptom | Look at |
|---|---|
| 401 on every request | Token missing/expired — re-login |
| 400 "Unable to resolve tenant" | `IdentifyTenant` middleware couldn't find `tenant_id` on the user — check the user has one |
| 403 on a route | Either `role:admin` middleware, or `PlanLimitExceededException` — check the JSON `error` field |
| 404 for a resource you just created | Almost always tenant-scope: you're authenticated as a *different* tenant than the one that owns it (working as intended) |
| Stale dashboard numbers | Check the relevant Observer fired — `docker-compose exec app php artisan tinker` then `Cache::get('tenant:1:dashboard:summary')` |
| Job never runs | `docker-compose logs queue` — worker may have crashed; `docker-compose restart queue` |
| Migration/500 errors after pulling changes | `docker-compose exec app php artisan optimize:clear` |
