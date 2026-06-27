# M0 — Login Module Architecture Baseline and Current Auth Audit

**Milestone:** M0  
**Status:** Complete (documentation and audit baseline only)  
**Related planning document:** [`login-module.md`](../../../login-module.md)  
**Repositories:** `backend-laravel-v1`, `frontend-react-v1`  
**Date context:** FYP Login Module — secure authentication and session architecture  

---

## 1. Milestone Summary

M0 documents the **current** authentication and authorization behavior across the Laravel API and React PWA client. It confirms gaps against the target Login Module design and defines the recommended migration path for M1–M7.

**Deliverables:**

- This audit document (`docs/login/m0-auth-baseline-and-current-audit.md`).
- Verified inventory of auth endpoints, token storage, protected APIs, and error behavior.
- Gap analysis and future milestone order aligned with [`login-module.md`](../../../login-module.md).

**Explicitly out of scope for M0:** refresh tokens, OTP/2FA enforcement, auth audit logs, new migrations, new auth endpoints, login UI changes, and any functional auth behavior changes.

---

## 2. Scope

### In scope

- Backend: `AuthController`, `routes/api.php`, `config/auth.php`, `config/jwt.php`, `User` model, JWT middleware, admin middleware, patrol monitoring authorization, and all routes under `auth:api`.
- Frontend: `api.js`, `utils/auth.js`, `AuthLogin.jsx`, `feature/authentication/`, route guards, menu role filtering, PWA sync, and service modules that call protected APIs.
- Gap analysis and migration path for Login Module milestones M1–M7.

### Out of scope

- Implementing refresh sessions, TOTP, rate limiting, audit logging, or password-setup flows.
- Database schema changes for `refresh_tokens`, `auth_audit_logs`, or login security fields.
- Modifying login UI, API client refresh logic, or patrol sync retry semantics.

---

## 3. Architecture Baseline

The FYP platform today uses a **single JWT access token** model:

```text
React PWA / Dashboard
        ↓
POST /api/auth/login (email + password)
        ↓
JWT access token + user profile returned in JSON
        ↓
Frontend stores access_token + auth_user in localStorage
        ↓
Authorization: Bearer <token> on protected Laravel routes (auth:api)
        ↓
401 → clear localStorage + redirect to /login (no refresh retry)
```

**Security authority:** Laravel validates credentials, issues JWTs, and enforces `auth:api`, `admin`, and patrol-monitoring authorization. React route guards and sidebar filtering improve UX but are not the authoritative security layer.

**Target direction (future milestones):** short-lived access JWT + DB-backed refresh session (HttpOnly cookie) + mandatory TOTP + audit logs + rate limiting. Refresh-token session safety must be implemented **before** mandatory 2FA enforcement so patrol/PWA sync survives access-token expiry during active shifts.

---

## 4. Backend Audit

### 4.1 Current auth endpoints

| Method | Path | Auth required | Handler | Purpose |
| --- | --- | --- | --- | --- |
| `POST` | `/api/auth/login` | No | `AuthController@login` | Email/password login; issues JWT |
| `GET` | `/api/auth/me` | Yes (`auth:api`) | `AuthController@me` | Returns authenticated user profile |
| `POST` | `/api/auth/logout` | Yes (`auth:api`) | `AuthController@logout` | Invalidates current JWT (blacklist) |

**Not implemented today:**

- `POST /api/auth/refresh`
- `POST /api/auth/otp/verify`
- Password-setup or 2FA setup endpoints
- Session list/revoke or audit-log endpoints

There is **no** `/api/login` route in `routes/api.php`. The frontend retains a legacy fallback to `POST /login` only when `POST /auth/login` returns **404**.

### 4.2 Login endpoint behavior

**File:** `app/Http/Controllers/Api/AuthController.php`

1. Validates `email` (required, email) and `password` (required, string).
2. Calls `auth('api')->attempt($credentials)`.
3. On validation failure → **422** with `{ success: false, message: 'Validation failed.', data: { errors: … } }`.
4. On invalid credentials (`attempt` returns `false`) → **401** with `{ success: false, message: 'Invalid credentials.', data: null }`.
5. On success → **200** with:

```json
{
  "success": true,
  "message": "Login successful.",
  "data": {
    "access_token": "<jwt>",
    "token_type": "bearer",
    "expires_in": 3600,
    "user": { "...UserResource..." },
    "role": "Guard"
  }
}
```

**Notes:**

- `expires_in` is **seconds** (`auth('api')->getTTL() * 60`).
- `user` is serialized via `UserResource` with `role` relation loaded.
- `role` is also duplicated as a top-level string (`$user->role->name`).
- No OTP challenge, no setup-required state, no refresh cookie.
- No explicit check of `two_factor_enabled` during login.
- No dedicated `is_active` field; soft-deleted users are excluded by Eloquent default scope during credential lookup.

### 4.3 Logout endpoint behavior

1. Calls `auth('api')->logout()` to invalidate the JWT (requires `JWT_BLACKLIST_ENABLED=true`, default **true** in `config/jwt.php`).
2. Success → **200** `{ success: true, message: 'Logout successful.', data: null }`.
3. `JWTException` (e.g. already invalid/expired token) → **401** `{ success: false, message: 'Invalid or expired token.', data: null }`.

Logout revokes **only the presented JWT** via the package blacklist. There is no refresh-session revocation because refresh sessions do not exist.

### 4.4 `auth/me` behavior

1. Loads authenticated user with `role` relation.
2. Returns **200**:

```json
{
  "success": true,
  "message": "Authenticated user retrieved successfully.",
  "data": {
    "user": { "...UserResource..." },
    "role": "Admin"
  }
}
```

No separate token metadata is returned. The frontend does **not** call `/auth/me` on app load today; session restoration relies on `localStorage` only.

### 4.5 JWT guard configuration

**File:** `config/auth.php`

| Setting | Value |
| --- | --- |
| Default guard | `web` (session) — not used for API |
| API guard name | `api` |
| API driver | `jwt` |
| Provider | `users` → Eloquent `App\Models\User` |

**File:** `config/jwt.php` (selected settings)

| Setting | Source | Default / notes |
| --- | --- | --- |
| `ttl` | `JWT_TTL` env (minutes) | **60** minutes if unset |
| `refresh_ttl` | `JWT_REFRESH_TTL` env | **20160** minutes (14 days) — package config only; **no refresh API exposed** |
| `blacklist_enabled` | `JWT_BLACKLIST_ENABLED` | **true** |
| `algo` | `JWT_ALGO` | HS256 |
| `secret` | `JWT_SECRET` | Required for signing |

**User model:** `App\Models\User` implements `JWTSubject` with empty custom claims (`getJWTCustomClaims()` returns `[]`). Role is **not** embedded in the JWT payload; authorization uses DB-loaded role relations.

**UserResource fields exposed:** `id`, `name`, `email`, `phone`, `address`, `profile_picture_url`, `two_factor_enabled`, `email_verified_at`, nested `role`, timestamps, `deleted_at`.

### 4.6 Protected route groups

All routes below require `Authorization: Bearer <token>` via `Route::middleware('auth:api')` in `routes/api.php`.

| Group | Routes | Additional authorization |
| --- | --- | --- |
| Auth lifecycle | `GET auth/me`, `POST auth/logout` | JWT only |
| Broadcasting | `POST broadcasting/auth` | JWT; channel rules in `routes/channels.php` |
| Blockchain records | `GET/POST blockchain-records/*` | `AuthorizesPatrolMonitoring` (Admin + Security Operator); `POST …/retry` also requires `admin` middleware |
| Admin CRUD | `roles`, `users`, `vehicles` (incl. restore) | `admin` middleware |
| Facility / patrol data | `cameras`, `zones`, `checkpoints`, `patrol-sessions`, `patrol-routes`, `checkpoint-events`, `checkpoint-event-metrics`, `location-logs` | JWT only at route level; monitoring **read** endpoints call `authorizePatrolMonitoring()` |
| PWA / push | `POST pwa/sync`, push subscription CRUD, `POST push-notifications/test` | JWT only |
| ANPR | `anpr-events`, `anpr-event-logs`, `anpr-images`, image upload/file | JWT only (any authenticated role) |

**Patrol monitoring authorization** (`PatrolChannelAuthorizer::canAccessPatrolMonitoring`): Admin or Security Operator only. Applied via `AuthorizesPatrolMonitoring` on:

- `PatrolSessionController`: `index`, `show` (not `store`, `update`, `destroy`, `summary`, `validateSession`)
- `PatrolRouteController`: `index`
- `CheckpointEventController`: `index`
- `BlockchainRecordController`: all actions

Guards may **create/update** patrol sessions, post routes, checkpoint events, and use PWA sync without passing patrol-monitoring checks.

### 4.7 Admin-only route protection

**Middleware:** `EnsureUserIsAdmin` (alias `admin`)

- Requires authenticated API user with role name `Admin` (case-insensitive).
- Non-admin → **403** `{ success: false, message: 'Only administrators may perform this action.', data: null }`.

**Route group usage:** `users`, `roles`, `vehicles`, `POST blockchain-records/{id}/retry`.

**Controller-level usage:** `ZoneController` applies `EnsureUserIsAdmin` to `store`, `update`, `destroy` only (list/show remain any authenticated user).

### 4.8 Error behavior summary

| Condition | HTTP | Message (typical) | Handler |
| --- | --- | --- | --- |
| Invalid login credentials | 401 | `Invalid credentials.` | `AuthController@login` |
| Login validation failure | 422 | `Validation failed.` | `AuthController@login` |
| Missing/invalid Bearer token | 401 | `Unauthenticated.` | `AuthenticationException` in `bootstrap/app.php` |
| Expired or malformed JWT | 401 | `Invalid or expired token.` (or debug message) | `JWTException` renderer |
| Logout with invalid JWT | 401 | `Invalid or expired token.` | `AuthController@logout` |
| Non-admin on admin route | 403 | `Only administrators may perform this action.` | `EnsureUserIsAdmin` |
| Guard on patrol monitoring API | 403 | `Only administrators and security operators may perform this action.` | `AuthorizesPatrolMonitoring` |
| Unauthorized role (Reverb channel) | 403 | Channel authorization denied | `Broadcast::channel` callbacks |

### 4.9 Features confirmed absent

| Feature | Status | Evidence |
| --- | --- | --- |
| Refresh tokens (DB-backed or API) | **Not implemented** | No `refresh_tokens` table, model, or `/auth/refresh` route |
| Auth audit logs | **Not implemented** | No `auth_audit_logs` table or `AuthAuditService` |
| Mandatory 2FA at login | **Not enforced** | `two_factor_enabled` / `two_factor_secret` columns exist; login issues JWT without OTP |
| First-login password setup | **Not implemented** | No `setup_required` field or setup endpoints |
| Login/OTP rate limiting | **Not implemented** | No dedicated throttle on `auth/login` |
| Disabled-user session invalidation | **Partial / implicit only** | Users use **soft deletes** (`deleted_at`); no `is_active` flag or refresh revocation |
| Password-change session invalidation | **Not implemented** | `last_password_changed_at` exists; no token/session invalidation on password update |

**JWT package refresh config:** `config/jwt.php` defines `refresh_ttl`, but the application does not expose the jwt-auth refresh flow through a controller endpoint.

---

## 5. Frontend Audit

### 5.1 Login page flow

**UI wrapper:** `views/pages/authentication/Login.jsx`  
**Form:** `views/pages/auth-forms/AuthLogin.jsx`

1. User submits email and password (client-side validation).
2. `submitLogin()` calls `api.post('/auth/login', payload)`.
3. If response is **404**, retries `api.post('/login', payload)` (legacy compatibility; current backend only exposes `/auth/login`).
4. On success, reads `response.data.data.access_token` and `response.data.data.user`.
5. Persists via `setAuthToken()` → `localStorage['access_token']` and `setAuthUser()` → `localStorage['auth_user']`.
6. Navigates to `getDefaultRouteForRole(role)`:
   - Admin → `/dashboard`
   - Security Operator → `/admin/patrol-monitoring`
   - Guard → `/patrol`
7. On **401**, clears password field and shows backend message (typically `Invalid credentials.`).

No OTP screen, no first-login setup routing, and no call to `/auth/me` after login.

### 5.2 Token and user storage

| Item | Storage | Key / location |
| --- | --- | --- |
| Access token | **`localStorage` (persisted)** | `access_token` (`AUTH_TOKEN_KEY`) |
| User profile | **`localStorage` (persisted)** | `auth_user` (`AUTH_USER_KEY`, JSON) |

Access token is **not** memory-only. Refresh token is **not** stored (none issued).

### 5.3 Protected route validation

**`ProtectedRoute`:** requires `hasAuthToken()` and `validateAuthSession()` (token **and** parseable `auth_user`). Invalid → `clearAuthSession()` → `/login`.

**`RoleProtectedRoute`:** same session checks, then `hasAnyRole(allowedRoles)`. Missing role → `/login`; wrong role → `/forbidden`.

**`GuestRoute`:** authenticated valid session → role default route.

**`RoleHomeRedirect`:** `/` index sends users to role default route.

Route guards do **not** validate JWT expiry server-side; they only check localStorage presence.

### 5.4 Sidebar / menu role filtering

**File:** `menu-items/getMenuItemsForRole.js`

| Role | Menu groups |
| --- | --- |
| Guard | `guard` only |
| Security Operator | `patrolHome` + operator monitoring subset (patrol, ANPR, blockchain monitoring) |
| Admin | `dashboard`, `patrolHome`, full `operator`, full `admin` |
| Unknown / missing | Empty menu |

Used by `layout/MainLayout/MenuList/index.jsx` via `getAuthUserRole()` from `localStorage`.

### 5.5 Logout flow

**File:** `feature/authentication/controllers/useAuthController.js`

1. Profile menu triggers `handleLogout()`.
2. Calls `POST /auth/logout` via `authService` → shared `api.js`.
3. On network/server errors (except expected 401), shows a local sign-out warning.
4. **Always** in `finally`: disconnect Reverb (`broadcastService.disconnect()`), `clearAuthSession()`, navigate to `/login`.
5. Cross-tab sync: `storage` event listener clears session and redirects when `access_token` or `auth_user` is removed in another tab.

### 5.6 `401` behavior in `api.js`

**File:** `src/api/api.js`

On **any** response with status **401**:

1. `clearAuthSession()` — removes `access_token` and `auth_user`.
2. `window.location.replace('/login')` if not already on `/login`.
3. Throws `Error('Unauthorized')` **without** attaching `error.status`.

**Refresh-on-401:** **Not implemented.** There is no call to `/auth/refresh`, no retry of the original request, and no refresh queue.

### 5.7 PWA sync and shared API client

**File:** `src/pwa/syncService.js`

- `flushSyncQueue()` processes IndexedDB `sync_queue` items sequentially.
- Each item calls `api.post('/pwa/sync', item.payload)` through the **shared** `api.js` client.
- **No separate token logic** in the sync layer.

**When access token expires during sync:**

1. `api.js` receives **401**, clears session, redirects to `/login`.
2. Thrown error has message `'Unauthorized'` but typically **no** `error.status`.
3. `syncService` treats this as a **transient failure** (not 422/409), increments `retryCount`, marks queue item `failed`, and may register background sync for retry (up to `MAX_SYNC_RETRY_COUNT = 5`).
4. Unsynced location logs remain in IndexedDB; they are **not** deleted on auth failure.
5. Without refresh tokens, retries continue to fail until the user logs in again and obtains a new JWT.

Push subscription APIs (`pushNotificationService.js`) also use `api.js` and are subject to the same **401** behavior.

---

## 6. Protected Module Dependency Audit

All modules below depend on a valid JWT for their API calls. When the access token expires (default **60 minutes** unless `JWT_TTL` is changed), calls return **401** and the frontend clears the session without silent recovery.

| Module | Protected API endpoints (via `api.js`) | Why token expiry matters |
| --- | --- | --- |
| **Patrol start/stop/session** | `POST /patrol-sessions`, `PUT /patrol-sessions/{id}`, `GET /patrol-sessions/{id}/summary`, `POST /patrol-sessions/{id}/validate` | Guard cannot start, update, or finalize patrol without valid JWT |
| **PWA sync** | `POST /pwa/sync` | Offline location evidence cannot upload; queue entries fail/retry until re-login |
| **Patrol routes / breadcrumbs** | `POST /patrol-routes` | Live track points cannot be appended after expiry |
| **Checkpoint events (patrol)** | `POST /checkpoint-events`, `PATCH /checkpoint-events/{id}` | Provisional checkpoint metadata cannot be sent during patrol |
| **Zone / checkpoint load (patrol)** | `GET /zones`, `GET /checkpoints?zone_id=` | Patrol home cannot load zone/checkpoint data |
| **Patrol monitoring** | `GET /patrol-sessions`, `GET /patrol-sessions/{id}`, summary, validate, `GET /patrol-routes`, `GET /checkpoint-events`, `GET /zones` | Operator dashboards stop updating; validation actions fail |
| **Reverb / live monitoring** | `POST /broadcasting/auth` | WebSocket private channels disconnect on auth loss |
| **ANPR monitoring** | `GET /anpr-events`, `GET /anpr-events/{id}`, `GET /anpr-images` | Live polling and evidence fetch stop |
| **Blockchain monitoring** | `GET /blockchain-records`, summary, show, `POST …/verify`, `POST …/refresh`, `POST …/retry` (admin) | Monitoring and operator actions fail mid-session |
| **User / role management** | `GET/POST/PATCH/DELETE /users`, `GET /roles` | Admin CRUD unavailable after expiry |
| **Zone / checkpoint / vehicle admin** | `/zones`, `/checkpoints`, `/vehicles` CRUD | Admin mutations and lists fail |
| **Camera management** | `/cameras` CRUD (patrol-live-tracking module) | Camera admin/list fails |
| **Push subscriptions** | `POST /push-subscriptions`, `DELETE /push-subscriptions/{id}`, `POST /push-notifications/test` | Web Push registration/test fails |
| **Logout** | `POST /auth/logout` | Expected 401 if token already expired; local cleanup still runs |

**Highest operational risk:** Guard **PWA patrol + sync** during shifts longer than `JWT_TTL`, because there is no refresh path and **401** forces full re-login while local evidence accumulates unsynced.

---

## 7. Gap Analysis

| Area | Current behavior | Target Login Module behavior | Risk | Future milestone |
| --- | --- | --- | --- | --- |
| Access token storage | JWT in `localStorage` (`access_token`) | Short-lived JWT in memory; optional safe user persistence | XSS can exfiltrate long-lived bearer token from storage | M2 |
| Refresh token absence | Single JWT only; no `/auth/refresh` | DB-backed opaque refresh token in HttpOnly cookie with rotation | Patrol/monitoring interrupted at JWT expiry | M1, M2 |
| 401 redirect behavior | Immediate `clearAuthSession()` + redirect; no retry | Refresh once, retry original request, redirect only if refresh fails | Active workflows abort on transient expiry | M2 |
| PWA sync continuity | Uses `api.js`; 401 clears session; queue retries fail without new token | Silent refresh before sync retry | Unsynced patrol evidence during long shifts | M1, M2 (before 2FA) |
| 2FA absence | Columns exist; login does not require OTP | Mandatory TOTP on every login | Stolen password alone grants API access | M5 |
| First-login setup absence | Admin-created users login immediately with password | Forced password setup + 2FA enrollment | Weak/default credentials may remain | M4 |
| Audit log absence | No centralized auth event logging | `auth_audit_logs` for login, OTP, refresh, logout, lockout | No forensic trail for FYP security evaluation | M3 |
| Login/OTP rate limiting gap | No dedicated auth throttling | Email/IP lockout and OTP attempt limits | Brute-force exposure on public login | M3 |
| Disabled-user / session invalidation gap | Soft delete only; no refresh revocation | Block login/refresh; revoke all sessions on disable | Disabled accounts may retain valid JWT until TTL | M6 |
| Password-change invalidation gap | `last_password_changed_at` stored; no session revoke | Revoke refresh families on password change | Old sessions remain valid until JWT expires | M6 |
| Multi-tab / session consistency | `storage` event sync on logout; no server session list | Session management UI + revoke-by-device | Stale tabs may hold token until expiry or manual logout | M6 |

---

## 8. Migration Path

Recommended implementation order (aligned with [`login-module.md`](../../../login-module.md) and this audit):

| Order | Milestone | Goal |
| --- | --- | --- |
| 1 | **M1 — Laravel Session Foundation and Refresh Tokens** | `refresh_tokens` table, `RefreshTokenService`, `POST /auth/refresh`, logout revocation, HttpOnly cookie |
| 2 | **M2 — Frontend Refresh Client and Session State** | `api.js` refresh-on-401 with shared refresh queue; move access token toward memory; session-expired UX |
| 3 | **M3 — Auth Audit Logs and Rate Limiting** | `auth_audit_logs`, `AuthAuditService`, login/OTP throttling |
| 4 | **M4 — First-Login Password Setup** | `setup_required`, setup tokens, first-login UI flow |
| 5 | **M5 — Mandatory 2FA** | TOTP setup + OTP login challenge |
| 6 | **M6 — Session Management, Revocation, and Hardening** | Disabled-user enforcement, password-change invalidation, session list/revoke, middleware hardening |
| 7 | **M7 — Final Testing and Security Review** | End-to-end auth tests, manual demo checklist, documentation freeze |

**Critical sequencing rule:** **M1 and M2 (refresh-token session safety) must be complete before M5 (mandatory 2FA enforcement).** Patrol and PWA sync depend on silent access-token renewal during active shifts. Enforcing OTP on every login before refresh exists would compound mid-shift auth failures without fixing the underlying expiry problem.

---

## 9. Passing Criteria

M0 is complete when:

- [x] `backend-laravel-v1/docs/login/m0-auth-baseline-and-current-audit.md` exists.
- [x] Current auth endpoints are listed (`/auth/login`, `/auth/me`, `/auth/logout`).
- [x] Current token storage (`localStorage`) and **401** behavior (clear + redirect, no refresh) are documented.
- [x] Protected patrol/PWA and monitoring APIs affected by token expiry are identified.
- [x] Refresh tokens are confirmed **not implemented**.
- [x] 2FA is confirmed **not enforced** at login.
- [x] Future migration path M1–M7 is defined with refresh-before-2FA ordering.
- [x] No functional auth behavior was changed.
- [x] No migrations or new auth endpoints were added.
- [x] Existing automated tests still pass (see Verification Notes).

---

## 10. Manual Verification Checklist

Use this checklist to validate the documented baseline without implementing new auth features:

- [ ] `POST /api/auth/login` with valid credentials returns `access_token`, `expires_in`, `user`, and `role`.
- [ ] `POST /api/auth/login` with invalid password returns **401** `Invalid credentials.`
- [ ] Protected `GET /api/auth/me` without token returns **401** `Unauthenticated.`
- [ ] Protected route with expired JWT returns **401** `Invalid or expired token.`
- [ ] Non-admin `GET /api/users` returns **403**.
- [ ] Guard `GET /api/patrol-sessions` (monitoring index) returns **403**.
- [ ] Guard `POST /api/patrol-sessions` succeeds with valid JWT.
- [ ] React login stores `access_token` and `auth_user` in `localStorage`.
- [ ] Simulated API **401** from any module triggers redirect to `/login` and clears storage.
- [ ] PWA `flushSyncQueue()` calls `POST /pwa/sync` through `api.js` (not a separate fetch client).
- [ ] Logout clears local storage and calls `POST /auth/logout` when token is valid.
- [ ] Confirm no `/api/auth/refresh` route exists in `routes/api.php`.

---

## 11. Verification Notes

Commands run in the local environment on **2026-06-27**:

| Command | Result |
| --- | --- |
| `php artisan test` (backend) | **Passed** — 348 tests, 1139 assertions (~404s) |
| `yarn test --run` (frontend) | **Passed** — 7 files, 48 tests (~14s) |
| `yarn build` (frontend) | **Passed** — Vite production build + PWA service worker generated (~20s) |

No verification blockers were encountered. Backend test runtime is long (~7 minutes) due to full suite size; this is environmental, not an auth regression.

---

## 12. M0 Conclusion

The FYP platform has a **working JWT baseline**: Laravel issues bearer tokens, protects operational modules with `auth:api`, and React enforces route-level access with role guards and menu filtering. Logout blacklists the current JWT, and global **401** handling clears client session state.

The baseline is **insufficient for the target Login Module** in four critical areas: **no refresh sessions**, **no silent token renewal for PWA patrol**, **no mandatory 2FA or first-login setup**, and **no auth audit or rate limiting**. The highest operational risk is **patrol continuity during JWT expiry**, which must be addressed in **M1–M2 before M5**.

M0 makes no code or schema changes. Implementation should proceed milestone-by-milestone per Section 8, with tests and documentation updates after each completed milestone.

---

## Appendix A — Current auth endpoint quick reference

```text
POST /api/auth/login          → public; returns JWT + user
GET  /api/auth/me             → auth:api
POST /api/auth/logout         → auth:api
POST /api/broadcasting/auth   → auth:api (Reverb)
POST /api/pwa/sync            → auth:api
… all other /api/* resources in auth:api group (see routes/api.php)
```

## Appendix B — Related documentation

- [`login-module.md`](../../../login-module.md) — full target architecture and extended milestone detail
- [`backend-laravel-v1/documentation.md`](../../documentation.md) — Section 6 Authentication & authorization
- [`frontend-react-v1/documentation.md`](../../../frontend-react-v1/documentation.md) — Section 6 Authentication & Authorization
