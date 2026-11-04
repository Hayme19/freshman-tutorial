# Implementation Plan — Freshman Tutorial Platform

Authoritative spec: `DESIGN.md` at the workspace root (read sections 6, 10, 11.1–11.8, 12, 13, 14, 15 in full). Two top-level directories go under the workspace root `c:/Users/Hp Computer/Desktop/A+ Freshman Tutorial`: `backend/` (PHP 8.2 REST API, Render/Docker, MongoDB Atlas) and `frontend/` (static HTML5/CSS3/JS, Netlify). Build sequence: backend skeleton + DB + auth → controllers → seed → frontend pages → verification + report.

Environment facts (verified): **PHP and composer are NOT installed** here, so `php -l` cannot run — backend verification is a careful manual syntax/structure review. **Python 3.14 and Node are available** — use `python -m json.tool <file>` to validate JSON. No MongoDB Atlas cluster and no Render exist here, so the full app cannot be runtime-tested end to end. Paths contain spaces and a backslash-style Windows separator; prefer quoting absolute paths.

Autoloader decision (pick ONE, state in report): all backend classes live in the `App\` namespace with PSR-4 `App\\ => src/` (e.g. `App\Controllers\AuthController` at `src/controllers/AuthController.php`). `public/index.php` declares `namespace`-less top-level code with `use` imports for every class it references (`Cors`, `AuthMiddleware`, `Response`, `Dotenv`, and all controllers), matching DESIGN.md §11.2's structure.

---

## Phase A — Backend (maps to FEAT-001)

- [ ] A1. Create the backend skeleton: `composer.json` (require `mongodb/mongodb`, `firebase/php-jwt`, `vlucas/phpdotenv` pinned to recent stable; autoload PSR-4 `App\\ => src/`), `Dockerfile` verbatim from DESIGN §15, `.env.example` documenting `MONGODB_URI`, `DB_NAME`, `JWT_SECRET`, `FRONTEND_URL` with placeholders, and `public/.htaccess` verbatim from DESIGN §13.
      Files: backend/composer.json, backend/Dockerfile, backend/.env.example, backend/public/.htaccess
      Verify: `python -m json.tool backend/composer.json` prints valid JSON with no error.

- [ ] A2. Create the core infrastructure: `Database.php` (singleton MongoDB client from `MONGODB_URI`+`DB_NAME`, exposes all collections from §10, graceful connection-error handling), `Response.php` (`Response::json($success,$data,$message,$status=200)` sets `Content-Type: application/json`, the http code, echoes `{success,data,message}`, never crashes if output started), `Cors.php` per DESIGN §11.5 (comma-separated `FRONTEND_URL`, exact origin match, OPTIONS 204 exit), and `AuthMiddleware.php` per DESIGN §11.4 (JWT decode with `JWT_SECRET` HS256, role check including `any`, returns payload `sub/role/stream/exp`; add a helper that fetches the authenticated user document from DB by `sub`).
      Files: backend/src/config/Database.php, backend/src/helpers/Response.php, backend/src/middleware/Cors.php, backend/src/middleware/AuthMiddleware.php
      Verify: manual review — each class is in the correct `App\` subnamespace matching its path, signatures match DESIGN §11.4/§11.5, `Response::json` matches the §12 response shape.

- [ ] A3. Create `AuthController` (register: validate + reject unexpected fields, `password_hash` bcrypt, unique email; login: `password_verify`, JWT 24h signed with `JWT_SECRET`, rate-limit 5 failures/15 min per email+IP using a `logins` collection, return `{token,user}`; me: return user doc minus `passwordHash`) and `UserController` (PUT `/users/me` whitelisting `fullName`,`avatarUrl`,`stream` in {natural,social}). Wire the auth + users routes into `public/index.php` following the §11.2 router pattern (use-imports, `[$method,'#regex#',[Controller::class,'method'],$roles]`, `AuthMiddleware::require($roles)`, 404 fallback).
      Files: backend/src/controllers/AuthController.php, backend/src/controllers/UserController.php, backend/public/index.php
      Verify: manual review — register hashes passwords, login issues 24h JWT and rate-limits, `me` omits `passwordHash`; index.php autoloads and routes auth/users with correct roles.

- [ ] A4. Create the learning controllers: `CourseController` (list with whitelisted `?stream`/`?group` and case-insensitive `?q` regex on title/description, `isPublished` visibility by role per §11.3; create instructor/admin; update/delete owner-or-admin), `LessonController` (GET `/courses/{id}/lessons` — enrolled see all, guests/unenrolled see `isFree` only, cast id in try/catch; POST/PUT `/lessons` instructor/admin), `EnrollmentController` (POST `/enrollments` student; create if none, unique index prevents dupes), `ProgressController` (POST `/progress/complete-lesson` `$addToSet` lesson id, recompute `progressPercent=round(count/totalLessons*100)`, at 100% + final quiz ≥50% set `completedAt` + create certificate; GET `/progress/me` returns enrollments + quiz history), `QuizController` (GET `/quizzes/{id}`; POST `/quizzes/{id}/attempt` auto-grade MC/TF by `correctIndex`, record `quizAttempt`, return `score/total`). Wire all their routes.
      Files: backend/src/controllers/CourseController.php, backend/src/controllers/LessonController.php, backend/src/controllers/EnrollmentController.php, backend/src/controllers/ProgressController.php, backend/src/controllers/QuizController.php, backend/public/index.php (append routes)
      Verify: manual review — every method casts ids with the BSON ObjectId constructor in try/catch (400 on malformed), Mongo filters use whitelisted typed values, progress math matches §11.8.

- [ ] A5. Create the remaining controllers: `SubmissionController` (POST `/submissions` student; PUT `/submissions/{id}/grade` instructor), `ForumController` (GET/POST `/forum`; POST `/forum/{id}/reply` logged-in, tutors/instructors answer), `AnnouncementController` (GET logged-in; POST instructor/admin), `AdminController` (GET `/admin/users` paginated/filtered; PUT `/admin/users/{id}/role` validate target role, prevent demoting last admin), `ReportController` (GET `/reports/summary` enrollment counts, completion rate, avg quiz score, active users per stream; admin+instructor), `CertificateController` (GET `/certificates/{id}` for printing; owner), `FitnessLogController` (Sport activity log CRUD; student), `NotificationController` (list + mark-read; logged-in). Optionally add `src/models/` helpers centralizing ObjectId casting + BSON→JSON serialization (ObjectId→`{$oid}`, Date→ISO string). Wire every remaining §12 route into `index.php` so the full endpoint table is covered.
      Files: backend/src/controllers/SubmissionController.php, backend/src/controllers/ForumController.php, backend/src/controllers/AnnouncementController.php, backend/src/controllers/AdminController.php, backend/src/controllers/ReportController.php, backend/src/controllers/CertificateController.php, backend/src/controllers/FitnessLogController.php, backend/src/controllers/NotificationController.php, backend/src/models/* (optional), backend/public/index.php (finalize routes)
      Verify: cross-check — every row of DESIGN §12 has a route in index.php AND a matching controller method.

- [ ] A6. Create `seed/courses.json` verbatim from DESIGN §11.1 (16 courses) and `seed/load.php` that loads `.env`, connects via `Database`, upserts courses by `code` (idempotent), creates ALL §10 indexes (`users.email` unique, `courses.streams`, `lessons.courseId+order`, `enrollments.studentId+courseId` unique, `quizAttempts.studentId`), and inserts real sample content: LOCT101 (common) 3 lessons (Arguments and propositions; Common fallacies; Deductive vs inductive reasoning) + 1 exercise + 1 quiz of 5 TF/MC; MATH101 (natural) 3 lessons (Functions; Limits and continuity; Derivatives) with KaTeX examples incl. the limit of sin(x)/x as x→0 equals 1 and derivative rules + 1 exercise + 1 quiz of 5 numeric; ECON101 (social) 3 lessons (Demand and supply; Market equilibrium; National income) + 1 exercise + 1 quiz of 5; WEBD101 (digital) 5 lessons (HTML basics; CSS Flexbox/Grid/responsive; JS DOM/events/fetch; PHP forms/sessions/REST/MongoDB; Mini project) each with a working `codeExample` for the playground. First lesson of each course `isFree=true`. Print a clear summary of inserted counts.
      Files: backend/seed/courses.json, backend/seed/load.php
      Verify: `python -m json.tool backend/seed/courses.json` is valid; manual review of load.php confirms upsert-by-code, all indexes, and the four sample courses with first lessons free.

- [ ] A7. Backend verification pass: validate all JSON with `python -m json.tool`; manual PHP syntax/structure review of every `.php` file (balanced braces/quotes, correct class/method signatures, namespace+use statements, every class referenced in index.php exists under src/ and is PSR-4 autoloadable); endpoint→controller cross-check of every §12 route. Fix every issue found.
      Files: (no new files; fixes across backend/)
      Verify: all JSON validates; manual review clean; §12 endpoint/controller matrix complete.

## Phase B — Frontend (maps to FEAT-002)

- [ ] B1. Create the shared frontend core: `js/config.js` (`const API_BASE_URL = "https://your-api.onrender.com/api";` documented placeholder), `js/api.js` (the DESIGN §11.6 fetch helper — Authorization Bearer from localStorage, loading banner `#loading` shown after 3s with text "Waking up the server, this can take up to a minute on the first visit.", 401 clears token + redirects to `login.html`, returns parsed JSON), `js/auth.js` (register/login/logout, token storage, current-user cache, role guards, role-adaptive navbar), `css/style.css` (responsive mobile-first, accessible focus/contrast, low-bandwidth hand-written CSS), and `_headers` (Netlify strict CSP: `default-src 'self'`; `script-src 'self'` plus the Chart.js and KaTeX CDN origins; `style-src 'self'` plus those CDNs; `connect-src 'self' https://your-api.onrender.com`; `frame-src 'self'`; `img-src 'self' data: https:`).
      Files: frontend/js/config.js, frontend/js/api.js, frontend/js/auth.js, frontend/css/style.css, frontend/_headers
      Verify: manual review — api.js matches §11.6 behavior; _headers CSP allows the used CDN origins and the API origin.

- [ ] B2. Create the entry + catalog pages: `index.html` (landing + stream picker Natural/Social, saves choice to localStorage, routes to register/login), `login.html` + `register.html` (forms calling `/auth/login` and `/auth/register`, store JWT, redirect by role), `courses.html` + `js/courses.js` (list/filter by stream/group/keyword per §11.7 using textContent never innerHTML; guests see only published; logged-in students see their stream's courses with an Enroll button → POST `/enrollments`; cards link to `lesson.html?course=<id>`). Each page links the css/js it needs and has a `#loading` banner + navbar container.
      Files: frontend/index.html, frontend/login.html, frontend/register.html, frontend/courses.html, frontend/js/courses.js
      Verify: each HTML links config.js/api.js/auth.js/style.css; every element id referenced by its JS exists in the HTML.

- [ ] B3. Create the learning/interaction pages: `lesson.html` + `js/lesson.js` (load `GET /courses/{id}/lessons`, render text safely with textContent + KaTeX for `$...$`/`$$...$$`, show `codeExample` with "Open in Playground" passing code to playground.html, sandboxed video embed, "Mark complete" → POST `/progress/complete-lesson`, prev/next navigation), `playground.html` + `js/playground.js` (three textareas HTML/CSS/JS + Run building the preview iframe via `srcdoc` with `sandbox="allow-scripts"`, "Load from lesson" reading passed code; never run PHP server-side), `quiz.html` + `js/quiz.js` (fetch `GET /quizzes/{id}`, render MC/TF, submit `POST /quizzes/{id}/attempt`, show graded score).
      Files: frontend/lesson.html, frontend/js/lesson.js, frontend/playground.html, frontend/js/playground.js, frontend/quiz.html, frontend/js/quiz.js
      Verify: HTML↔JS id cross-check per page; playground iframe uses `sandbox="allow-scripts"` and `srcdoc`.

- [ ] B4. Create the dashboard/community/certificate pages: `dashboard.html` + `js/dashboard.js` (progress per course from `GET /progress/me` rendered with Chart.js via CDN), `forum.html` + `js/forum.js` (per-course discussion: list `GET /forum?courseId=`, create `POST /forum`, reply `POST /forum/{id}/reply`; all user content textContent only), `certificate.html` + `js/certificate.js` (fetch `GET /certificates/{id}`, render printable certificate, print button via `window.print`).
      Files: frontend/dashboard.html, frontend/js/dashboard.js, frontend/forum.html, frontend/js/forum.js, frontend/certificate.html, frontend/js/certificate.js
      Verify: HTML↔JS id cross-check; Chart.js/KaTeX loaded via CDN; no innerHTML with API content.

- [ ] B5. Create the admin pages: `admin/users.html` + `js/admin/users.js` (list `GET /admin/users` paginated/filtered, change role `PUT /admin/users/{id}/role`; admin only), `admin/courses.html` + `js/admin/courses.js` (create/edit/delete courses and manage lessons; instructor/admin), `admin/reports.html` + `js/admin/reports.js` (fetch `GET /reports/summary`, render enrollment/completion/quiz/active-users charts with Chart.js; admin+instructor). Each admin page enforces its role client-side via auth.js (and the backend enforces server-side).
      Files: frontend/admin/users.html, frontend/js/admin/users.js, frontend/admin/courses.html, frontend/js/admin/courses.js, frontend/admin/reports.html, frontend/js/admin/reports.js
      Verify: HTML↔JS id cross-check per page; role guards present; API paths match backend routes.

- [ ] B6. Frontend verification pass: for every HTML page confirm linked css/js exist and every element id referenced by its JS exists in the HTML; confirm the `frontend/` tree matches DESIGN §13; confirm no JS uses innerHTML with user/API content. Fix every mismatch.
      Files: (no new files; fixes across frontend/)
      Verify: folder structure matches §13; id cross-check clean; innerHTML audit clean.

## Phase C — Cross-FEAT verification & report (convergence loop + finalize)

- [ ] C1. Run the full verification checklist (JSON validation; manual PHP review; endpoint→controller matrix; HTML↔JS id cross-check; frontend↔backend API path/shape consistency; folder-structure match to §13). Fix the seams between backend and frontend (e.g., a frontend call path not matching a backend route, id shape `_id.$oid` handling).
      Files: fixes across backend/ and frontend/ as needed; write evidence to `.agents/tasks/verification.md`
      Verify: every check passes or every issue is fixed; reviewer writes `.agents/tasks/review.json` verdict.

- [ ] C2. Write `IMPLEMENTATION_REPORT.md` at the workspace root: what was built (file-tree summary of backend/ and frontend/), how to run `seed/load.php` locally, how to deploy backend to Render and frontend to Netlify, env vars to set (`MONGODB_URI`, `DB_NAME`, `JWT_SECRET`, `FRONTEND_URL`, `API_BASE_URL`), verification results, EXPLICITLY what could NOT be runtime-tested (no MongoDB Atlas, no Render, no PHP/composer locally), and tradeoffs/assumptions (innerHTML policy, autoloader namespace choice, hand-written CSS vs framework).
      Files: IMPLEMENTATION_REPORT.md
      Verify: report accurately reflects what was verified vs not; env vars and deploy steps match DESIGN §15.

---

## Verification checklist (the implementer MUST run before declaring done)

1. JSON validity: `python -m json.tool backend/composer.json`; `python -m json.tool backend/seed/courses.json`; and any seed content JSON file. All must print valid JSON with no error.
2. PHP: if `php` is on PATH, run `php -l` on EVERY `.php` file and fix all syntax errors. **PHP is NOT installed here**, so instead do a careful manual syntax/structure review of every `.php` file — balanced braces/quotes, correct class/method signatures, correct namespace+use statements, and every class referenced in `public/index.php` actually exists under `src/` and is autoloadable under `App\`.
3. Endpoint→controller cross-check: every endpoint in DESIGN §12 has a matching route entry in `backend/public/index.php` AND a corresponding controller method that exists.
4. HTML→JS id cross-check: for each frontend HTML page, confirm it links the correct css/js files and that every element id referenced by its JS (`getElementById`/`querySelector('#...')`) exists in that HTML.
5. Folder-structure match: the on-disk `frontend/` and `backend/` trees match DESIGN §13 exactly.
6. Runtime limits (state in report): the full app cannot be run here (no MongoDB Atlas cluster, no Render deployment, no PHP/composer locally). List exactly what was verified (static checks above) and what could NOT be runtime-tested.

---

## File enumeration (every file to create)

### backend/
| File | One-line description + key behavior | DESIGN ref |
|------|--------------------------------------|-----------|
| backend/composer.json | Deps (mongodb/mongodb, firebase/php-jwt, vlucas/phpdotenv pinned) + PSR-4 `App\\ => src/` autoload. | §9, §13 |
| backend/Dockerfile | php:8.2-apache, pecl mongodb, rewrite/headers, composer install, doc root=public. | §15 (verbatim) |
| backend/.env.example | Documents MONGODB_URI, DB_NAME, JWT_SECRET, FRONTEND_URL placeholders (no real secrets). | §15 |
| backend/public/.htaccess | Rewrite all non-file requests to index.php. | §13 (verbatim) |
| backend/public/index.php | Full router: all §12 routes as `[method,'#regex#',[Controller::class,'method'],$roles]`, AuthMiddleware::require, {id} captured, 404 fallback. | §11.2, §12 |
| backend/src/config/Database.php | Singleton MongoDB client from env; exposes all §10 collections; graceful errors. | §10, §15 |
| backend/src/helpers/Response.php | `Response::json($success,$data,$message,$status=200)` → `{success,data,message}`. | §11.2, §12 |
| backend/src/middleware/AuthMiddleware.php | JWT decode + role check incl `any`; returns payload; user-doc helper. | §11.4 |
| backend/src/middleware/Cors.php | Comma-separated FRONTEND_URL, exact match, OPTIONS 204. | §11.5 |
| backend/src/controllers/AuthController.php | register (hash), login (verify+JWT 24h+rate-limit), me (no passwordHash). | §11.8, §14 |
| backend/src/controllers/UserController.php | PUT /users/me whitelisting name/avatar/stream. | §6 mod2, §12 |
| backend/src/controllers/CourseController.php | list (?stream/?group/?q, visibility by role), create, update/delete (owner/admin). | §11.3, §12 |
| backend/src/controllers/LessonController.php | GET lessons (enrolled or free), POST/PUT lessons (instructor/admin). | §12 |
| backend/src/controllers/EnrollmentController.php | POST enrollments (student, unique-index dedupe). | §12 |
| backend/src/controllers/ProgressController.php | complete-lesson ($addToSet + recompute %, completion+certificate), GET /progress/me. | §11.8, §12 |
| backend/src/controllers/QuizController.php | GET quiz, POST attempt (auto-grade MC/TF, record attempt). | §12 |
| backend/src/controllers/SubmissionController.php | POST submission (student), PUT grade (instructor). | §12 |
| backend/src/controllers/ForumController.php | GET/POST forum, POST reply (logged-in; tutors/instructors answer). | §12 |
| backend/src/controllers/AnnouncementController.php | GET (logged-in), POST (instructor/admin). | §12 |
| backend/src/controllers/AdminController.php | GET users (paginated/filtered), PUT role (validate, protect last admin). | §12, §14 |
| backend/src/controllers/ReportController.php | GET /reports/summary (enrollment, completion, avg quiz, active per stream). | §12, §6 mod13 |
| backend/src/controllers/CertificateController.php | GET certificate by id (printable; owner). | §12, §6 mod12 |
| backend/src/controllers/FitnessLogController.php | Sport fitness log CRUD (student). | §6 mod15 |
| backend/src/controllers/NotificationController.php | List + mark-read notifications (logged-in). | §6 mod11 |
| backend/src/models/* (optional) | ObjectId casting + BSON→JSON serialization helpers. | §10, §14 |
| backend/seed/courses.json | The 16 merged courses. | §11.1 (verbatim) |
| backend/seed/load.php | Upsert courses by code, create §10 indexes, insert LOCT101/MATH101/ECON101/WEBD101 sample content; print summary. | §10, §11.1, task spec |

### frontend/
| File | One-line description + key behavior | DESIGN ref |
|------|--------------------------------------|-----------|
| frontend/index.html | Landing + stream picker (Natural/Social), saves choice, routes to register/login. | §13 |
| frontend/login.html | Login form → /auth/login, store JWT, redirect by role. | §13 |
| frontend/register.html | Register form → /auth/register, store JWT, redirect. | §13 |
| frontend/courses.html | List/filter courses (stream/group/keyword), Enroll button. | §11.7, §13 |
| frontend/lesson.html | Lesson viewer: text/KaTeX/code/video, Mark complete, nav, Open in Playground. | §13, §14 |
| frontend/playground.html | HTML/CSS/JS editor, sandboxed iframe preview, Load from lesson. | §6 mod5, §14 |
| frontend/quiz.html | Fetch quiz, render, submit attempt, show score. | §13 |
| frontend/dashboard.html | Progress charts (Chart.js) from /progress/me. | §6 mod8, §13 |
| frontend/forum.html | Per-course discussion (list/create/reply, textContent only). | §6 mod9, §13 |
| frontend/certificate.html | Printable certificate by id; print button. | §6 mod12, §13 |
| frontend/admin/users.html | Manage users/roles (admin). | §6 mod14, §13 |
| frontend/admin/courses.html | Manage courses/lessons (instructor/admin). | §6 mod3/4, §13 |
| frontend/admin/reports.html | View reports (admin+instructor). | §6 mod13, §13 |
| frontend/css/style.css | Responsive, accessible, low-bandwidth styling. | §13, §18 |
| frontend/js/config.js | `const API_BASE_URL = "https://your-api.onrender.com/api";` | §11.6, §15 |
| frontend/js/api.js | §11.6 fetch helper + loading banner + 401→login. | §11.6 |
| frontend/js/auth.js | Register/login/logout, token storage, current-user cache, role guards, navbar. | §6 mod1/2 |
| frontend/js/courses.js | Stream-based list + filter + enroll (textContent never innerHTML). | §11.7 |
| frontend/js/playground.js | Sandboxed srcdoc editor logic. | §6 mod5, §14 |
| frontend/js/dashboard.js | Chart.js progress charts from /progress/me. | §6 mod8 |
| frontend/js/lesson.js | Lesson viewer logic. | §13 |
| frontend/js/quiz.js | Quiz logic. | §13 |
| frontend/js/forum.js | Forum logic (textContent only). | §6 mod9 |
| frontend/js/certificate.js | Certificate logic + print. | §6 mod12 |
| frontend/js/admin/users.js | Admin users logic. | §6 mod14 |
| frontend/js/admin/courses.js | Admin courses/lessons logic. | §6 mod3/4 |
| frontend/js/admin/reports.js | Admin reports logic. | §6 mod13 |
| frontend/_headers | Netlify strict CSP (self + Chart.js/KaTeX CDNs + API connect-src + frame-src self). | §14 |
