# Login Module M5 — TOTP Two-Factor Authentication

**Status:** Implemented  
**Depends on:** M1 (refresh sessions), M2 (frontend refresh-on-401), M3 (PWA sync via `api.js`), M4 (first-login password setup)  
**Target design reference:** [`../../../login-module.md`](../../../login-module.md)

---

## Objective

Implement **mandatory TOTP two-factor authentication** so that:

1. Admin-created users complete password setup (M4), then mandatory TOTP enrollment, before receiving a usable session.
2. Normal login validates email/password, returns an OTP challenge, and issues JWT + HttpOnly refresh cookie **only after successful OTP verification**.

Email/password alone must not issue access tokens for fully initialized users.

---

## Scope

### In scope (M5)

| Area | Delivered |
|------|-----------|
| Backend TOTP | Native RFC 6238 implementation in `TwoFactorService` (no external TOTP package) |
| Database | `users.two_factor_confirmed_at`, `auth_login_challenges`, `two_factor_setup_sessions` |
| Auth endpoints | `POST /api/auth/2fa/setup/start`, `POST /api/auth/2fa/setup/verify`, `POST /api/auth/otp/verify` |
| Login branching | `password_setup_required` → `two_factor_setup_required` → `otp_required` → session |
| Refresh guard | Rejects users with `setup_required = true` or `two_factor_enabled = false` |
| Frontend | `/first-login/2fa`, `/login/otp`, login branching, route guards |
| Challenge lockout | Failed OTP attempts increment `failed_attempts`; challenge locks at configured max |
| Tests | `TwoFactorServiceTest`, `AuthTwoFactorTest`, frontend Vitest coverage |

### Out of scope (M6+)

- Public self-service 2FA reset
- Recovery codes workflow
- Full account lockout (user-level)
- Audit UI / session management UI
- Rate limiting beyond challenge-level failed attempts

---

## Dependency summary (M1–M4)

| Milestone | M5 dependency |
|-----------|---------------|
| **M1** | HttpOnly refresh cookie rotation; session issued only after full auth |
| **M2** | Refresh-on-401 must not run on semi-public auth endpoints |
| **M3** | PWA sync uses shared `api.js`; refresh guard applies to patrol continuity |
| **M4** | Password setup returns `two_factor_setup_required` + setup token; no JWT until 2FA complete |

---

## Backend architecture

### TOTP implementation choice

**Decision:** Internal RFC 6238 implementation using native PHP (`hash_hmac`, Base32 decode). No Composer TOTP package was present in `composer.json`, and a small internal service avoids version coupling while remaining fully testable.

**Location:** `app/Services/Auth/TwoFactorService.php`

**Capabilities:**

- Generate random Base32 secrets
- Build `otpauth://totp/...` URIs
- Verify 6-digit codes (30-second step, configurable window ±1)
- Encrypt secrets at rest via Laravel `encrypt()` / `decrypt()`
- Never expose decrypted secrets after setup completion

### Supporting services

| Service | Responsibility |
|---------|----------------|
| `TwoFactorSetupService` | Short-lived setup tokens (hashed), pending encrypted secret, verify enables 2FA |
| `AuthLoginChallengeService` | Login OTP challenges, failed-attempt tracking, lockout |
| `PasswordSetupService` | Unchanged M4 behavior; completion triggers 2FA setup session |

### Login branching (`AuthController@login`)

```
Invalid credentials → 401

setup_required = true
  → password_setup_required + setup_token
  → no JWT, no refresh cookie

setup_required = false AND two_factor_enabled = false
  → two_factor_setup_required + two_factor_setup_token
  → no JWT, no refresh cookie

setup_required = false AND two_factor_enabled = true
  → otp_required + login_challenge_id
  → no JWT, no refresh cookie
```

Credentials are validated with `Hash::check`. JWT is issued only in `issueAuthenticatedSession()` after successful OTP or 2FA setup verification.

### Refresh behavior

`POST /api/auth/refresh` revokes the presented refresh session and clears the cookie when:

- `user.setup_required = true`, or
- `user.two_factor_enabled = false`

---

## Frontend architecture

### Routes

| Path | Component | Purpose |
|------|-----------|---------|
| `/first-login/setup` | `FirstLoginSetup` | M4 password setup |
| `/first-login/2fa` | `SetupTwoFactor` | M5 TOTP enrollment |
| `/login/otp` | `VerifyOtp` | M5 login OTP challenge |
| `/login` | `Login` + `AuthLogin` | Email/password entry |

### State handling

Setup tokens, challenge IDs, manual keys, and OTP values are passed via **React Router location state only**. They are **not** stored in `localStorage` or `sessionStorage`.

### API client

Semi-public auth paths bypass refresh-on-401 in `src/api/api.js`:

- `/auth/login`
- `/auth/password-setup/complete`
- `/auth/2fa/setup/start`
- `/auth/2fa/setup/verify`
- `/auth/otp/verify`

### QR rendering

Local dependency `qrcode.react` renders QR codes from `otpauth_uri` on the 2FA setup page. No external QR API is used.

### Route guards

`ProtectedRoute` clears incomplete sessions when stored `auth_user` has `setup_required = true` or `two_factor_enabled = false`.

---

## API contracts

### Password setup completion (M4 → M5 handoff)

**`POST /api/auth/password-setup/complete`**

```json
{
  "success": true,
  "message": "Password setup completed successfully.",
  "data": {
    "next_step": "two_factor_setup_required",
    "two_factor_setup_token": "plain-one-time-token",
    "expires_in": 600,
    "user": {
      "email": "user@example.com",
      "setup_required": false,
      "two_factor_enabled": false
    }
  }
}
```

### Start 2FA setup

**`POST /api/auth/2fa/setup/start`**

Request: `{ "two_factor_setup_token": "plain-token" }`

Response:

```json
{
  "success": true,
  "message": "Two-factor setup started.",
  "data": {
    "next_step": "two_factor_setup_verify_required",
    "manual_key": "BASE32SECRET",
    "otpauth_uri": "otpauth://totp/...",
    "expires_in": 600
  }
}
```

### Verify 2FA setup

**`POST /api/auth/2fa/setup/verify`**

Request: `{ "two_factor_setup_token": "plain-token", "otp": "123456" }`

On success: JWT access token JSON + HttpOnly refresh cookie (same shape as login completion).

### Normal login (2FA enabled)

**`POST /api/auth/login`**

```json
{
  "success": true,
  "message": "OTP verification required.",
  "data": {
    "next_step": "otp_required",
    "login_challenge_id": "uuid",
    "expires_in": 300,
    "user": { "email": "user@example.com" }
  }
}
```

### Verify login OTP

**`POST /api/auth/otp/verify`**

Request: `{ "login_challenge_id": "uuid", "otp": "123456" }`

On success: JWT access token JSON + HttpOnly refresh cookie.

---

## Database changes

### `users` table

| Column | Type | Notes |
|--------|------|-------|
| `two_factor_confirmed_at` | nullable timestamp | Set when TOTP setup completes |

Existing columns `two_factor_enabled`, `two_factor_secret` are used. Secret is encrypted; never exposed via API.

### `auth_login_challenges`

| Column | Type | Notes |
|--------|------|-------|
| `id` | UUID PK | Returned as `login_challenge_id` |
| `user_id` | UUID FK | |
| `expires_at` | timestamp | TTL from config |
| `consumed_at` | nullable timestamp | Set on successful OTP |
| `failed_attempts` | unsigned int | Default 0 |
| `locked_at` | nullable timestamp | Set when max attempts exceeded |
| `ip_address` | nullable string | |
| `user_agent` | nullable string | |

### `two_factor_setup_sessions`

| Column | Type | Notes |
|--------|------|-------|
| `id` | UUID PK | |
| `user_id` | UUID FK | |
| `token_hash` | string (indexed) | Raw token never stored |
| `pending_secret` | encrypted text | Pending TOTP secret until OTP confirmed |
| `expires_at` | timestamp | |
| `verified_at` | nullable timestamp | |

---

## Configuration

**File:** `config/auth_security.php`  
**Environment variables** (see `.env.example`):

| Variable | Default | Purpose |
|----------|---------|---------|
| `AUTH_OTP_CHALLENGE_TTL` | 5 | Login challenge TTL (minutes) |
| `AUTH_OTP_MAX_ATTEMPTS` | 5 | Failed OTP attempts before challenge lock |
| `AUTH_TWO_FACTOR_SETUP_TTL` | 10 | 2FA setup token TTL (minutes) |
| `AUTH_TOTP_ISSUER` | `IKH One` | TOTP issuer in otpauth URI |
| `AUTH_TOTP_WINDOW` | 1 | Time step tolerance (± steps) |

---

## Security controls

| Control | Implementation |
|---------|----------------|
| Backend authority | All branching and token issuance server-side |
| Mandatory 2FA | All users must complete TOTP before session |
| Secret encryption | `two_factor_secret` encrypted at rest |
| Secret non-exposure | Hidden on `User` model; excluded from `UserResource` and CRUD |
| No premature tokens | JWT + refresh cookie only after OTP/setup verify |
| Short-lived challenges | TTL + single-use consumption |
| Hashed setup tokens | Plain token never persisted |
| Generic OTP errors | Safe messages; no secret/challenge leakage |
| Refresh guard | Incomplete users cannot refresh |
| Frontend non-persistence | Challenge/setup tokens in route state only |

---

## Test coverage

### Backend

| Suite | Coverage |
|-------|----------|
| `tests/Unit/Auth/TwoFactorServiceTest.php` | Secret format, otpauth URI, verify, window, encryption |
| `tests/Feature/AuthTwoFactorTest.php` | Full M5 flows, lockout, expiry, refresh rejection |
| `tests/Feature/AuthPasswordSetupTest.php` | M4 regression + 2FA handoff |
| `tests/Feature/AuthRefreshTokenTest.php` | Refresh with 2FA-enabled users |
| `tests/Feature/AuthCorsTest.php` | Credentialed CORS through OTP verify |
| `tests/Concerns/EnablesTwoFactorAuth.php` | Test helper for 2FA-enabled login |

**Commands:**

```bash
php artisan test --filter=TwoFactorServiceTest
php artisan test --filter=AuthTwoFactorTest
php artisan test --filter=AuthPasswordSetupTest
php artisan test --filter=AuthRefreshTokenTest
php artisan test
```

### Frontend

| Suite | Coverage |
|-------|----------|
| `AuthLogin.test.jsx` | Login branching for all `next_step` values |
| `authService.test.js` | Normalizers + `skipAuthRefresh` on M5 endpoints |
| `useOtpController.test.jsx` | OTP verify success/failure |
| `useTwoFactorSetupController.test.jsx` | Setup start/verify, missing state |
| `OtpInput.test.jsx` | 6-digit input filtering |
| `ProtectedRoute.test.jsx` | Incomplete 2FA session rejection |
| `usePasswordSetupController.test.jsx` | Routes to `/first-login/2fa` after password setup |

**Commands:**

```bash
yarn test AuthLogin
yarn test authService
yarn test OtpInput
yarn test useOtpController
yarn test useTwoFactorSetupController
yarn test ProtectedRoute
yarn test
yarn build
```

---

## Manual verification checklist

- [ ] Admin creates user → first login returns `password_setup_required`
- [ ] Password setup completes → redirects to `/first-login/2fa` with setup token in route state only
- [ ] 2FA setup shows QR code and manual key
- [ ] Invalid setup OTP does not enable 2FA or issue tokens
- [ ] Valid setup OTP enables 2FA and issues JWT + refresh cookie
- [ ] Normal login for 2FA user returns `otp_required` (no token)
- [ ] Valid login OTP issues JWT + refresh cookie
- [ ] Invalid/expired/used OTP challenge fails with generic error
- [ ] Too many wrong OTP attempts lock the challenge
- [ ] Protected routes reject users without completed 2FA
- [ ] Refresh fails for users without completed 2FA
- [ ] `two_factor_secret` never appears in API responses

---

## Known limitations

- Seeded demo users (`admin@example.com`, etc.) have `two_factor_enabled = false` until enrolled through login or a test helper.
- No admin 2FA reset endpoint (deferred).
- No recovery codes (deferred).
- Challenge lockout is per-challenge, not per-user account lockout.

---

## M6/M7/M8 handoff notes

| Topic | M5 state | Next milestone |
|-------|----------|----------------|
| Account lockout | Challenge-level only | M6/M7 user-level lockout |
| Audit logs | Not implemented | M6/M7 audit UI |
| Session management | Refresh rotation only | M7 session UI |
| 2FA reset | Admin manual DB only | Dedicated admin endpoint |
| Recovery codes | Not implemented | Future milestone |

---

## Files changed

### Backend (created)

- `database/migrations/2026_06_29_100000_add_two_factor_confirmed_at_to_users_table.php`
- `database/migrations/2026_06_29_100100_create_auth_login_challenges_table.php`
- `database/migrations/2026_06_29_100200_create_two_factor_setup_sessions_table.php`
- `app/Models/AuthLoginChallenge.php`
- `app/Models/TwoFactorSetupSession.php`
- `app/Services/Auth/TwoFactorService.php`
- `app/Services/Auth/TwoFactorSetupService.php`
- `app/Services/Auth/AuthLoginChallengeService.php`
- `app/Services/Auth/InvalidTwoFactorSetupTokenException.php`
- `app/Services/Auth/InvalidOtpChallengeException.php`
- `app/Http/Requests/StartTwoFactorSetupRequest.php`
- `app/Http/Requests/VerifyTwoFactorSetupRequest.php`
- `app/Http/Requests/VerifyOtpRequest.php`
- `tests/Unit/Auth/TwoFactorServiceTest.php`
- `tests/Feature/AuthTwoFactorTest.php`
- `tests/Concerns/EnablesTwoFactorAuth.php`

### Backend (modified)

- `app/Http/Controllers/Api/AuthController.php`
- `routes/api.php`
- `config/auth_security.php`
- `.env.example`
- `app/Models/User.php`
- `app/Http/Resources/UserResource.php`
- `tests/Feature/AuthRefreshTokenTest.php`
- `tests/Feature/AuthPasswordSetupTest.php`
- `tests/Feature/AuthCorsTest.php`
- `tests/Feature/PatrolTokenExpiryTest.php`

### Frontend (created)

- `src/feature/authentication/views/VerifyOtp.jsx`
- `src/feature/authentication/views/SetupTwoFactor.jsx`
- `src/feature/authentication/components/OtpInput.jsx`
- `src/feature/authentication/components/OtpVerificationForm.jsx`
- `src/feature/authentication/components/TwoFactorSetupCard.jsx`
- `src/feature/authentication/controllers/useOtpController.js`
- `src/feature/authentication/controllers/useTwoFactorSetupController.js`
- `src/feature/authentication/components/OtpInput.test.jsx`
- `src/feature/authentication/controllers/useOtpController.test.jsx`
- `src/feature/authentication/controllers/useTwoFactorSetupController.test.jsx`

### Frontend (modified)

- `src/feature/authentication/datasources/authService.js`
- `src/feature/authentication/repositories/authRepository.js`
- `src/views/pages/auth-forms/AuthLogin.jsx`
- `src/views/pages/auth-forms/AuthLogin.test.jsx`
- `src/feature/authentication/controllers/usePasswordSetupController.js`
- `src/feature/authentication/controllers/usePasswordSetupController.test.jsx`
- `src/feature/authentication/datasources/authService.test.js`
- `src/routes/AuthenticationRoutes.jsx`
- `src/routes/guards/ProtectedRoute.jsx`
- `src/routes/guards/ProtectedRoute.test.jsx`
- `src/utils/auth.js`
- `src/api/api.js`
- `src/views/pages/authentication/Login.jsx`
- `package.json` (+ `qrcode.react`)

---

## Passing criteria

M5 is complete when all of the following hold:

- [x] First-login password setup flows into mandatory TOTP setup
- [x] TOTP setup cannot complete without valid OTP
- [x] Normal login returns OTP challenge without issuing tokens
- [x] Correct OTP verification issues JWT + HttpOnly refresh cookie
- [x] Invalid/expired/used OTP challenges fail safely
- [x] Users without completed 2FA cannot access protected modules
- [x] Refresh fails for users without completed 2FA
- [x] `two_factor_secret` is encrypted and never exposed
- [x] Frontend supports OTP and TOTP setup UI
- [x] Backend and frontend tests pass
- [x] M5 documentation exists under `docs/login`
- [x] M1–M4 behavior preserved

**Test results (delivery):**

| Suite | Result |
|-------|--------|
| Backend `php artisan test` | 415 passed |
| Frontend `yarn test` | 104 passed |
| Frontend `yarn build` | Success |
