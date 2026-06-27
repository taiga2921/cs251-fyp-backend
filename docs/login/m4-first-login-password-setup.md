# Login Module — Milestone 4 (M4): First Login Password Setup

**Status:** Implemented  
**Depends on:** M1 (refresh sessions), M2 (frontend refresh-on-401), M3 (PWA patrol token expiry safety)  
**Defers to M5:** Mandatory TOTP generation, QR code, OTP verification, lockout, audit logs, and full route hardening

---

## Objective

Implement first-login password setup for admin-created users. Users with `setup_required = true` must set a permanent password before receiving JWT access tokens or HttpOnly refresh cookies. The flow is structured so M5 can enforce mandatory two-factor authentication immediately afterward.

---

## Scope

### In scope (M4)

- `users.setup_required` column and migration-safe defaults
- `password_setup_tokens` table with hashed, single-use, expiring tokens
- `PasswordSetupService` for token lifecycle and password completion
- Login branch for setup-required users (`next_step = password_setup_required`)
- Public `POST /api/auth/password-setup/complete` endpoint
- Admin user creation defaults to `setup_required = true` with one-time setup token in create response
- Frontend first-login setup page at `/first-login/setup`
- Route guards block setup-required users from protected modules
- Backend and frontend tests; documentation updates

### Out of scope (M5+)

- TOTP secret generation, QR codes, OTP fields, recovery codes
- Mandatory 2FA enforcement and dedicated 2FA setup UI (placeholder routing only)
- Audit logging, rate limiting, and full M8 route hardening

---

## Architecture summary

```text
Admin creates user (setup_required = true, temp password)
        ↓
User logs in with temporary password
        ↓
Backend validates credentials; does NOT issue JWT or refresh cookie
        ↓
Returns next_step = password_setup_required + one-time setup_token
        ↓
Frontend navigates to /first-login/setup (in-memory route state only)
        ↓
User submits new password + setup_token
        ↓
PasswordSetupService completes setup in DB transaction
        ↓
setup_required = false, last_password_changed_at = now(), token marked used
        ↓
Frontend redirects to /login with success message (M5: 2FA setup next)
        ↓
User logs in normally (M1/M2/M3 behavior preserved)
```

---

## Backend changes

| Area | Change |
|------|--------|
| Migration | `users.setup_required` boolean, default `false` |
| Migration | `password_setup_tokens` table (UUID PK, hashed token, expiry, used_at) |
| Model | `PasswordSetupToken`, `User::passwordSetupTokens()` |
| Service | `App\Services\Auth\PasswordSetupService` |
| Exception | `InvalidPasswordSetupTokenException` |
| Controller | `AuthController@login` — setup-required branch |
| Controller | `AuthController@completePasswordSetup` |
| Controller | `UserController@store` — `setup_required = true`, returns `password_setup` metadata |
| Request | `CompletePasswordSetupRequest` |
| Resource | `UserResource` — exposes `setup_required`, `last_password_changed_at` |
| Config | `auth_security.password_min_length`, `auth_security.password_setup_token_ttl_hours` |
| Route | `POST /api/auth/password-setup/complete` (public) |

### Token handling

- Plain tokens: 64 hex chars (`bin2hex(random_bytes(32))`)
- Storage: SHA-256 hash only (same convention as `RefreshTokenService`)
- TTL: `AUTH_PASSWORD_SETUP_TOKEN_TTL_HOURS` (default 24 hours)
- Single-use: `used_at` set on completion; reuse rejected
- Regeneration: creating a new token deletes prior unused tokens for the same user inside a **DB transaction** with user row lock (M4 hardening)

### Security rules

- Setup tokens returned only after successful password verification (login) or admin create
- Setup-required login does **not** create refresh sessions or JWT access tokens
- **`POST /api/auth/refresh` rejects `setup_required` users** — revokes the presented refresh session, clears the cookie, returns **401** (M4 hardening)
- `two_factor_secret` never exposed in API resources
- General user CRUD **cannot** set `two_factor_*`, `last_password_changed_at`, or `setup_required` (prohibited in FormRequests; removed from mass assignment)
- Admin temporary passwords must meet `AUTH_PASSWORD_MIN_LENGTH` (same as password setup completion)
- Password setup completion does **not** issue access/refresh tokens (normal login required)

---

## Frontend changes

| Area | Change |
|------|--------|
| `AuthLogin.jsx` | Handles `next_step === 'password_setup_required'`; no token storage |
| `FirstLoginSetup.jsx` | First-login setup page (minimal auth layout) |
| `PasswordSetupForm.jsx` | Password + confirmation, strength hint, client validation |
| `usePasswordSetupController.js` | Form state, API submit, navigation |
| `authService.js` | `completePasswordSetup()` via `api.post(..., { skipAuthRefresh: true })` |
| `authRepository.js` | `completePasswordSetup()` wrapper |
| `AuthenticationRoutes.jsx` | Route `/first-login/setup` |
| Guards | `ProtectedRoute`, `RoleProtectedRoute`, `GuestRoute` — block/clear setup-required sessions |
| `utils/auth.js` | `validateAuthSession()` rejects `setup_required`; `isAuthUserSetupRequired()` |
| `api.js` | `/auth/password-setup/complete` bypasses refresh-on-401 |

Setup tokens are passed via React Router **location state only** — never `localStorage` or `sessionStorage`.

---

## Database changes

### `users` (added column)

| Column | Type | Notes |
|--------|------|-------|
| `setup_required` | boolean, default `false` | Existing/seeded users migrated as `false` |

Existing column reused:

| Column | Purpose |
|--------|---------|
| `last_password_changed_at` | Timestamp when first-login or future password change completes |

Admin `PATCH /api/users/{user}` password updates set `last_password_changed_at` automatically in the controller; request payloads cannot set this field directly.

### `password_setup_tokens` (new table)

| Column | Type | Notes |
|--------|------|-------|
| `id` | UUID PK | |
| `user_id` | UUID FK → `users.id` | cascade delete |
| `token_hash` | string, indexed | SHA-256 of plain token |
| `expires_at` | timestamp, indexed | |
| `used_at` | timestamp nullable | set on successful completion |
| `created_at`, `updated_at` | timestamps | |

---

## API contract

### `POST /api/auth/login` — setup-required user

**Request:** unchanged (`email`, `password`)

**Response (200):**

```json
{
  "success": true,
  "message": "Account setup required.",
  "data": {
    "next_step": "password_setup_required",
    "setup_token": "plain-token-returned-once",
    "expires_in": 86400,
    "user": {
      "email": "user@example.com",
      "setup_required": true
    }
  }
}
```

No `access_token`. No `refresh_token` cookie.

### `POST /api/auth/password-setup/complete` (public)

**Request:**

```json
{
  "setup_token": "plain-token",
  "password": "NewStrongPassword123!",
  "password_confirmation": "NewStrongPassword123!"
}
```

**Success (200):**

```json
{
  "success": true,
  "message": "Password setup completed successfully.",
  "data": {
    "next_step": "two_factor_setup_required",
    "user": { "...UserResource fields..." }
  }
}
```

**Invalid/expired/used token (422):** generic message — `Password setup token is invalid or expired.`

### `POST /api/users` — admin create (updated)

**Response (201):** includes `data` (user resource) and one-time `password_setup`:

```json
{
  "success": true,
  "message": "User created successfully.",
  "data": { "id": "...", "setup_required": true, "...": "..." },
  "password_setup": {
    "token": "plain-token-returned-once",
    "expires_at": "2026-06-29T12:00:00+00:00"
  }
}
```

List/show endpoints do **not** include setup tokens.

---

## Security considerations

- Setup tokens are credentials-adjacent; returned only once at generation time
- Hashed storage prevents DB leak from revealing usable tokens
- Setup-required users cannot obtain JWT or refresh sessions until setup completes
- M1–M3 refresh cookie and PWA sync behavior unchanged for fully set-up users
- Client must not persist setup tokens in browser storage

---

## Files changed

### Backend (created)

- `database/migrations/2026_06_28_120000_add_setup_required_to_users_table.php`
- `database/migrations/2026_06_28_120100_create_password_setup_tokens_table.php`
- `app/Models/PasswordSetupToken.php`
- `app/Services/Auth/PasswordSetupService.php`
- `app/Services/Auth/InvalidPasswordSetupTokenException.php`
- `app/Http/Requests/CompletePasswordSetupRequest.php`
- `database/factories/PasswordSetupTokenFactory.php`
- `tests/Feature/AuthPasswordSetupTest.php`
- `tests/Unit/Auth/PasswordSetupServiceTest.php`

### Backend (modified)

- `app/Models/User.php`
- `app/Http/Controllers/Api/AuthController.php`
- `app/Http/Controllers/Api/UserController.php`
- `app/Http/Resources/UserResource.php`
- `config/auth_security.php`
- `.env.example`
- `routes/api.php`
- `database/factories/UserFactory.php`

### Frontend (created)

- `src/feature/authentication/views/FirstLoginSetup.jsx`
- `src/feature/authentication/components/PasswordSetupForm.jsx`
- `src/feature/authentication/controllers/usePasswordSetupController.js`
- Tests: `AuthLogin.test.jsx`, `PasswordSetupForm.test.jsx`, `usePasswordSetupController.test.jsx`, `ProtectedRoute.test.jsx`

### Frontend (modified)

- `src/views/pages/auth-forms/AuthLogin.jsx`
- `src/views/pages/authentication/Login.jsx`
- `src/feature/authentication/datasources/authService.js`
- `src/feature/authentication/repositories/authRepository.js`
- `src/routes/AuthenticationRoutes.jsx`
- `src/routes/guards/ProtectedRoute.jsx`
- `src/routes/guards/RoleProtectedRoute.jsx`
- `src/routes/guards/GuestRoute.jsx`
- `src/utils/auth.js`
- `src/api/api.js`
- `src/feature/authentication/datasources/authService.test.js`

---

## Tests added/updated

### Backend

| Test file | Coverage |
|-----------|----------|
| `AuthPasswordSetupTest.php` | Migration defaults, admin create, login branch, token expiry/reuse, completion, login after setup, M1 regression |
| `PasswordSetupServiceTest.php` | Hash storage, token invalidation, expiry, reuse, completion side effects |

**Commands:**

```bash
php artisan test --filter=AuthPasswordSetupTest
php artisan test --filter=PasswordSetupServiceTest
php artisan test --filter=AuthRefreshTokenTest
php artisan test --filter=PatrolTokenExpiryTest
php artisan test
```

### Frontend

| Test file | Coverage |
|-----------|----------|
| `AuthLogin.test.jsx` | Setup-required login routes without storing token |
| `PasswordSetupForm.test.jsx` | Missing context message, mismatch display, submit |
| `usePasswordSetupController.test.jsx` | API payload, no storage, mismatch blocking |
| `ProtectedRoute.test.jsx` | Setup-required user redirected and cleared |
| `authService.test.js` | `normalizePasswordSetupResponse`, `completePasswordSetup` |

---

## Manual verification checklist

- [ ] Existing seeded users (`admin@example.com`, etc.) can log in without setup prompt
- [ ] Admin creates user → `setup_required: true` in response; `password_setup.token` present once
- [ ] New user login with temp password → redirected to `/first-login/setup` (no JWT in localStorage)
- [ ] Password setup form validates mismatch and minimum length
- [ ] Successful setup → login page success message; sign in with new password works
- [ ] Old temporary password rejected after setup
- [ ] Refresh cookie issued only after setup-complete normal login
- [ ] PWA patrol sync still recovers from 401 via M2 refresh (M3 regression)

---

## Known limitations / deferred items

- No TOTP/OTP UI or backend enforcement (M5)
- No dedicated `/first-login/2fa` route yet — frontend returns to login with messaging
- Admin must still supply a temporary password at create time.
- No rate limiting on setup token attempts (future hardening)
- Setup token lost on page refresh — user must re-login with temporary password (by design)

---

## M5 handoff notes

M4 establishes:

1. **`next_step` contract** — login returns `password_setup_required`; completion returns `two_factor_setup_required`
2. **`setup_required` flag** — cleared after password setup; M5 should gate login similarly for users without 2FA when enforcement is enabled
3. **Token service pattern** — `PasswordSetupService` mirrors `RefreshTokenService` hashing/TTL/single-use semantics; M5 can add `TwoFactorSetupService` following the same approach
4. **Frontend route slot** — `/first-login/setup` exists; M5 should add `/first-login/2fa` (or equivalent) and extend login routing
5. **No access tokens until fully set up** — M5 should continue withholding JWT/refresh until mandatory 2FA is complete

PWA patrol M3 behavior and M2 refresh-on-401 remain unchanged for users who complete M4 and log in normally.
