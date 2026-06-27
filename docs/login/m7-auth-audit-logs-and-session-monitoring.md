# Login Module M7 — Auth Audit Logs and Session Monitoring

**Status:** Implemented  
**Depends on:** M1 (refresh sessions), M2 (frontend refresh-on-401), M3 (PWA sync), M4 (password setup), M5 (TOTP), M6 (rate limiting, minimal audit foundation)  
**Target design reference:** [`../../../login-module.md`](../../../login-module.md)

---

## Objective

Make authentication activity **visible and defensible** for security reporting by:

1. Extending M6 `auth_audit_logs` coverage across login, OTP, refresh, logout, session revoke, and disabled-user events.
2. Providing an **Admin-only audit log API** with filtering and pagination.
3. Providing **session monitoring APIs** so users and admins can inspect and revoke refresh sessions.
4. Delivering a minimal **Admin Auth Monitoring UI** in React.

---

## Dependency summary (M1–M6)

| Milestone | M7 dependency |
|-----------|---------------|
| **M1** | Refresh sessions are listed/revoked via `refresh_tokens`; rotation unchanged |
| **M2** | Frontend continues using shared `api.js`; no refresh-token storage in JS |
| **M3** | PWA sync unaffected |
| **M4/M5** | Setup and OTP flows emit audit events at completion |
| **M6** | Existing `auth_audit_logs` table extended with `status`; event names standardized |

---

## Scope

### In scope

- Full auth audit event taxonomy with `status` field
- Metadata sanitization (no secrets/tokens/passwords/OTP)
- `GET /api/auth/audit-logs` (Admin)
- `GET /api/auth/sessions`, `DELETE /api/auth/sessions/{id}`, `POST /api/auth/logout-all`
- Admin UI at `/admin/auth-monitoring` (audit logs + sessions tabs)
- Backend and frontend tests

### Out of scope

- Public registration or self-service 2FA reset (M9)
- Route guard rewrite (M8)
- Real-time session push notifications
- Export/download of audit logs

---

## Architecture summary

```
Auth flows (login/OTP/refresh/logout)
        ↓
AuthAuditService.record(event_type, status, request, user, metadata)
        ↓
auth_audit_logs

RefreshTokenService + AuthSessionController
        ↓
GET/DELETE /api/auth/sessions, POST /api/auth/logout-all
        ↓
Admin React Auth Monitoring dashboard
```

---

## Audit event taxonomy

| Event | Status | When recorded |
|-------|--------|---------------|
| `login_password_success` | success | Valid password accepted (before OTP/setup branch) |
| `login_password_failure` | failure | Invalid credentials |
| `login_rate_limited` | blocked | Lockout threshold or already locked |
| `otp_success` | success | OTP verify success |
| `otp_failure` | failure | Invalid OTP attempt |
| `otp_challenge_locked` | blocked | OTP challenge locked at max failures |
| `password_setup_completed` | success | First-login password setup completed |
| `two_factor_setup_started` | success | TOTP setup start (secret generated) |
| `two_factor_setup_completed` | success | TOTP setup verify success |
| `refresh_success` | success | Refresh rotation success |
| `refresh_failure` | failure | Missing/invalid refresh |
| `refresh_token_reuse_detected` | blocked | Rotated token reuse |
| `logout_success` | success | Logout revokes current refresh session |
| `logout_all_success` | success | All user refresh sessions revoked |
| `session_revoked` | revoked | Individual session revoke |
| `refresh_blocked_disabled_user` | blocked | Refresh for soft-deleted user |
| `user_disabled_sessions_revoked` | revoked | Admin soft-delete revokes sessions |

M6 tests were updated to use standardized event names (e.g. `login_password_failure` instead of `login_failed`).

---

## Audit log API contract

**`GET /api/auth/audit-logs`** — Admin only (`auth:api` + `admin`)

| Query param | Description |
|-------------|-------------|
| `user_id` | Filter by user UUID |
| `email` | Partial email match |
| `action` / `event_type` | Exact event name |
| `status` | `success`, `failure`, `blocked`, `revoked` |
| `date_from`, `date_to` | Inclusive date range |
| `per_page` | Max **100**, default **25** |

Response: Laravel paginated `AuthAuditLogResource` collection with `action`, `status`, user summary, IP, user agent, safe metadata. Ordered by `occurred_at` descending.

---

## Session monitoring API contract

**`GET /api/auth/sessions`** — Authenticated

- Non-admin: own sessions only.
- Admin: all sessions, optional `user_id` filter.
- Returns session metadata only; **`token_hash` never exposed**.
- `is_current` true when session matches caller's refresh cookie.

**`DELETE /api/auth/sessions/{session}`** — Authenticated

- User may revoke own session; Admin may revoke any.
- Writes `session_revoked` audit event.
- Clears refresh cookie if current session revoked.

**`POST /api/auth/logout-all`** — Authenticated

- Revokes all refresh sessions for authenticated user.
- Clears refresh cookie.
- Writes `logout_all_success`.

---

## Authorization rules

| Endpoint | Admin | Guard / Operator |
|----------|-------|------------------|
| `GET /auth/audit-logs` | Yes | No (403) |
| `GET /auth/sessions` | All (+ filter) | Own only |
| `DELETE /auth/sessions/{id}` | Any session | Own session only |
| `POST /auth/logout-all` | Own sessions | Own sessions |

---

## Security and privacy rules

Audit metadata is sanitized before persistence. Forbidden keys/values include passwords, OTP/code fields (including nested `code`, `otp_code`, `auth_code`, and similar keys), raw/hashed tokens, setup tokens, TOTP secrets, Authorization headers, and long hex strings resembling token material. Harmless metadata such as `session_id`, `reason`, `revoked_count`, and user identifiers is retained.

HttpOnly refresh cookie behavior, rotation, M2 refresh-on-401, M5 OTP challenges, and M6 rate limiting remain unchanged.

### M7 hardening follow-up

* `two_factor_setup_started` and `user_disabled_sessions_revoked` record request IP address and user agent when triggered through the API.
* Metadata sanitization recursively redacts nested sensitive keys and generic OTP/code-like keys.

---

## Backend files changed

| File | Change |
|------|--------|
| `database/migrations/2026_07_02_100000_add_status_to_auth_audit_logs_table.php` | Added `status` column |
| `app/Services/Auth/AuthAuditService.php` | Event constants, status, metadata sanitization |
| `app/Models/AuthAuditLog.php` | `status` fillable |
| `app/Http/Controllers/Api/AuthController.php` | Full audit coverage |
| `app/Services/Auth/AuthLoginChallengeService.php` | Standardized OTP audit events |
| `app/Services/Auth/TwoFactorSetupService.php` | `two_factor_setup_started` audit |
| `app/Http/Controllers/Api/UserController.php` | Standardized disable audit |
| `app/Http/Controllers/Api/AuthAuditLogController.php` | **New** |
| `app/Http/Controllers/Api/AuthSessionController.php` | **New** |
| `app/Http/Resources/AuthAuditLogResource.php` | **New** |
| `app/Http/Resources/AuthSessionResource.php` | **New** |
| `routes/api.php` | M7 routes |
| `tests/Feature/AuthAuditLogTest.php` | **New** |
| `tests/Feature/AuthSessionMonitoringTest.php` | **New** |
| `tests/Feature/AuthRateLimitLockoutTest.php` | Updated event names |

---

## Frontend files changed

| File | Change |
|------|--------|
| `src/feature/auth-monitoring/**` | **New** module (service, repository, controllers, components, view) |
| `src/routes/MainRoutes.jsx` | `/admin/auth-monitoring` route |
| `src/menu-items/admin.js` | Auth Monitoring menu item |

Sensitive values are intentionally hidden in UI (no token hash, raw tokens, OTP, secrets).

---

## API behavior table

| Method | Endpoint | Auth | Result |
|--------|----------|------|--------|
| GET | `/api/auth/audit-logs` | Admin JWT | Paginated audit logs |
| GET | `/api/auth/sessions` | JWT | Paginated session metadata |
| DELETE | `/api/auth/sessions/{id}` | JWT | Revoke session + audit |
| POST | `/api/auth/logout-all` | JWT | Revoke all + clear cookie + audit |

---

## Testing performed

```bash
php artisan test --filter=AuthAuditLogTest
php artisan test --filter=AuthSessionMonitoringTest
php artisan test --filter=AuthRateLimitLockoutTest
php artisan test --filter=AuthTwoFactorTest
php artisan test --filter=AuthRefreshTokenTest
php artisan test --filter=AuthPasswordSetupTest
php artisan test --filter=PatrolTokenExpiryTest
```

Frontend:

```bash
yarn test --run src/feature/auth-monitoring
yarn build
```

---

## Manual QA checklist

- [ ] Admin opens `/admin/auth-monitoring` and sees audit logs after failed/successful login
- [ ] Audit filters by action/status/email/date work
- [ ] Sessions tab lists refresh sessions without token hash
- [ ] Revoke session makes refresh fail
- [ ] Logout-all clears sessions and cookie
- [ ] Guard receives 403 on audit log API

---

## Known limitations / deferred work

- No audit log export or alerting (future reporting)
- Admin cannot `logout-all` for another user via API (by design unless extended in M9)
- Session tab lists rotated/revoked rows for investigation; active-only filter UI deferred
