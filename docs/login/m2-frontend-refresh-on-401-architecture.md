# M2 — Frontend Refresh-on-401 Architecture

**Milestone:** M2  
**Status:** Complete  
**Implementation repository:** `frontend-react-v1/` (frontend-only; backend M1 contract unchanged)  
**Planning reference:** [`login-module.md`](../../../login-module.md)  
**Prior milestone:** [`m1-laravel-session-foundation-and-refresh-tokens.md`](m1-laravel-session-foundation-and-refresh-tokens.md)  
**Date context:** FYP Login Module — secure authentication and session architecture  

---

## 1. Executive Summary

M2 replaces the previous **immediate logout-on-401** behavior in the React API client with **refresh-on-401**: when a protected request returns `401`, the client calls `POST /auth/refresh` using the HttpOnly refresh cookie, stores the new access token, retries the original request **exactly once**, and only clears the session when refresh fails.

**Delivered:**

- `authService.refresh()` and `AuthRepository.refreshSession()`
- Shared refresh queue (`authRefreshQueue.js`) to deduplicate concurrent refresh calls
- Refactored `api.js` with refresh-on-401, single retry, and auth-endpoint exclusions
- Minimal session-expired dialog (`SessionExpiredDialog.jsx`) mounted globally in `App.jsx`
- In-memory access-token cache in `utils/auth.js` (localStorage fallback retained for route guards)
- Vitest coverage for refresh success, queue concurrency, refresh failure, skip paths, FormData, and PWA sync path
- No backend runtime changes

**Not delivered (deferred):** full AuthContext migration, OTP/2FA, audit logs, rate limiting, session management UI, logout-all.

---

## 2. Scope

| Area | M2 work |
| --- | --- |
| API client | `api.js` refresh-on-401 with single retry |
| Refresh queue | `authRefreshQueue.js` — one in-flight refresh promise |
| Auth service | `authService.refresh()` via direct `fetch` (avoids recursion) |
| Auth repository | `AuthRepository.refreshSession()` |
| Session UX | `SessionExpiredDialog` + `auth:session-expired` event + `sessionStorage` flag |
| Token storage | Memory cache + `localStorage` fallback for guards |
| PWA | No changes to `syncService.js`; benefits from shared `api.js` |
| Tests | Vitest in `src/api/`, `src/utils/`, `src/feature/authentication/` |
| Backend | **No runtime changes** — consumes M1 `POST /api/auth/refresh` |

---

## 3. Out of Scope

| Item | Milestone |
| --- | --- |
| OTP / TOTP verification UI | M5 |
| First-login password setup | M4 |
| Auth audit logs | M3+ |
| Rate limiting UI | M3 |
| Session management UI / logout-all | M6 |
| Full AuthContext migration | Later milestone |
| Backend refresh-token redesign | Not required |

---

## 4. Current Baseline (Pre-M2)

After M1:

- `api.js` sent `credentials: 'include'` but cleared auth and redirected to `/login` on any `401`
- Refresh token lived only in an HttpOnly cookie (browser-managed)
- `access_token` and `auth_user` were stored in `localStorage`
- `feature/authentication` handled logout only; login remained in `AuthLogin.jsx`

---

## 5. Architecture Summary

```text
Protected API request (Bearer access_token + credentials: include)
        ↓
    401 Unauthorized?
        ↓ no                          ↓ yes
    Return response              Auth endpoint?
                                    ↓ yes → throw (no refresh)
                                    ↓ no
                            runAuthRefresh() [shared queue]
                                    ↓
                            POST /auth/refresh (cookie only)
                                    ↓ success              ↓ failure
                            setAuthToken + optional      clearAuthSession
                            setAuthUser                  markSessionExpired
                                    ↓                      redirect /login
                            Retry original request once
                                    ↓
                            Return response or session-expired error
```

**Backend contract (M1, unchanged):**

- Frontend calls `/auth/refresh` (relative to `VITE_API_BASE_URL`, which includes `/api`)
- Refresh uses `credentials: 'include'`; JavaScript never reads the cookie
- Success returns `{ success, data: { access_token, token_type, expires_in, user, role } }`
- Failure returns `401` and clears the refresh cookie server-side

---

## 6. Files Changed

### Frontend runtime

| File | Change |
| --- | --- |
| `src/api/api.js` | Refresh-on-401, single retry, `skipAuthRefresh` option |
| `src/api/authRefreshQueue.js` | **New** — shared refresh promise |
| `src/utils/auth.js` | Memory token cache; session-expired helpers |
| `src/feature/authentication/datasources/authService.js` | `refresh()` via direct fetch |
| `src/feature/authentication/repositories/authRepository.js` | `refreshSession()` |
| `src/feature/authentication/components/SessionExpiredDialog.jsx` | **New** — dismissible dialog |
| `src/App.jsx` | Mount `SessionExpiredDialog` |

### Frontend tests

| File | Coverage |
| --- | --- |
| `src/api/api.test.js` | Refresh retry, skip paths, FormData, PWA sync path |
| `src/api/authRefreshQueue.test.js` | Queue dedup, storage safety, failure handling |
| `src/feature/authentication/datasources/authService.test.js` | Response normalization, refresh fetch |
| `src/feature/authentication/components/SessionExpiredDialog.test.jsx` | Dialog UX |
| `src/utils/auth.test.js` | Memory cache behavior |

### Documentation

| File | Change |
| --- | --- |
| `docs/login/m2-frontend-refresh-on-401-architecture.md` | This document |
| `frontend-react-v1/documentation.md` | API client / auth flow updates |
| `backend-laravel-v1/documentation.md` | Cross-reference to M2 doc |

### Unchanged (by design)

- `src/pwa/syncService.js` — continues using `api.post('/pwa/sync', …)`
- Backend runtime — no M2 changes
- Route guards — still use `hasAuthToken()` / `validateAuthSession()` with localStorage fallback

---

## 7. Refresh-on-401 Flow

1. `api.js` sends the request with `Authorization: Bearer <token>` and `credentials: 'include'`.
2. On `401`, if the path is `/auth/login`, `/login`, `/auth/logout`, or `/auth/refresh`, or if `skipAuthRefresh: true`, the client throws a structured error **without** attempting refresh.
3. Otherwise `runAuthRefresh()` is invoked from `authRefreshQueue.js`.
4. On success, the original request is retried once with the updated token from `getAuthToken()`.
5. If the retried request still returns `401`, the session is cleared, session-expired UX is triggered, and the user is redirected to `/login`.
6. `403` and other non-401 errors follow the existing structured error path (no refresh).

**Option shape:**

```js
api.get('/example', { skipAuthRefresh: true });
api.post('/path', payload, { skipAuthRefresh: true });
```

---

## 8. Refresh Queue Behavior

`authRefreshQueue.js` maintains a module-level `activeRefreshPromise`:

```text
Request A → 401 → starts refresh
Request B → 401 → awaits same promise
Request C → 401 → awaits same promise
Refresh succeeds → all callers receive new token → each retries once
Refresh fails → all callers reject → session cleared → session-expired signal
```

On success:

- `setAuthToken(newAccessToken)` updates memory + localStorage
- `setAuthUser(user)` only when the refresh response includes a user payload

On failure:

- `clearAuthSession()`
- `markSessionExpired()` sets `sessionStorage.auth_session_expired` and dispatches `auth:session-expired`

---

## 9. Session-Expired UX

- Component: `SessionExpiredDialog.jsx` (MUI Dialog)
- Mounted globally in `App.jsx` alongside `NetworkSnackbar`
- Triggered by:
  - `auth:session-expired` custom event
  - `sessionStorage` flag consumed on mount (survives redirect to `/login`)
- Message: *"Your session has expired. Please sign in again to continue."*
- Dismissible; no sensitive data stored in `sessionStorage`
- Does **not** delete IndexedDB patrol records or PWA `sync_queue`

---

## 10. Token Storage Decision

| Item | Storage | Notes |
| --- | --- | --- |
| Refresh token | HttpOnly cookie only | Never read or stored by JavaScript |
| Access token | Memory + `localStorage` | Memory preferred; localStorage for page reload / guards |
| `auth_user` | `localStorage` | Unchanged for route guards and role resolution |
| Session-expired flag | `sessionStorage` (`auth_session_expired`) | Non-sensitive boolean marker only |

Full AuthContext / memory-only migration is deferred to a later milestone.

---

## 11. PWA Sync Continuity

`flushSyncQueue()` in `syncService.js` calls `api.post('/pwa/sync', payload)` without duplicate refresh logic. When sync receives `401`, `api.js` refreshes and retries automatically. Session expiry does **not** clear Dexie databases or the sync queue.

---

## 12. Security Notes

- Refresh token is never written to `localStorage` or `sessionStorage`
- `authService.refresh()` uses direct `fetch` to prevent recursive refresh-on-401 through `api.js`
- Login credential failures (`401` on `/auth/login`) do not trigger refresh
- Refresh endpoint failures do not recursively call refresh
- Logout tolerates `401` without refresh; local cleanup remains in `useAuthController`

---

## 13. Tests and Verification Commands

From `frontend-react-v1/`:

```bash
yarn test
yarn build
```

**Verification (post-M2):** `yarn test` — **76 passed**; `yarn build` — success.

No backend runtime changes; backend tests were not required for M2.

---

## 14. Manual QA Checklist

1. Log in from the React app; confirm `access_token` in localStorage and HttpOnly refresh cookie in DevTools.
2. Allow the JWT to expire (or temporarily invalidate it) while the refresh cookie remains valid.
3. Navigate or trigger a protected API call — confirm silent refresh and successful retry (Network tab: one `/auth/refresh`, then retried original request).
4. Confirm session-expired dialog appears when refresh fails (revoke cookie / logout from another tab / wait for refresh expiry).
5. Confirm invalid login credentials show login error without refresh attempt.
6. Confirm logout still clears local session even if the API returns `401`.
7. With pending PWA sync items, trigger sync after access token expiry — confirm refresh + successful sync without queue deletion.

---

## 15. Passing Criteria

M2 passes when:

- [x] `authService.refresh()` and `AuthRepository.refreshSession()` exist
- [x] `/auth/refresh` called with cookie credentials; refresh token never in JS storage
- [x] Protected requests retry exactly once after successful refresh
- [x] Concurrent `401` responses share one refresh request
- [x] Refresh failure clears auth and shows session-expired UX
- [x] Login, refresh, and logout paths skip refresh-on-401
- [x] PWA sync uses shared `api.js` without queue deletion on expiry
- [x] Frontend tests and build pass
- [x] M2 documentation exists under `backend-laravel-v1/docs/login/`

---

## 16. Known Limitations and Deferred Work

- Access token still mirrored to `localStorage` for route-guard compatibility
- No cross-tab refresh coordination beyond existing `storage` event on logout
- No dedicated `/session-expired` route (dialog + redirect to `/login` used instead)
- Minor circular-import coupling (`api.js` → `authRefreshQueue.js` → `authService.js` → `api.js` for logout); `authService.refresh()` uses direct `fetch` to avoid refresh recursion — consolidation deferred with AuthContext migration
- Full AuthContext, OTP, audit logs, session UI, and rate limiting remain future milestones

---

**End of M2 milestone document.**
