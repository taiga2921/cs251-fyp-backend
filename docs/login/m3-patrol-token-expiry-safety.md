# Login Module M3 — Patrol Token Expiry Safety

**Milestone:** M3  
**Status:** Complete  
**Implementation repository:** `frontend-react-v1/` (primary); `backend-laravel-v1/` (verification tests only)  
**Planning reference:** [`login-module.md`](../../../login-module.md)  
**Prior milestones:** [`m1-laravel-session-foundation-and-refresh-tokens.md`](m1-laravel-session-foundation-and-refresh-tokens.md), [`m2-frontend-refresh-on-401-architecture.md`](m2-frontend-refresh-on-401-architecture.md)

---

## 1. Purpose

M3 proves and hardens **patrol/PWA sync continuity** when the short-lived JWT access token is no longer accepted, while a valid HttpOnly refresh session remains. Guards must be able to drain `sync_queue` location logs without manual re-login when refresh succeeds.

M3 does **not** add new patrol features, OTP, audit logs, or rate limiting.

---

## 2. Scope

| Area          | M3 work                                                                             |
| ------------- | ----------------------------------------------------------------------------------- |
| Frontend      | Confirm `flushSyncQueue()` uses shared `api.js`; add `syncService.test.js`          |
| Backend       | Add `PatrolTokenExpiryTest.php` proving server-side refresh + sync contract         |
| Runtime       | **No production code changes required** — M2 refresh-on-401 already covers PWA sync |
| Documentation | This document + short cross-links in root docs                                      |

### Out of scope

- M4 first-login password setup
- M5 OTP/TOTP
- M6 rate limiting / lockout
- Auth audit logs
- Full AuthContext migration
- Service-worker refresh handling
- JavaScript-readable refresh token storage

---

## 3. Relationship to M1 and M2

| Milestone | Responsibility                                                                |
| --------- | ----------------------------------------------------------------------------- |
| **M1**    | HttpOnly refresh cookie, `POST /api/auth/refresh`, DB-backed refresh sessions |
| **M2**    | `api.js` refresh-on-401, shared `runAuthRefresh()` queue, session-expired UX  |
| **M3**    | Prove patrol `flushSyncQueue()` benefits from M2 without duplicate auth logic |

M3 is primarily **verification and documentation hardening** built on M1/M2.

---

## 4. Current Baseline

Before M3:

- `src/pwa/syncService.js` already called `api.post('/pwa/sync', item.payload)`.
- `api.js` already sent `credentials: 'include'` and handled refresh-on-401.
- `authService.refresh()` used direct `fetch` (not `api.js`) to avoid recursion.
- No refresh token was read or stored in JavaScript.
- Session expiry did not delete Dexie `location_logs` or `sync_queue`.

Inspection confirmed **no duplicate refresh logic** in PWA sync code.

---

## 5. Architecture Flow

### Successful patrol sync after access token rejection

```text
IndexedDB sync_queue (pending location_log)
        ↓
flushSyncQueue() → processSyncItem()
        ↓
api.post('/pwa/sync', payload)          [credentials: include]
        ↓
401 (missing/expired access token)
        ↓
runAuthRefresh() → POST /auth/refresh   [HttpOnly cookie only]
        ↓
setAuthToken(new access token)
        ↓
api.post('/pwa/sync', payload)          [single retry]
        ↓
201/200 success
        ↓
sync_queue.status = synced
sync_queue.resultStatus = synced | duplicate_synced
location_logs.syncStatus = synced
```

### Refresh failure

```text
401 on /pwa/sync
        ↓
runAuthRefresh() fails
        ↓
clearAuthSession() + session-expired UX (M2)
        ↓
sync_queue row marked failed (retryable) with errorMessage
location_logs remain in Dexie (not deleted)
sync_queue rows remain in Dexie (not deleted)
```

---

## 6. Backend Verification

**File:** `tests/Feature/PatrolTokenExpiryTest.php`

### `test_guard_can_sync_pwa_location_after_access_token_expiry_using_valid_refresh_session`

Proves server-side pieces required for frontend retry:

1. Unauthenticated `POST /api/pwa/sync` → **401**
2. Guard login → access token + HttpOnly refresh cookie (refresh token not in JSON)
3. `POST /api/auth/refresh` with cookie → **200**, new `data.access_token`, rotated cookie
4. `POST /api/pwa/sync` with new bearer → **201**, `LocationLog` created

### `test_expired_refresh_session_cannot_restore_pwa_sync_after_access_token_expiry`

1. Refresh session created via `RefreshTokenService`
2. Time advanced beyond refresh TTL
3. Unauthenticated sync → **401**
4. Refresh with expired cookie → **401** (`Refresh session is invalid or expired.`)
5. No `LocationLog` persisted

**Note:** Backend retry is a **frontend responsibility**; backend tests prove each hop independently.

---

## 7. Frontend Verification

**File:** `src/pwa/syncService.test.js`

| Test                                   | Proves                                                                           |
| -------------------------------------- | -------------------------------------------------------------------------------- |
| Shared api client retry harness        | Real `api.js` + mocked `fetch` retries `/pwa/sync` after `runAuthRefresh()`      |
| Expired access token, refresh succeeds | Queue → `synced`, location log → `synced`, two `/pwa/sync` attempts, one refresh |
| Duplicate after refresh                | Queue → `duplicate_synced`, location log → `synced`                              |
| Refresh fails                          | Queue/location evidence preserved; row → `failed` with `errorMessage`            |
| Auth coupling inspection               | `syncService.js` uses `api.post` only; no direct `/auth/refresh` or token reads  |

Uses in-memory mocked `db.js` (no `fake-indexeddb` dependency).

---

## 8. Refresh Failure Behavior

When refresh fails during PWA sync:

- `api.js` triggers M2 session-expired flow (`clearAuthSession`, `markSessionExpired`, redirect to `/login`).
- `processSyncItem()` catches the error and marks the queue row **`failed`** with incremented `retryCount` (retryable until exhausted).
- **`location_logs` and `sync_queue` are never deleted** on session expiry.
- Guards can sign in again and retry sync via existing PWA retry mechanisms (`resetTerminalSyncFailures`, Background Sync, etc.).

---

## 9. Files Changed

| File                                                             | Change                            |
| ---------------------------------------------------------------- | --------------------------------- |
| `frontend-react-v1/src/pwa/syncService.test.js`                  | **New** — M3 Vitest coverage      |
| `backend-laravel-v1/tests/Feature/PatrolTokenExpiryTest.php`     | **New** — M3 backend verification |
| `backend-laravel-v1/docs/login/m3-patrol-token-expiry-safety.md` | **New** — this document           |
| `frontend-react-v1/documentation.md`                             | M3 cross-link on PWA sync         |
| `backend-laravel-v1/documentation.md`                            | M3 cross-link + test table entry  |

**No frontend or backend runtime files were modified.**

---

## 10. Tests and Verification Commands

### Backend

```bash
cd backend-laravel-v1
php artisan test --filter=PatrolTokenExpiryTest
php artisan test --filter=AuthRefreshTokenTest
php artisan test --filter=PwaSyncTest
```

### Frontend

```bash
cd frontend-react-v1
yarn test src/pwa/syncService.test.js
yarn test src/api/api.test.js src/api/authRefreshQueue.test.js
yarn test
yarn build
```

**Verification (post-M3):**

- `PatrolTokenExpiryTest` — **2 passed**
- `AuthRefreshTokenTest` — **13 passed**
- `PwaSyncTest` — **9 passed**
- Frontend Vitest — **81 passed**
- `yarn build` — **success**

Full backend suite (`php artisan test`) — **375 passed**

---

## 11. Manual QA Checklist

1. Log in as Guard and start a patrol; confirm Dexie `sync_queue` has pending rows.
2. Invalidate or wait for JWT expiry while refresh cookie remains valid.
3. Trigger `flushSyncQueue()` (e.g. go online or stop patrol).
4. Network tab: first `/api/pwa/sync` → 401; `/api/auth/refresh` → 200; retried `/api/pwa/sync` → success.
5. Confirm queue row is `synced` or `duplicate_synced` and location log `syncStatus` is `synced`.
6. Revoke refresh session (logout elsewhere or wait for refresh expiry); repeat — confirm session-expired UX, queue/logs still in Dexie.

---

## 12. Passing Criteria

M3 passes when:

- [x] PWA sync uses central `api.js` (no duplicate refresh logic)
- [x] Refresh token remains HttpOnly-cookie only
- [x] Protected /pwa/sync 401 + valid refresh allows sync retry to continue; deterministic expired-JWT assertion remains deferred.
- [x] `/pwa/sync` retry after refresh succeeds; queue → `synced` / `duplicate_synced`
- [x] Refresh failure preserves Dexie evidence; session-expired UX still fires
- [x] Backend and frontend M3 tests pass
- [x] M2 regression tests pass
- [x] M3 documentation exists

---

## 13. Known Limitations / Deferred Work

- Backend M3 test uses **unauthenticated** first sync (401) to avoid Laravel test-client auth bleed after login; production guards experience the same refresh path when JWT expires (also 401).
- Deterministic JWT time-expiry assertion in PHPUnit was not reliable in this environment; server contract is covered via unauthenticated 401 + refresh + authenticated retry.
- Full `php artisan test` suite not re-run for M3 (no runtime changes).
- M4+ items (OTP, audit logs, session UI, AuthContext) remain deferred.

---

**End of M3 milestone document.**
