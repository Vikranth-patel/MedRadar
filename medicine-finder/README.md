# MedRadar — find the exact medicine before you travel

MedRadar is an academic client–server web app that helps a visitor find an **exact medicine product** at **admin-approved pharmacies**, see **how recently** the pharmacy confirmed it, and **call, get directions, or request confirmation** before travelling.

> **All data is fictional demonstration data.** MedRadar is *not* an emergency service and gives no diagnosis, treatment advice, substitution, online sales, payments, delivery or stock guarantee. In an emergency call **108 / 112**.

**Stack:** HTML5 · CSS3 (Grid/Flexbox, fluid type, dark mode) · vanilla JavaScript (DOM, Fetch/JSON) → **PHP 8 JSON endpoints** → **PDO** → **MySQL / MariaDB**. One backend, no frameworks.

---

## 1. Quick start (XAMPP on Windows)

1. Copy the whole `medicine-finder` folder to `C:\xampp\htdocs\` (replace the old one). Start **Apache** and **MySQL** in the XAMPP control panel.
2. **Create the database:** open <http://localhost/phpmyadmin> → *Import* → choose `database/schema.sql` → *Go*; then import `database/seed.sql` the same way.
   (Command line alternative: `mysql -u root -p < database/schema.sql` then `mysql -u root -p < database/seed.sql`.)
   This creates a **new** database called `medradar`; your old `medicine_finder` database is not touched.
3. **Environment file:** copy `.env.example` to `.env`. XAMPP’s default MySQL user has **no password**, so set `DB_PASS=` (empty). If you use MySQL Workbench with a root password, put it there. `.env` is git-ignored — never commit it.
4. Open <http://localhost/medicine-finder/>.

### Demo accounts (password for all: `password123`)
| Role | Email | Notes |
|---|---|---|
| Administrator | `admin@medradar.test` | reviews pharmacies, catalogue, reports |
| Pharmacy (approved) | `sanjivani@example.com`, `lakecity@example.com`, `hiranmagri@example.com` | visible in public search |
| Pharmacy (pending) | `pending@example.com` | not visible until approved |
| Pharmacy (suspended) | `suspended@example.com` | cannot sign in; listings hidden |

Change these passwords before any real use. New pharmacies can register themselves on the **Register** tab; they start as *pending*.

### Run the tests
```
php -S 127.0.0.1:8080            # in the project folder (or use XAMPP and pass its URL)
php tests/run_tests.php          # or: php tests/run_tests.php http://localhost/medicine-finder
```
Re-import `schema.sql` + `seed.sql` first for a clean state. See [`TESTS.md`](TESTS.md) for the recorded results.

---

## 2. Demonstration script (the “definition of done” walk-through)
1. **Register** a pharmacy (Login → Register). It starts *Pending* and is invisible to visitors.
2. **Admin approves** it (`admin@medradar.test` → Pharmacies → Pending → *Approve*).
3. **Pharmacy lists a product** (Inventory → pick a catalogue product, tick “I checked the shelf just now”).
4. **Confirm stock** (Inventory → *Still available*, or change status). Only these actions refresh the item’s confirmation time.
5. **Visitor searches** (home page → type `Dolo` → pick a strength; optionally enter a locality or PIN). Results show status **and** freshness.
6. **Visitor requests confirmation** (*Request confirmation* → private tracking link).
7. **Pharmacy responds** (Requests → Available / Unavailable / Cannot confirm). The visitor’s tracking page updates automatically.
8. **Unauthorized edit is rejected** — another pharmacy posting to your listing gets **403** and nothing changes (test **T06**).

---

## 3. Roles
| Role | Can |
|---|---|
| **Visitor** (no account) | search, view pharmacy details, request confirmation, track a request, report incorrect information |
| **Pharmacy** | manage **only its own** profile/hours/closures and inventory; reconfirm stock; respond to requests; view its history |
| **Administrator** | review pharmacies (approve / reject / suspend / reactivate with reasons), manage the catalogue, investigate reports, review activity |

## 4. Pages & endpoints
| Area | Files |
|---|---|
| Public pages | `index.php` (search) · `pharmacy.php` · `track.php` · `report.php` · `about.php` |
| Public JSON API | `api/search.php` · `api/request_create.php` · `api/request_status.php` · `api/report_create.php` — see [`API.md`](API.md) |
| Auth | `login.php` (sign in + register) · `logout.php` (POST + CSRF) |
| Pharmacy portal | `dashboard.php` · `inventory.php` · `requests.php` · `profile.php` · `history.php` |
| Admin portal | `admin/index.php` · `pharmacies.php` · `catalogue.php` · `reports.php` · `history.php` |
| Shared | `inc/bootstrap.php` (security, auth, policies) · `inc/layout.php` · `config.php` · `cron/purge.php` |
| Front end | `css/style.css` · `js/app.js` (shared UI) · `js/search.js` · `js/public.js` |
| Database | `database/schema.sql` · `database/seed.sql` |

## 5. Documented policies (prototype policies, not guarantees)
| Rule | Value | Where |
|---|---|---|
| **Freshness** | *Recently confirmed* = confirmed ≤ **24 h** ago. *Stale* = older (availability **uncertain**, never auto “out of stock”). *Never confirmed* = no confirmation. *Reported out of stock* = pharmacy marked it. | `FRESH_HOURS`, `freshness()` |
| What refreshes `last_confirmed_at` | **Only** *Still available*, *Mark out of stock*, a status change, or “checked the shelf” when adding. **Not** profile edits, deactivate/reactivate, or request replies. | `inventory.php` |
| **Request expiry** | **4 h** (`REQUEST_TTL_HOURS`). Evaluated **on every read/update** (`effective_request_status()`), so it never depends on a background job. Expired requests cannot be answered. | `inc/bootstrap.php` |
| Request privacy | No name/phone/email. Random 128-bit token, only its **SHA-256 hash** stored. | `api/request_create.php` |
| Approval workflow | pending → approved \| rejected · approved → suspended · suspended → approved. Reject/suspend need a **reason**; every decision is logged. | `admin/pharmacies.php` |
| Catalogue | Same brand + different strength/form = **separate** product. Exact duplicates blocked (unique key + case-insensitive check). Entries are **archived, never deleted**. | `admin/catalogue.php` |
| Retention | requests 30 d · closed reports 180 d · audit events 365 d · rate-limit rows 1 d (`php cron/purge.php`, schedule daily) | `config.php`, `cron/purge.php` |
| Sessions | idle timeout 30 min, absolute 8 h, `HttpOnly` + `SameSite=Lax` cookie, ID regenerated on login | `inc/bootstrap.php` |
| Rate limits | login 5 failures/15 min per IP+email (30/IP) · requests 5/h/IP · reports 5/h/IP · registrations 5/h/IP | `rate_blocked()` |

## 6. Security measures
PDO **prepared statements** everywhere · output escaped with `htmlspecialchars` · **CSRF tokens** on every state-changing form/API call (logout included) · **role checks** on every protected page and **ownership checks** on every inventory/request action (`WHERE id = ? AND pharmacy_id = ?`) · generic login errors + rate limiting · `password_hash` (bcrypt) · sessions hardened · strict integer parsing (arrays/odd types rejected) · `display_errors` off and **no raw database errors** ever reach the browser · secrets only in `.env` · no location permission, no tracking, no visitor accounts.

## 7. Database (ER diagram)
```mermaid
erDiagram
    users ||--o| pharmacies : "owns"
    pharmacies ||--o{ inventory : "lists"
    medicines  ||--o{ inventory : "is listed as"
    inventory  ||--o{ availability_requests : "asked about"
    inventory  ||--o{ stock_events : "audited by"
    pharmacies ||--o{ information_reports : "reported"
    inventory  |o--o{ information_reports : "about (optional)"
    users ||--o{ stock_events : "actor"
    users ||--o{ admin_events : "actor"
    users ||--o{ information_reports : "reviewed by"

    users { int id PK  varchar email UK  varchar password_hash  enum role  enum status }
    pharmacies { int id PK  int owner_user_id FK_UK  varchar name  varchar phone  varchar address  varchar locality  char pin_code  text hours  date closed_until  enum approval_status  varchar review_reason }
    medicines { int id PK  varchar product_name  varchar ingredient_name  varchar strength  varchar dosage_form  tinyint archived }
    inventory { int id PK  int pharmacy_id FK  int medicine_id FK  enum status  tinyint is_active  datetime created_at  datetime updated_at  datetime last_confirmed_at }
    availability_requests { int id PK  int inventory_id FK  char token_hash UK  enum status  datetime expires_at  datetime responded_at }
    stock_events { bigint id PK  int actor_id FK  int inventory_id FK  varchar action  varchar before_value  varchar after_value }
    information_reports { int id PK  int pharmacy_id FK  int inventory_id FK  enum category  enum status  varchar admin_note }
    admin_events { bigint id PK  int actor_id FK  varchar affected_type  int affected_id  varchar action }
    rate_limits { bigint id PK  varchar bucket  char key_hash }
```
Key constraints: `UNIQUE(pharmacy_id, medicine_id)` (one listing per pair), `UNIQUE(product_name, strength, dosage_form)` (no duplicate catalogue entries), `UNIQUE(email)`, `UNIQUE(token_hash)`; indexes on `inventory(medicine_id, is_active, status, last_confirmed_at)`, pharmacy locality / PIN / approval status, request expiry.

## 8. Release coverage
| Release | Scope | Status |
|---|---|---|
| **1** | exact-product search · locality/PIN filter · catalogue · pharmacy registration + approval · sessions · ownership-protected inventory · stock timestamps · contact/directions · responsive design · essential security | ✔ done |
| **2** | private confirmation requests · expiry · reports · history · workflow/authorization tests · deployment + documentation | ✔ done |
| **3** | optional (multi-medicine search, CSV import, maps/distance, staff accounts) | not included — “choose only after Release 2 works” |

## 9. Syllabus mapping
| Module | Evidence |
|---|---|
| 1 Fundamentals | semantic HTML5 pages, forms, tables, fluid responsive CSS (Grid/Flexbox), mobile table→card layouts, dark mode, accessibility (labels, focus rings, icon+text status) |
| 2 Frontend | DOM + events, debounced autocomplete, Fetch/JSON, filters/sort, loading / empty / error+retry states, form validation, `<dialog>` modal, polling tracker |
| 3 Backend | PHP endpoints, sessions + cookies, role checks, CSRF, rate limiting |
| 4 Database | PDO, 9 related tables, CRUD, joins, constraints, indexes, transactions (registration) |
| 5 Tools | Git-ready repo (`.gitignore`, `.env.example`), acceptance tests with recorded results, retention script, screenshots |

## 10. Known limitations
- Pharmacy-reported data only; no verification that stock is physically present. “Admin-approved” is **not** a regulatory/licence check.
- Demo data is fictional; passwords in the seed are for demonstration only.
- Requests have **no push notification** (visitor polls the tracking page; no SMS/email by design).
- Locality/PIN matching is text-based (no geocoding, distance or map view).
- Rate limiting is per IP address (shared networks share limits); no CAPTCHA or email verification.
- Single administrator role; no multi-staff pharmacy accounts; no password-reset by email.
- Not load-tested; designed for a single small deployment.

## 11. Git & deployment notes
```
git init && git add . && git status     # .env must NOT appear in the list
git commit -m "MedRadar: initial import"
```
Suggested history: schema/seed → bootstrap + auth → catalogue + inventory → public search → requests + reports → admin → tests/docs.
Deploy to any PHP 8.1+ / MySQL 5.7+/MariaDB 10.4+ host: upload the folder, create `.env`, import the SQL, schedule `php cron/purge.php` daily, serve over **HTTPS** (the session cookie becomes `Secure` automatically).

## 12. Protecting files on Apache
`.htaccess` files block direct web access to `.env`, `*.sql`, `config.php`, `*.md`, `inc/`, `database/`, `cron/` and `tests/`. Keep them when deploying (Apache needs `AllowOverride All` for the site, which XAMPP enables by default). On nginx, add equivalent `deny all` rules.
