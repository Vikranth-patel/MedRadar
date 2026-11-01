# MedRadar — endpoint documentation

All JSON endpoints live in `api/`. Responses are `application/json; charset=utf-8` with `Cache-Control: no-store`.
Errors are `{ "error": "message" }` with a meaningful status code; **raw database errors are never returned**.
State-changing calls need a **CSRF token** (`X-CSRF-Token` header, taken from `<meta name="csrf">`, or a `csrf` form field).

## Public (visitor, no account)

### `GET api/search.php`
| Param | Meaning |
|---|---|
| `mode=suggest&q=` | autocomplete: ≤ 8 catalogue products (`id, label, product_name, ingredient_name, strength, dosage_form`) |
| `q=` | product or ingredient text (2–80 chars) |
| `medicine_id=` | exact catalogue product (overrides `q`) |
| `place=` | locality text **or** 6-digit PIN |
| `availability=` | `any` \| `in_stock` \| `low_stock` \| `out_of_stock` |
| `fresh=1` | only “recently confirmed” |
| `sort=` | `confirmed` (default) \| `name` |

`200` → `{ query, place, total, fresh_hours, medicines: [ { medicine:{id,label,…}, results:[ { inventory_id, pharmacy_id, pharmacy_name, address, locality, pin_code, phone, status, status_text, freshness:{key,label,detail,at}, hours_today, closed_today, approval } ] } ] }`
No match → `200` with `medicines: []` and a message stating that MedRadar **does not suggest substitutes**. `422` if `q` < 2 chars.
Only listings that are active, not archived, at an **approved** pharmacy with an **active** account are returned.

### `POST api/request_create.php`  `{ "inventory_id": 12 }`
Creates a private availability-confirmation request. `201` → `{ ok, token, track_url, expires_in_hours, product, pharmacy, notice }` (the token is shown **once**; only its SHA-256 hash is stored).
`403` bad/missing CSRF · `404` listing not public · `409` you already have a pending request for it · `422` invalid id · `429` rate limit (5/hour/IP).

### `GET api/request_status.php?t=<32-hex token>`
`200` → `{ status, status_label, product, pharmacy, phone, address, created_at, expires_at, responded_at, note, notice }` where `status` ∈ `pending | confirmed_available | confirmed_unavailable | cannot_confirm | expired`. **Expiry is evaluated here on read.** Unknown/malformed token → `404` (no information leaked). Rate-limited.

### `POST api/report_create.php`  `{ pharmacy_id, inventory_id?, category, explanation? }`
`category` ∈ `stock | contact | address | closure | misleading`; `explanation` ≤ 300 chars (truncated). `201` on success. A report **never** changes stock or suspends a pharmacy. `403/404/422/429` as above.

## Protected pages (server-rendered forms, CSRF + role + ownership checks)
| Endpoint | Role | Actions (`action=`) |
|---|---|---|
| `inventory.php` POST | pharmacy | `add`, `set_status`, `confirm`, `out_of_stock`, `deactivate`, `activate` — every listing action requires `inventory.pharmacy_id` = your pharmacy, else **403** |
| `requests.php` POST | pharmacy | respond: `available` \| `unavailable` \| `cannot_confirm` (+ note ≤ 200). Own requests only; expired/answered cannot be answered |
| `profile.php` POST | pharmacy | `profile` (details, hours, closure), `password` |
| `dashboard.php` POST | pharmacy | `resubmit` (rejected → pending) |
| `admin/pharmacies.php` POST | admin | `approve`, `reject`*, `suspend`*, `reactivate` (*reason required) |
| `admin/catalogue.php` POST | admin | `add`, `edit`, `archive`, `restore` |
| `admin/reports.php` POST | admin | `resolve`, `dismiss`, `reopen` |
| `logout.php` POST | any | sign out (CSRF-protected) |

**Unauthenticated** POST → `401`; **wrong role** → `403`; **missing/forged CSRF** → `403`; unauthenticated GET of a protected page → `302` to `login.php`.
