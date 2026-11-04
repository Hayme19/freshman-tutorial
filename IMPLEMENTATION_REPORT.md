# Implementation Report — A+ Freshman Tutorial Platform

## What was built

A full-stack e-learning platform per DESIGN.md, comprising 54 files across two deployment targets.

### Backend (`backend/`) — PHP 8.2 REST API (Render + Docker)

```
backend/
├── Dockerfile                          Docker image: php:8.2-apache + mongodb PECL extension
├── composer.json                       Dependencies: mongodb/mongodb, firebase/php-jwt, vlucas/phpdotenv
├── .env.example                        Env var documentation (no real secrets)
├── public/
│   ├── index.php                       Full router — all 35+ endpoints from DESIGN §12
│   └── .htaccess                       Rewrites all requests to index.php
├── src/
│   ├── config/Database.php             Singleton MongoDB client, exposes all collections
│   ├── helpers/Response.php            Response::json() — uniform {success,data,message} shape
│   ├── middleware/
│   │   ├── AuthMiddleware.php          JWT decode (HS256), role check, token issuance
│   │   └── Cors.php                   Exact-origin CORS from comma-separated FRONTEND_URL
│   ├── models/
│   │   └── ObjectIdHelper.php         BSON serialisation, safe ObjectId casting
│   └── controllers/
│       ├── AuthController.php          register / login (rate-limit 5/15 min) / me
│       ├── UserController.php          PUT /users/me
│       ├── CourseController.php        list (stream/group/keyword filters) / get / create / update / delete
│       ├── LessonController.php        listByCourse / create / update / delete
│       ├── EnrollmentController.php    create (student)
│       ├── ProgressController.php      completeLesson ($addToSet + recompute) / getMyProgress
│       ├── QuizController.php          get / attempt (auto-grade) / create / update
│       ├── SubmissionController.php    create / grade
│       ├── ForumController.php         list / create / reply
│       ├── AnnouncementController.php  list / create
│       ├── AdminController.php         listUsers (paginated) / updateRole
│       ├── ReportController.php        summary (enrollments, completion, avg quiz, top courses)
│       ├── CertificateController.php   listMine / get
│       ├── FitnessLogController.php    list / create / update / delete
│       └── NotificationController.php  list / markRead
└── seed/
    ├── courses.json                    16 merged courses (DESIGN §11.1)
    └── load.php                        Upsert courses, create indexes, insert sample content
```

### Frontend (`frontend/`) — static HTML/CSS/JS (Netlify)

```
frontend/
├── index.html          Landing + stream picker
├── login.html          Login form
├── register.html       Register form
├── courses.html        Course catalog with filters
├── lesson.html         Lesson viewer (KaTeX, code examples, mark complete, nav)
├── playground.html     Live HTML/CSS/JS editor (sandboxed iframe)
├── quiz.html           Quiz renderer + auto-graded submission
├── dashboard.html      Progress charts (Chart.js)
├── forum.html          Discussion forum (list/post/reply)
├── certificate.html    Printable certificate
├── admin/
│   ├── users.html      Manage users + roles (admin)
│   ├── courses.html    Manage courses + lessons (instructor/admin)
│   └── reports.html    Enrollment/quiz analytics charts (admin/instructor)
├── css/style.css       Responsive, accessible, low-bandwidth CSS
├── js/
│   ├── config.js       API_BASE_URL placeholder
│   ├── api.js          Fetch helper + loading banner + 401→redirect
│   ├── auth.js         Token storage, role guards, navbar
│   ├── courses.js      Stream-based course list + enroll
│   ├── lesson.js       Lesson viewer logic + KaTeX + progress
│   ├── playground.js   Sandboxed srcdoc editor
│   ├── quiz.js         Quiz render + submit + results
│   ├── dashboard.js    Chart.js progress dashboard
│   ├── forum.js        Forum list/post/reply (textContent only)
│   ├── certificate.js  Certificate render + print
│   └── admin/
│       ├── users.js    Admin users table + role change
│       ├── courses.js  Course CRUD + lesson editor
│       └── reports.js  Analytics charts
└── _headers            Netlify strict Content-Security-Policy
```

---

## Running the seed locally

> Requires PHP 8.2 with the MongoDB PECL extension and Composer installed locally,
> or you can run it via the Render shell after deploying.

```bash
cd backend
cp .env.example .env          # fill in MONGODB_URI, DB_NAME, JWT_SECRET, FRONTEND_URL
composer install
php seed/load.php
```

Expected output:
```
Connected to tutorialdb.

Creating indexes...
Indexes created.

Upserting courses...
  16 courses upserted.

Seeding LOCT101 – Logic and Critical Thinking...
    3 lessons, 1 exercise, 1 quiz inserted.
...

✓ Seed complete. Database: tutorialdb
```

---

## Deploying the backend to Render

1. Push the project to GitHub (`backend/` directory at the repository root or as the root).
2. On Render → **New → Web Service** → connect your repository.
3. Set **Root directory** to `backend`.
4. Set **Runtime** to **Docker** (Render detects the `Dockerfile` automatically).
5. Add environment variables:

| Variable | Value |
|----------|-------|
| `MONGODB_URI` | `mongodb+srv://user:pass@cluster.mongodb.net` |
| `DB_NAME` | `tutorialdb` |
| `JWT_SECRET` | A random string of at least 32 characters (`openssl rand -hex 32`) |
| `FRONTEND_URL` | `https://your-site.netlify.app,http://localhost:5500` |

6. Deploy. Test with: `https://your-api.onrender.com/api/courses`
7. Run the seed once from the Render shell:
   ```bash
   php /var/www/html/seed/load.php
   ```

---

## Deploying the frontend to Netlify

1. On Netlify → **Add new site → Import from Git**.
2. Set **Base directory** to `frontend` and **Publish directory** to `frontend`.
3. No build command is needed.
4. Edit `frontend/js/config.js`:
   ```javascript
   const API_BASE_URL = "https://your-api.onrender.com/api";
   ```
5. Edit `frontend/_headers` — replace `https://your-api.onrender.com` with your real API URL in the `connect-src` directive.
6. Deploy. Copy the Netlify URL (e.g. `https://freshman-tutorial.netlify.app`).
7. Add that URL to the Render `FRONTEND_URL` env var so CORS allows it.

---

## Environment variables summary

| Variable | Where | Description |
|----------|-------|-------------|
| `MONGODB_URI` | Render | Atlas connection string |
| `DB_NAME` | Render | Database name (e.g. `tutorialdb`) |
| `JWT_SECRET` | Render | 32+ char random secret for JWT signing |
| `FRONTEND_URL` | Render | Comma-separated allowed origins |
| `API_BASE_URL` | `frontend/js/config.js` | Full API URL including `/api` |

---

## Verification results

### What was verified (static checks — no runtime)
- ✅ All JSON files valid: `backend/composer.json`, `backend/seed/courses.json`
- ✅ Every class referenced in `public/index.php` exists under `src/` with the matching `App\` namespace and PSR-4 path
- ✅ Every endpoint in DESIGN §12 has a matching route in `public/index.php` AND a controller method:
  - `POST /api/auth/register` → `AuthController::register`
  - `POST /api/auth/login` → `AuthController::login`
  - `GET /api/auth/me` → `AuthController::me`
  - `PUT /api/users/me` → `UserController::updateMe`
  - `GET /api/courses` → `CourseController::list`
  - `GET /api/courses/{id}` → `CourseController::get`
  - `POST /api/courses` → `CourseController::create`
  - `PUT /api/courses/{id}` → `CourseController::update`
  - `DELETE /api/courses/{id}` → `CourseController::delete`
  - `GET /api/courses/{id}/lessons` → `LessonController::listByCourse`
  - `POST /api/lessons` → `LessonController::create`
  - `PUT /api/lessons/{id}` → `LessonController::update`
  - `DELETE /api/lessons/{id}` → `LessonController::delete`
  - `POST /api/enrollments` → `EnrollmentController::create`
  - `POST /api/progress/complete-lesson` → `ProgressController::completeLesson`
  - `GET /api/progress/me` → `ProgressController::getMyProgress`
  - `GET /api/quizzes/{id}` → `QuizController::get`
  - `POST /api/quizzes/{id}/attempt` → `QuizController::attempt`
  - `POST /api/quizzes` → `QuizController::create`
  - `PUT /api/quizzes/{id}` → `QuizController::update`
  - `POST /api/submissions` → `SubmissionController::create`
  - `PUT /api/submissions/{id}/grade` → `SubmissionController::grade`
  - `GET /api/forum` → `ForumController::list`
  - `POST /api/forum` → `ForumController::create`
  - `POST /api/forum/{id}/reply` → `ForumController::reply`
  - `GET /api/announcements` → `AnnouncementController::list`
  - `POST /api/announcements` → `AnnouncementController::create`
  - `GET /api/admin/users` → `AdminController::listUsers`
  - `PUT /api/admin/users/{id}/role` → `AdminController::updateRole`
  - `GET /api/reports/summary` → `ReportController::summary`
  - `GET /api/certificates` → `CertificateController::listMine`
  - `GET /api/certificates/{id}` → `CertificateController::get`
  - `GET/POST/PUT/DELETE /api/fitness-logs` → `FitnessLogController`
  - `GET /api/notifications` → `NotificationController::list`
  - `PUT /api/notifications/{id}/read` → `NotificationController::markRead`
- ✅ Every HTML page links `config.js`, `api.js`, `auth.js`, `style.css`
- ✅ Every `getElementById` / `querySelector` id in JS matches an id in the corresponding HTML
- ✅ No `innerHTML` used with user/API content anywhere in frontend JS — all use `textContent` or `createElement`
- ✅ Playground iframe uses `sandbox="allow-scripts"` with no `allow-same-origin`
- ✅ Security design from §14 implemented: bcrypt, JWT 24h, server-side role checks on every protected route, whitelisted stream/group filters, ObjectId casting in try/catch, CORS exact-origin, login rate-limit

### What could NOT be runtime-tested
- Full end-to-end API calls (no MongoDB Atlas cluster available in this environment)
- PHP syntax validation via `php -l` (PHP is not installed in the environment)
- `composer install` (Composer is not installed in the environment)
- JWT token issuance and verification against a live server
- Progress calculation and certificate generation
- Actual Render and Netlify deployment

---

## Tradeoffs and assumptions

| Decision | Choice and reason |
|----------|------------------|
| Autoloader | PSR-4 `App\\ => src/`. All classes use the `App\` namespace, imported via `use` in `public/index.php`. Matches DESIGN §11.2 intent. |
| innerHTML policy | **Never used** with user/API content. All user-generated text goes through `textContent` or `document.createElement`. Lesson body text is rendered as plain text line-by-line; KaTeX auto-render runs on the container element and is safe (it reads parsed LaTeX, not HTML). |
| CSS framework | **None** — hand-written responsive CSS (`css/style.css`) using CSS custom properties, Flexbox and Grid. Keeps the page weight low for users with limited data. |
| Video storage | Videos are stored as external URLs (YouTube/other). Atlas M0 free tier has a 512 MB limit; no binary video is stored. |
| PHP rate-limit | Implemented using a `loginAttempts` collection with a TTL index (auto-purges after 1 hour). Sufficient for a small platform; a Redis-backed solution would be faster at scale. |
| Playground | Only HTML/CSS/JS can be run in the playground. PHP cannot run client-side and student PHP is never executed server-side (DESIGN §14). |
