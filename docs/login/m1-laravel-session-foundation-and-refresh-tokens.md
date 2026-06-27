# M1 — Laravel Session Foundation and Refresh Tokens

**Milestone:** M1  
**Status:** Complete  
**Implementation repository:** `backend-laravel-v1/`  
**Planning reference:** [`login-module.md`](../../../login-module.md)  
**Prior milestone:** [`m0-auth-baseline-and-current-audit.md`](m0-auth-baseline-and-current-audit.md)  
**Date context:** FYP Login Module — secure authentication and session architecture  

---

## 1. Executive Summary

M1 introduces **DB-backed refresh sessions** for the Laravel API while preserving the existing login JSON contract. Successful login still returns `access_token`, `token_type`, `expires_in`, `user`, and `role` in the response body. A new opaque refresh token is issued **only** as an **HttpOnly cookie**; the raw value is hashed in `refresh_tokens` and is never returned in JSON or stored in JavaScript-readable storage.

**Delivered:**

- `refresh_tokens` migration, model, and factory
- `config/auth_security.php` and `.env.example` entries
- `RefreshTokenService` (create, validate, rotate, revoke, family revocation on reuse)
- `POST /api/auth/refresh` (public route; cookie-authenticated)
- Login and logout extended to create/revoke refresh sessions
- Backend feature and unit tests (17 new cases)
- Minimal frontend change: `credentials: 'include'` in `api.js` for cookie transport

**Not delivered (deferred):** frontend refresh-on-401, OTP/2FA, audit logs, rate limiting, session management UI, memory-only access-token migration.

---

## 2. Scope

| Area | M1 work |
| --- | --- |
| Database | `refresh_tokens` table with indexed hash, family, expiry, revoke/rotate timestamps |
| Models | `RefreshToken`, `User::refreshTokens()` |
| Config | `auth_security.php`, `.env.example` `AUTH_*` variables |
| Services | `App\Services\Auth\RefreshTokenService` |
| API | `POST /api/auth/login` (cookie added), `POST /api/auth/refresh` (new), `POST /api/auth/logout` (revoke + clear cookie) |
| Middleware | `refresh_token` excluded from Laravel cookie encryption |
| Frontend | `api.js` sends `credentials: 'include'` only |
| Tests | `RefreshTokenServiceTest`, `AuthRefreshTokenTest` |

---

## 3. Out of Scope

| Item | Milestone |
| --- | --- |
| Frontend refresh-on-401 retry queue | M2 |
| Session-expired UX | M2 |
| Auth audit logs | M3 |
| Login/OTP rate limiting | M3 |
| First-login password setup | M4 |
| Mandatory TOTP | M5 |
| Session list/revoke APIs, logout-all | M6 |
| Memory-only access token storage | M2 |

---

## 4. Architecture Overview

```text
POST /api/auth/login (email + password)
        ↓
JWT access token in JSON (unchanged shape)
        +
HttpOnly refresh_token cookie (opaque, raw value never in JSON)
        +
refresh_tokens row (SHA-256 hash only)
        ↓
POST /api/auth/refresh (cookie only; no Bearer required)
        ↓
Validate hash → rotate token → new JWT + new cookie
        ↓
POST /api/auth/logout (Bearer + cookie)
        ↓
Revoke refresh row + blacklist JWT + clear cookie
```

**Security rules enforced in M1:**

- Raw refresh tokens are never stored in the database, logs, or JSON responses.
- Rotated token reuse revokes the entire `token_family`.
- Refresh cookie is HttpOnly and excluded from Laravel cookie encryption (opaque token does not require app-level encryption).

---

## 5. Files Created / Updated

### Created

| Path | Purpose |
| --- | --- |
| `database/migrations/2026_06_27_120000_create_refresh_tokens_table.php` | Schema |
| `app/Models/RefreshToken.php` | Eloquent model + state helpers |
| `database/factories/RefreshTokenFactory.php` | Test factory |
| `config/auth_security.php` | TTL and cookie settings |
| `app/Services/Auth/RefreshTokenService.php` | Token lifecycle |
| `app/Services/Auth/InvalidRefreshTokenException.php` | Validation failure |
| `app/Services/Auth/RefreshTokenReuseException.php` | Reuse detection |
| `tests/Unit/Auth/RefreshTokenServiceTest.php` | Unit tests |
| `tests/Feature/AuthRefreshTokenTest.php` | Feature tests |
| `docs/login/m1-laravel-session-foundation-and-refresh-tokens.md` | This document |

### Updated

| Path | Change |
| --- | --- |
| `app/Http/Controllers/Api/AuthController.php` | Login cookie, refresh endpoint, logout revocation |
| `app/Models/User.php` | `refreshTokens()` relationship |
| `routes/api.php` | `POST auth/refresh` (public) |
| `bootstrap/app.php` | Exclude `refresh_token` from cookie encryption |
| `.env.example` | `AUTH_*` variables |
| `frontend-react-v1/src/api/api.js` | `credentials: 'include'` |

---

## 6. Database Schema

### `refresh_tokens`

| Column | Type | Notes |
| --- | --- | --- |
| `id` | UUID PK | |
| `user_id` | UUID FK → `users` | cascade on delete |
| `token_hash` | string, indexed | SHA-256 of opaque token |
| `token_family` | UUID, indexed | Rotation family |
| `device_name` | string, nullable | Reserved |
| `ip_address` | string, nullable | From request |
| `user_agent` | text, nullable | From request |
| `expires_at` | timestamp, indexed | Default TTL from config |
| `revoked_at` | timestamp, nullable, indexed | Set on logout or family revoke |
| `rotated_at` | timestamp, nullable | Set when superseded by refresh |
| `last_used_at` | timestamp, nullable | Set on rotation |
| `created_at` / `updated_at` | timestamps | |

**Indexes:** `user_id`, `token_hash`, `token_family`, `expires_at`, `revoked_at`.

---

## 7. Refresh-Token Lifecycle

| Event | Behavior |
| --- | --- |
| **Login success** | Create new `token_family`, store hash, set HttpOnly cookie |
| **Refresh success** | Mark current row `rotated_at`, create new row in same family, new cookie |
| **Refresh failure** | `401` JSON + clear cookie |
| **Rotated token reused** | Revoke all rows in `token_family`, `401` + clear cookie |
| **Logout** | Revoke active refresh row (if cookie matches), blacklist JWT, clear cookie |

---

## 8. API Contract

### Public endpoints

| Method | Path | Auth | Purpose |
| --- | --- | --- | --- |
| `POST` | `/api/auth/login` | None | Email/password → JWT JSON + refresh cookie |
| `POST` | `/api/auth/refresh` | Refresh cookie | Rotate session → JWT JSON + new cookie |

### Protected endpoints (unchanged guard)

| Method | Path | Purpose |
| --- | --- | --- |
| `GET` | `/api/auth/me` | Current user |
| `POST` | `/api/auth/logout` | Revoke refresh + invalidate JWT + clear cookie |

### Login / refresh success response (unchanged shape)

```json
{
  "success": true,
  "message": "Login successful.",
  "data": {
    "access_token": "<jwt>",
    "token_type": "bearer",
    "expires_in": 1800,
    "user": { "id": "...", "email": "...", "role": { "id": "...", "name": "Guard" } },
    "role": "Guard"
  }
}
```

`expires_in` is **seconds**. M1 sets JWT TTL from `AUTH_ACCESS_TOKEN_TTL` (default **30** minutes) via runtime `config(['jwt.ttl' => …])` on login and refresh.

### Refresh failure response

```json
{
  "success": false,
  "message": "Refresh session is invalid or expired.",
  "data": null
}
```

HTTP status: **401**. Response clears the refresh cookie.

---

## 9. Cookie Security Behavior

| Setting | Config key | Default |
| --- | --- | --- |
| Cookie name | `AUTH_REFRESH_COOKIE_NAME` | `refresh_token` |
| Path | `AUTH_REFRESH_COOKIE_PATH` | `/api/auth` |
| SameSite | `AUTH_REFRESH_COOKIE_SAME_SITE` | `lax` |
| Secure | `AUTH_REFRESH_COOKIE_SECURE` | `false` (local dev) |
| HttpOnly | hard-coded | `true` |
| Max-Age | derived from `AUTH_REFRESH_TOKEN_TTL_HOURS` | 12 hours |

**Production:** set `AUTH_REFRESH_COOKIE_SECURE=true` on HTTPS deployments.

**Laravel encryption:** `refresh_token` is listed in `bootstrap/app.php` `encryptCookies(except: …)` so the opaque value is not double-encrypted. If `AUTH_REFRESH_COOKIE_NAME` is changed from the default, update the except list to match.

**Frontend:** `api.js` uses `credentials: 'include'` so browsers send the HttpOnly cookie on same-origin (or CORS-configured) API requests. Refresh-on-401 is **not** implemented until M2.

---

## 10. Test Coverage

| Suite | File | Cases |
| --- | --- | --- |
| Unit | `tests/Unit/Auth/RefreshTokenServiceTest.php` | 8 |
| Feature | `tests/Feature/AuthRefreshTokenTest.php` | 9 |

**Covered behaviors:** hash storage, model state helpers, login cookie + row creation, refresh rotation, missing/expired/revoked/reused tokens, logout revocation, refresh-after-logout failure, invalid login unchanged.

**Verification commands (2026-06-27):**

| Command | Result |
| --- | --- |
| `php artisan test --filter=RefreshTokenServiceTest` | 8 passed |
| `php artisan test --filter=AuthRefreshTokenTest` | 9 passed |
| `php artisan test` | 365 passed |
| `yarn test --run` | 48 passed |
| `yarn build` | Success |

---

## 11. Manual Verification Checklist

1. Login with valid credentials → JSON includes `access_token`, `user`, `role`.
2. Response `Set-Cookie` includes `refresh_token` (HttpOnly).
3. Database `refresh_tokens.token_hash` ≠ raw cookie value.
4. `POST /api/auth/refresh` with cookie (no Bearer) → new `access_token` and rotated cookie.
5. Re-submit old refresh cookie → `401`; all rows in family have `revoked_at`.
6. `POST /api/auth/logout` with Bearer + cookie → `200`, cookie cleared, row revoked.
7. `POST /api/auth/refresh` after logout → `401`.

**Example (curl):**

```bash
# Login and save cookies
curl -c cookies.txt -X POST http://localhost:8000/api/auth/login \
  -H "Content-Type: application/json" \
  -d '{"email":"guard@example.com","password":"password"}'

# Refresh
curl -b cookies.txt -c cookies.txt -X POST http://localhost:8000/api/auth/refresh

# Logout (use access_token from login JSON)
curl -b cookies.txt -X POST http://localhost:8000/api/auth/logout \
  -H "Authorization: Bearer <access_token>"
```

---

## 12. Known Limitations and Deferred Work (M2+)

| Limitation | Planned milestone |
| --- | --- |
| Frontend still stores JWT in `localStorage` | M2 |
| `401` on API calls still clears session without refresh retry | M2 |
| PWA sync does not auto-refresh on token expiry | M2 |
| No audit trail for login/refresh/logout | M3 |
| No rate limiting on login/refresh | M3 |
| No 2FA or first-login setup | M4–M5 |
| No disabled-user refresh invalidation beyond soft-delete | M6 |
| Custom `AUTH_REFRESH_COOKIE_NAME` requires matching `encryptCookies` except entry | Document / future middleware hardening |

**Critical sequencing:** M2 (frontend refresh client) must ship before mandatory 2FA (M5) so patrol/PWA sync survives short access-token expiry.

---

## 13. Passing Criteria

- [x] `refresh_tokens` migration runs on SQLite and MySQL-compatible schema
- [x] Raw refresh token never stored in DB or JSON
- [x] Login response shape preserved; HttpOnly cookie added
- [x] `POST /api/auth/refresh` rotates tokens and returns new JWT
- [x] Logout revokes refresh session and clears cookie
- [x] Rotated-token reuse revokes token family
- [x] Unit and feature tests pass
- [x] Full backend suite passes (365 tests)
- [x] No OTP, audit logs, or frontend refresh-on-401 in M1
- [x] M1 documentation committed

---

## 14. M1 Conclusion

M1 establishes the **server-side refresh session foundation** required for shift-length patrol continuity and future login hardening. The API login contract seen by the React client is unchanged in JSON shape; the refresh session is carried only via HttpOnly cookie. Frontend automatic token renewal remains **M2** work; until then, expired JWTs still trigger the existing `401` → clear session → `/login` behavior.

---

## Appendix — Related documentation

- [`m0-auth-baseline-and-current-audit.md`](m0-auth-baseline-and-current-audit.md)
- [`login-module.md`](../../../login-module.md)
- [`backend-laravel-v1/documentation.md`](../../documentation.md) — Section 6
- [`frontend-react-v1/documentation.md`](../../../frontend-react-v1/documentation.md) — Section 6
