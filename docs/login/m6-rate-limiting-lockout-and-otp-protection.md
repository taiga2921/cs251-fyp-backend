# Login Module M6 — Rate Limiting, Lockout, and OTP Protection

**Status:** Implemented  
**Depends on:** M1 (refresh sessions), M2 (frontend refresh-on-401), M3 (PWA sync via `api.js`), M4 (first-login password setup), M5 (mandatory TOTP 2FA)  
**Target design reference:** [`../../../login-module.md`](../../../login-module.md)

---

## Objective

Harden authentication endpoints against brute-force and abuse while preserving M1–M5 behavior:

1. Login rate limiting by normalized email + IP with temporary lockout after repeated credential failures.
2. OTP challenge protection and expiry hardening with audit logging for failed and locked challenges.
3. Disabled-user enforcement using **soft-deleted users** as authentication-disabled accounts.
4. Minimal backend audit logging for blocked auth attempts (foundation for M7 reporting).

Email/password login still does not issue JWT or refresh cookies until OTP or 2FA setup verification succeeds.

---

## Scope

### In scope (M6)

| Area | Delivered |
|------|-----------|
| Login rate limiter | `LoginRateLimiter` — email + IP scoped, hashed cache keys, configurable max attempts and lock duration |
| Login lockout | **429** after threshold; safe generic message; optional `retry_after` seconds |
| Audit foundation | `auth_audit_logs` table, `AuthAuditLog` model, `AuthAuditService` |
| M6 audit events | `login_failed`, `login_rate_limited`, `otp_failed`, `otp_challenge_locked`, `refresh_blocked_disabled_user`, `user_disabled_sessions_revoked` |
| OTP hardening | Existing M5 challenge lockout retained; audit calls added |
| Disabled users | Soft-deleted users cannot login, refresh, or use protected APIs; admin delete revokes refresh tokens |
| Frontend | Minimal: show lockout message, clear password on **401** and **429** |
| Tests | `AuthRateLimitLockoutTest`, frontend Vitest for lockout UX |

### Out of scope (M7+)

- Recovery codes and public 2FA reset
- Full auth session management UI or admin audit dashboard
- AuthContext rewrite or memory-only access-token migration
- User-level (non-IP) permanent account lockout beyond cache-based login lockout
- Comprehensive audit logging for all auth success paths (M7)

---

## Dependency summary (M1–M5)

| Milestone | M6 dependency |
|-----------|---------------|
| **M1** | Refresh revocation on disabled user; HttpOnly cookie clearing on failed refresh |
| **M2** | `/auth/login` and semi-public auth paths must not trigger refresh-on-401 (unchanged); **429** from login also must not trigger refresh |
| **M3** | PWA sync continues using shared `api.js` |
| **M4** | Password setup branch unchanged; failed setup login attempts count toward rate limit like any credential failure |
| **M5** | OTP challenge failed-attempt tracking extended with audit only; no duplicate counter logic |

---

## Architecture summary

```
POST /api/auth/login
        ↓
LoginRateLimiter::ensureNotLocked(email, IP)
        ↓ (locked → audit login_rate_limited → 429)
Hash::check credentials
        ↓ (fail → record attempt → audit login_failed → 401 or 429 at threshold)
        ↓ (success → clear limiter → M5 branching)
POST /api/auth/otp/verify
        ↓
AuthLoginChallengeService (M5 lockout + M6 audit)
        ↓
issueAuthenticatedSession (unchanged)

DELETE /api/users/{id}  (soft delete)
        ↓
RefreshTokenService::revokeAllForUser
        ↓
audit user_disabled_sessions_revoked
```

**Disabled-user rule (project-specific):** A **soft-deleted user** (`users.deleted_at` set via `UserController::destroy()`) is treated as disabled for authentication. No separate `is_active` column was added.

---

## Configuration

| Key | Env variable | Default | Purpose |
|-----|--------------|---------|---------|
| `login_max_attempts` | `AUTH_LOGIN_MAX_ATTEMPTS` | `5` | Failed password attempts before lockout (email + IP) |
| `login_lock_minutes` | `AUTH_LOGIN_LOCK_MINUTES` | `15` | Temporary login lock duration |
| `otp_max_attempts` | `AUTH_OTP_MAX_ATTEMPTS` | `5` | Failed OTP attempts before challenge lock (M5, unchanged) |
| `otp_challenge_ttl_minutes` | `AUTH_OTP_CHALLENGE_TTL` | `5` | Login challenge expiry (M5, unchanged) |

Cache driver stores hashed login limiter keys (`auth_login:{sha256}`). Raw emails are not used in cache keys.

---

## Login rate limiting behavior

1. Email is normalized: `strtolower(trim($email))`.
2. Scope: normalized email **and** client IP (`Request::ip()`).
3. Before credential validation, `ensureNotLocked()` runs. Locked requests:
   - Write `login_rate_limited` audit row
   - Return **429** with message: `Too many unsuccessful sign-in attempts. Please try again later.`
   - Optional `data.retry_after` (seconds)
4. Invalid credentials (unknown email, wrong password, or soft-deleted user — indistinguishable):
   - Increment failed attempt counter
   - Write `login_failed` audit row
   - Attempts 1–4: **401** `Invalid credentials.`
   - Attempt 5 (threshold): lock and return **429**
5. Valid password: clear limiter for that email + IP; continue M5 branching (no JWT until OTP/setup verify).
6. No response reveals whether the account exists, is disabled, or has 2FA enabled.

---

## OTP protection behavior

Retained from M5 with M6 audit additions:

| Rule | Behavior |
|------|----------|
| Challenge TTL | `AUTH_OTP_CHALLENGE_TTL` minutes |
| Max failures | `AUTH_OTP_MAX_ATTEMPTS`; challenge `locked_at` set at threshold |
| Expired / consumed / locked | **422** generic: `The authentication code is invalid or expired.` |
| Success | Challenge marked consumed immediately; JWT + refresh cookie issued |
| Audit | `otp_failed` on each invalid code; `otp_challenge_locked` when threshold reached |
| Enumeration | No remaining-attempt counts in API responses |

Two-factor **setup** sessions (M5) continue using the same `AUTH_OTP_MAX_ATTEMPTS` on `two_factor_setup_sessions` without additional M6 changes.

---

## Disabled-user enforcement behavior

| Action | Result |
|--------|--------|
| Login (soft-deleted) | User not found by default Eloquent query → **401** `Invalid credentials.` |
| Refresh (soft-deleted) | Token validated, user loaded with `withTrashed()`; if trashed → revoke token, audit `refresh_blocked_disabled_user`, **401**, clear cookie |
| `DELETE /api/users/{id}` | `revokeAllForUser()` then soft delete; audit `user_disabled_sessions_revoked` |
| Protected API with existing JWT | JWT provider cannot resolve soft-deleted user → **401** on `auth:api` |
| `POST /api/users/{id}/restore` | Does not recreate sessions; user must log in again |

---

## Audit logging notes

M6 introduces a minimal `auth_audit_logs` table for security-sensitive blocked/failure events only. This is **not** a full audit trail (M7 will extend coverage for success paths, refresh, logout, and admin reporting).

Suggested fields: `id` (UUID), nullable `user_id`, `event_type`, nullable `email`, `ip_address`, `user_agent`, `metadata` (JSON), `occurred_at`.

No admin UI or public API exposes audit rows in M6.

---

## Backend files changed

| File | Change |
|------|--------|
| `config/auth_security.php` | Added `login_max_attempts`, `login_lock_minutes` |
| `.env.example` | Added `AUTH_LOGIN_MAX_ATTEMPTS`, `AUTH_LOGIN_LOCK_MINUTES` |
| `app/Services/Auth/LoginRateLimiter.php` | **New** — email + IP rate limiting |
| `app/Services/Auth/LoginRateLimitedException.php` | **New** |
| `app/Services/Auth/AuthAuditService.php` | **New** — minimal audit writer |
| `app/Models/AuthAuditLog.php` | **New** |
| `database/migrations/2026_07_01_100000_create_auth_audit_logs_table.php` | **New** |
| `app/Http/Controllers/Api/AuthController.php` | Login limiter + audit; refresh disabled-user check |
| `app/Services/Auth/AuthLoginChallengeService.php` | OTP failure audit events |
| `app/Services/Auth/RefreshTokenService.php` | Added `revokeAllForUser()` |
| `app/Http/Controllers/Api/UserController.php` | Revoke sessions on soft delete |
| `tests/Feature/AuthRateLimitLockoutTest.php` | **New** — M6 feature coverage |

---

## Frontend files changed

| File | Change |
|------|--------|
| `src/views/pages/auth-forms/AuthLogin.jsx` | Clear password on **429** as well as **401** |
| `src/views/pages/auth-forms/AuthLogin.test.jsx` | Lockout message and password-clear tests |
| `src/api/api.test.js` | Confirm **429** on `/auth/login` does not call refresh |

No change to refresh-on-401 logic in `api.js` (429 is not 401; login path already bypasses refresh).

---

## API behavior table

| Endpoint | Condition | Status | Message (public) |
|----------|-----------|--------|------------------|
| `POST /api/auth/login` | Invalid credentials (attempts 1–4) | 401 | `Invalid credentials.` |
| `POST /api/auth/login` | Lockout (5th failure or while locked) | 429 | `Too many unsuccessful sign-in attempts. Please try again later.` |
| `POST /api/auth/login` | Valid password, M5 branch | 200 | Branch-specific (setup / 2FA / OTP) |
| `POST /api/auth/otp/verify` | Invalid/expired/locked challenge | 422 | `The authentication code is invalid or expired.` |
| `POST /api/auth/refresh` | Soft-deleted user | 401 | `Refresh session is invalid or expired.` |
| `GET /api/auth/me` | Soft-deleted user (stale JWT) | 401 | Unauthenticated |

---

## Security considerations

- **No user enumeration:** Login failures, lockouts, and disabled accounts share generic client-facing messages where required.
- **Hashed limiter keys:** Cache keys use SHA-256 of normalized email + IP; passwords and OTPs are never stored in cache or audit metadata.
- **Soft delete as disable:** Aligns with existing admin user management; avoids schema churn for `is_active`.
- **Immediate session cut-off:** Refresh tokens revoked on disable; JWT becomes unusable when the user provider excludes soft-deleted rows.
- **IP + email scope:** Limits collateral lockout compared to IP-only limiting while still constraining targeted attacks.

---

## Testing performed

```bash
php artisan test --filter=AuthRateLimitLockoutTest
php artisan test --filter=AuthTwoFactorTest
php artisan test --filter=AuthRefreshTokenTest
php artisan test --filter=AuthPasswordSetupTest
php artisan test --filter=PatrolTokenExpiryTest
```

Frontend (after M6 UI tweaks):

```bash
yarn test --run
yarn build
```

---

## Manual QA checklist

- [ ] Five wrong passwords from same email + IP return **429** with generic lockout message
- [ ] Sixth attempt while locked does not reveal account existence
- [ ] Correct password after successful login clears prior failure count (within same IP)
- [ ] Five wrong OTP codes invalidate challenge; valid code afterward fails
- [ ] Admin soft-deletes user; login and refresh fail; existing JWT cannot call `/api/auth/me`
- [ ] Admin restore does not auto-login user
- [ ] Login lockout message appears in React login form; password field clears

---

## Passing criteria checklist

- [x] `LoginRateLimiter` used by `AuthController@login`
- [x] Login limited by normalized email + IP
- [x] Five failed password attempts trigger temporary lockout
- [x] Locked login returns safe generic **429**
- [x] Blocked login recorded (`login_rate_limited`)
- [x] OTP challenges expire and lock per config (M5 + M6 audit)
- [x] Soft-deleted users blocked from login, refresh, and protected APIs
- [x] Disabling user revokes active refresh tokens
- [x] M1–M5 behaviors preserved
- [x] Backend and frontend tests pass
- [x] M6 documentation under `docs/login/`

---

## Known limitations / deferred work

- Login lockout is cache-based; clearing application cache resets counters (acceptable for M6; production should use a shared cache store in multi-node deployments).
- Audit logging covers M6 blocked/failure events only; success-path and admin audit UI remain **M7**.
- No progressive lockout escalation beyond fixed `AUTH_LOGIN_LOCK_MINUTES`.
- Restore does not notify the user or invalidate old JWTs beyond natural JWT expiry (soft-delete exclusion handles access immediately after disable).
