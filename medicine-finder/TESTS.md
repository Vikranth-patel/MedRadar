# MedRadar — test cases and recorded results

**Result: 21/21 passed** · run on 2026-10-31 07:04:06 (PHP 8.3, MariaDB 10.11, PHP built-in server) against a freshly imported `schema.sql` + `seed.sql`.

The runner (`tests/run_tests.php`) makes **real HTTP requests** — including **direct unauthorized calls** to protected endpoints with no session, the wrong role, another pharmacy's session, and missing/forged CSRF tokens — and inspects the database with PDO to prove that nothing changed. Re-run it yourself:

```
php -S 127.0.0.1:8080
php tests/run_tests.php
```

## Specification acceptance tests (T01–T14) and extra workflow tests (W01–W07)
| ID | Test | Result |
|---|---|---|
| T01 | Same product name, different strength → separate, clearly labelled products | **PASS** |
| T02 | Location permission denied → manual locality / PIN search still works | **PASS** |
| T03 | Old confirmation time → stale warning, never an invented out-of-stock status | **PASS** |
| T04 | Edit pharmacy profile → stock confirmation times unchanged | **PASS** |
| T05 | Pending / suspended pharmacy has listings → no public visibility | **PASS** |
| T06 | Pharmacy A edits Pharmacy B inventory → rejected, no database change | **PASS** |
| T07 | Visitor calls protected write endpoints → rejected (direct unauthorized calls) | **PASS** |
| T08 | Request expiry passes without cleanup → read/update logic treats it as expired | **PASS** |
| T09 | No medicine matches → honest empty state and no substitute claim | **PASS** |
| T10 | Pharmacy confirms availability → response time shown; no reservation guarantee | **PASS** |
| T11 | Duplicate inventory insertion → constraint prevents a second pharmacy/product row | **PASS** |
| T12 | Invalid / hostile input → validated or safely displayed; no raw database error | **PASS** |
| T13 | Unrelated user accesses request → private request details are not exposed | **PASS** |
| T14 | Catalogue entry archived → historical records remain interpretable | **PASS** |
| W01 | Full demonstration: register → admin approves → list → confirm → visitor searches → request → pharmacy responds → unauthorized edit rejected | **PASS** |
| W02 | Stock rules: only explicit actions refresh confirmation; reactivate keeps old time; status change confirms | **PASS** |
| W03 | CSRF: state-changing calls without a valid token are rejected | **PASS** |
| W04 | Login rate limiting, generic errors, session cookie flags | **PASS** |
| W05 | Admin workflow rules: transitions, mandatory reasons, catalogue duplicates, report review | **PASS** |
| W06 | Private request rules: duplicate pending blocked, rate limit, requests for hidden listings refused | **PASS** |
| W07 | Retention script deletes only old data (expiry does not depend on it) | **PASS** |

## What each T-test proves
| ID | Evidence checked |
|---|---|
| T01 | `Dolo 500 mg` and `Dolo 650 mg` are two catalogue products with disjoint listings; `medicine_id` search returns only the chosen strength |
| T02 | Locality and PIN filters work with no browser-location API anywhere in the JS |
| T03 | A 3-day-old confirmation is `stale` with an “uncertain” explanation while `status` stays `in_stock`; unconfirmed items are `never`, not hidden or out of stock |
| T04 | Saving the profile changes the phone number but `last_confirmed_at` of every listing is byte-identical |
| T05 | Pending and suspended pharmacies never appear in search; their public pages return 404; a suspended account cannot sign in |
| T06 | Pharmacy A's `set_status / confirm / out_of_stock / deactivate` on B's listing and answering B's request all return **403**; B's row is unchanged |
| T07 | Visitor POSTs to 8 protected endpoints → 401/403, GETs → 302 to login, DB counts unchanged; pharmacy role → 403 on admin pages; admin → 403 on pharmacy pages |
| T08 | A request whose `expires_at` passed but whose DB status is still `pending` (no cleanup ran) is read as `expired` and cannot be answered |
| T09 | Unknown product → empty state with “does not suggest substitutes”; exact product with no listing in an area → empty group, nothing else substituted |
| T10 | Reply shows `responded_at` and note; both responses carry “not a reservation”; stock status is unchanged; a request cannot be answered twice |
| T11 | App refuses a second listing; the database itself rejects it (SQLSTATE 23000) |
| T12 | SQL-injection strings, 500-char queries, wildcards, bad JSON, array-typed fields, invalid categories → clean 4xx/200, **no** `SQLSTATE`/stack-trace text; XSS payloads in registration are HTML-escaped when an admin views them |
| T13 | Request guesses (numeric id, padded id, md5, random token) → 404; only a token **hash** is stored; another pharmacy cannot see or answer it; admin overview exposes counts only |
| T14 | Archived product disappears from search and new requests but rows, history and admin view remain; restore brings it back |

## Defects found by these tests and fixed during development
1. **Array-typed input** (`inventory_id[]=1`) was cast to the integer 1 and accepted → added strict `int_val()` parsing (found by T12).
2. **Session cleared mid-request** (suspended user) lost its CSRF token → `logout_user()` now regenerates the session instead of destroying it (found by W01).
3. **Seed timestamps** used the DB server's clock while the app uses India time (5½-hour skew) → `seed.sql` now sets `time_zone` explicitly (found while reviewing screenshots).

## Screenshots
Located in `docs/screenshots/` — home, autocomplete, results (light/dark/mobile), request dialog, tracking, pharmacy page, about, login/register, pharmacy dashboard/inventory/requests/profile, admin overview/pharmacies/catalogue/history.
