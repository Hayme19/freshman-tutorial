/**
 * Authentication helpers — token storage, current-user cache, role guards, navbar.
 */

// ── Token / user helpers ──────────────────────────────────────────────────

function getToken()   { return localStorage.getItem("token"); }
function getUser()    { try { return JSON.parse(localStorage.getItem("user") || "null"); } catch { return null; } }
function isLoggedIn() { return !!getToken(); }

function saveAuth(token, user) {
  localStorage.setItem("token", token);
  localStorage.setItem("user",  JSON.stringify(user));
}

function logout() {
  localStorage.removeItem("token");
  localStorage.removeItem("user");
  location.href = "index.html";
}

// ── Role guards — call at top of page JS ─────────────────────────────────

/** Redirect to login if the user is not authenticated. */
function requireAuth() {
  if (!isLoggedIn()) {
    location.href = "login.html";
    return null;
  }
  return getUser();
}

/** Redirect to courses.html if the user lacks one of the required roles. */
function requireRole(...roles) {
  const user = requireAuth();
  if (!user) return null;
  if (!roles.includes(user.role)) {
    location.href = "courses.html";
    return null;
  }
  return user;
}

// ── Register ─────────────────────────────────────────────────────────────

async function register(fullName, email, password, stream) {
  const res = await api("/auth/register", "POST", { fullName, email, password, stream });
  if (res.success) {
    saveAuth(res.data.token, res.data.user);
    redirectAfterAuth(res.data.user);
  }
  return res;
}

// ── Login ────────────────────────────────────────────────────────────────

async function login(email, password) {
  const res = await api("/auth/login", "POST", { email, password });
  if (res.success) {
    saveAuth(res.data.token, res.data.user);
    redirectAfterAuth(res.data.user);
  }
  return res;
}

function redirectAfterAuth(user) {
  if (user.role === "admin") {
    location.href = "admin/users.html";
  } else if (user.role === "instructor" || user.role === "tutor") {
    location.href = "admin/courses.html";
  } else {
    location.href = "courses.html";
  }
}

// ── Navbar ────────────────────────────────────────────────────────────────
// Inserts a responsive navbar into #navbar (must exist in the HTML).

function renderNavbar(activePage) {
  const container = document.getElementById("navbar");
  if (!container) return;

  const user     = getUser();
  const role     = user?.role || "guest";
  const loggedIn = isLoggedIn();

  const navItems = [];

  navItems.push({ href: "courses.html",   label: "Courses",    pages: ["courses"] });
  navItems.push({ href: "playground.html",label: "Playground", pages: ["playground"] });

  if (loggedIn) {
    navItems.push({ href: "dashboard.html", label: "Dashboard", pages: ["dashboard"] });
    navItems.push({ href: "forum.html",     label: "Forum",     pages: ["forum"] });
  }

  if (role === "admin" || role === "instructor") {
    navItems.push({ href: "admin/courses.html", label: "Manage Courses", pages: ["admin-courses"] });
  }
  if (role === "admin") {
    navItems.push({ href: "admin/users.html",   label: "Users",   pages: ["admin-users"] });
    navItems.push({ href: "admin/reports.html", label: "Reports", pages: ["admin-reports"] });
  }

  const links = navItems.map(item => {
    const isActive = item.pages.includes(activePage) ? " active" : "";
    const el = document.createElement("li");
    const a  = document.createElement("a");
    a.href      = item.href;
    a.className = isActive;
    a.textContent = item.label;
    el.appendChild(a);
    return el.outerHTML;
  }).join("");

  const authLink = loggedIn
    ? `<li><button class="btn btn-outline btn-sm" id="logoutBtn">Logout</button></li>`
    : `<li><a href="login.html" class="btn ${activePage === 'login' ? 'btn-primary' : 'btn-outline'} btn-sm">Login</a></li>`;

  container.innerHTML = `
    <nav class="navbar">
      <div class="navbar-inner">
        <a href="index.html" class="navbar-brand">A+ Freshman Tutorial</a>
        <button class="navbar-toggle" id="navToggle" aria-label="Toggle navigation">&#9776;</button>
        <ul class="navbar-nav" id="navMenu">
          ${links}
          ${loggedIn && user ? `<li class="text-muted" style="font-size:.85rem">Hi, ${user.fullName?.split(" ")[0] || "Student"}</li>` : ""}
          ${authLink}
        </ul>
      </div>
    </nav>
  `;

  document.getElementById("navToggle")?.addEventListener("click", () => {
    document.getElementById("navMenu")?.classList.toggle("open");
  });

  document.getElementById("logoutBtn")?.addEventListener("click", logout);
}
