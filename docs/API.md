# API Documentation

Base URL: `http://localhost:8000/api`
Auth: Bearer token (Sanctum) in `Authorization: Bearer {token}` header, except where noted "Public".

---

## Authentication

### Register a new company (tenant) — Public
`POST /auth/register`

Request:
```json
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

Response `201`:
```json
{
  "message": "Company registered successfully.",
  "tenant": { "id": 1, "name": "Acme Inc", "slug": "acme", "status": "active" },
  "user": { "id": 1, "tenant_id": 1, "name": "Alice Admin", "email": "alice@acme.com" },
  "token": "1|abcdef123456..."
}
```

### Login — Public
`POST /auth/login`
```json
{ "email": "alice@acme.com", "password": "password123" }
```
Response `200`: `{ "user": {...}, "token": "2|xyz..." }`
Response `422` on bad credentials.

### Current user
`GET /auth/me` → `200` `{ "id": 1, "name": "...", "roles": [{ "name": "admin" }] }`

### Logout
`POST /auth/logout` → `200` `{ "message": "Logged out." }`

---

## Plans — Public, cached

`GET /plans`
```json
{
  "data": [
    { "id": 1, "name": "Free", "slug": "free", "price": "0.00",
      "feature_limits": { "max_users": 3, "max_customers": 50, "api_rate_limit": 30 } },
    { "id": 2, "name": "Pro", "slug": "pro", "price": "29.00",
      "feature_limits": { "max_users": 15, "max_customers": 1000, "api_rate_limit": 120 } }
  ]
}
```

---

## Tenant (Company)

### Get current company
`GET /tenant` → `200` `{ "id": 1, "name": "Acme Inc", "slug": "acme", ... }`

### Update company — admin only
`PATCH /tenant`
```json
{ "name": "Acme Incorporated", "settings": { "timezone": "Asia/Dhaka" } }
```

---

## Subscription

### Current subscription + usage
`GET /subscription`
```json
{
  "plan": { "id": 1, "name": "Free", "feature_limits": { "max_users": 3, "max_customers": 50 } },
  "usage": { "users": 2, "customers": 17 }
}
```

### Change plan — admin only
`POST /subscription`
```json
{ "plan_id": 2 }
```
Response `201`:
```json
{
  "message": "Subscription updated.",
  "subscription": { "id": 5, "tenant_id": 1, "plan_id": 2, "status": "active",
    "starts_at": "2026-09-17T10:00:00+00:00", "ends_at": "2026-10-17T10:00:00+00:00",
    "plan": { "id": 2, "name": "Pro" } }
}
```

---

## Customers (full CRUD, tenant-scoped)

### List — paginated, filterable, sortable
`GET /customers?status=active&search=john&sort_by=created_at&sort_dir=desc&per_page=15`

Query params:
| Param | Description |
|---|---|
| `status` | `active` or `inactive` |
| `search` | matches name or email |
| `from` / `to` | date range on `created_at` (`YYYY-MM-DD`) |
| `sort_by` | `created_at`, `name`, or `status` |
| `sort_dir` | `asc` or `desc` |
| `per_page` | default 15, max 100 |

Response `200`:
```json
{
  "data": [
    { "id": 1, "name": "John Doe", "email": "john@example.com", "phone": "+8801...",
      "status": "active", "created_at": "2026-09-01T08:00:00+00:00" }
  ],
  "links": { "first": "...", "last": "...", "prev": null, "next": null },
  "meta": { "current_page": 1, "per_page": 15, "total": 1 }
}
```

### Create
`POST /customers`
```json
{ "name": "John Doe", "email": "john@example.com", "phone": "+8801700000000" }
```
Response `201` — customer object. Response `403` if the tenant's plan customer limit is reached:
```json
{ "message": "The 'max_customers' limit (50) for your current plan has been reached.",
  "error": "plan_limit_exceeded" }
```

### Show / Update / Delete
`GET /customers/{id}`, `PATCH /customers/{id}`, `DELETE /customers/{id}`
A request for another tenant's customer ID returns `404`.

---

## Users (tenant's internal team) — admin only

### List
`GET /users?per_page=15` → paginated users with `roles` eager-loaded.

### Create
`POST /users`
```json
{ "name": "Bob Manager", "email": "bob@acme.com", "password": "password123", "role": "manager" }
```
`403` if the tenant's `max_users` plan limit is reached.

### Update
`PATCH /users/{id}`
```json
{ "status": "disabled", "role": "staff" }
```

### Delete
`DELETE /users/{id}`

---

## Dashboard

`GET /dashboard/summary` (cached 5 minutes, tenant-scoped)
```json
{
  "plan": "Pro",
  "total_users": 4,
  "total_customers": 213,
  "active_customers": 198,
  "limits": { "max_users": 15, "max_customers": 1000 },
  "generated_at": "2026-09-17T10:15:00+00:00"
}
```

---

## Error format
Validation errors (`422`):
```json
{ "message": "The email field is required.", "errors": { "email": ["The email field is required."] } }
```
Auth errors (`401`): `{ "message": "Unauthenticated." }`
Authorization errors (`403`): `{ "message": "This action is unauthorized." }`
Rate limit exceeded (`429`): `{ "message": "Too Many Attempts." }`

---

## Rate limits
- `/auth/login`: 5 requests/minute per IP
- `/auth/register`: 3 requests/minute per IP
- All other authenticated endpoints: scaled by the tenant's plan `api_rate_limit`
  (Free: 30/min, Pro: 120/min, Enterprise: 600/min), keyed per user.
