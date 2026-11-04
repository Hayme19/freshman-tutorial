/**
 * Course listing page — redesigned with rich cards, stream tabs, skeleton loader.
 * Uses textContent exclusively; never innerHTML with API content (DESIGN §14).
 */

// ── Course metadata (icon + short description) for known course codes ─────
const COURSE_META = {
  ENGL101: { icon: "📝", desc: "Develop reading, writing, grammar and communication skills essential for academic success." },
  ANTH101: { icon: "🌍", desc: "Explore culture, society, kinship systems and social institutions through case studies." },
  EMTE101: { icon: "🤖", desc: "Discover AI, IoT, data science, cloud computing and cybersecurity fundamentals." },
  GLTR101: { icon: "🌐", desc: "Understand globalization, international relations and pressing global issues through news-style lessons." },
  PSYC101: { icon: "🧠", desc: "Study learning, memory, motivation and personality — the science of the human mind." },
  LOCT101: { icon: "💡", desc: "Master arguments, identify logical fallacies and sharpen your critical reasoning skills." },
  SPRT101: { icon: "🏃", desc: "Learn fitness principles, nutrition, exercise techniques and track your personal activity log." },
  PHYS101: { icon: "⚛️",  desc: "Cover mechanics, heat, waves and electricity with formula sheets and worked examples." },
  MATH101: { icon: "📐", desc: "Tackle functions, limits, derivatives and integrals with step-by-step KaTeX-rendered solutions." },
  GEOG101: { icon: "🗺️", desc: "Explore Earth systems, climate, population dynamics and geographic patterns." },
  MCEN101: { icon: "⚖️", desc: "Examine ethics, rights and duties, democratic principles and civic responsibility." },
  ECON101: { icon: "📊", desc: "Understand demand and supply, market equilibrium, national income and economic policy." },
  ENTR101: { icon: "💼", desc: "Generate ideas, build a business plan, learn marketing and finance basics." },
  HIST101: { icon: "🏛️", desc: "Journey through Ethiopian and Horn of Africa history — major periods and events." },
  INCL101: { icon: "🤝", desc: "Explore disability, gender, diversity and inclusion through real-world scenarios." },
  WEBD101: { icon: "💻", desc: "Learn HTML, CSS, JavaScript and PHP with a live code playground. Build a mini project." },
};

const DEFAULT_META = { icon: "📚", desc: "A structured course with lessons, exercises and quizzes." };

// ── Group display labels ───────────────────────────────────────────────────
const GROUP_LABEL = {
  common:  "Common",
  natural: "Natural",
  social:  "Social",
  digital: "Digital",
};

const GROUP_BADGE_CLASS = {
  common:  "badge-common",
  natural: "badge-natural",
  social:  "badge-social",
  digital: "badge-digital",
};

// ─────────────────────────────────────────────────────────────────────────────

(async () => {
  renderNavbar("courses");

  const user      = getUser();
  const coursesEl = document.getElementById("courses");
  const countEl   = document.getElementById("courseCount");
  const labelEl   = document.getElementById("sectionLabel");

  // ── Stream tabs ────────────────────────────────────────────────────────────
  let activeStream = user?.stream || localStorage.getItem("stream") || "";
  const tabs = document.querySelectorAll(".stream-tab");

  function setActiveTab(stream) {
    activeStream = stream;
    tabs.forEach(t => t.classList.toggle("active", t.dataset.stream === stream));
    updateLabel();
  }

  // Set initial tab based on saved stream
  setActiveTab(activeStream);

  tabs.forEach(tab => {
    tab.addEventListener("click", () => {
      setActiveTab(tab.dataset.stream);
      loadCourses();
    });
  });

  function updateLabel() {
    const streamName = activeStream === "natural" ? "Natural Science" :
                       activeStream === "social"  ? "Social Science"  : "All";
    const groupVal   = document.getElementById("filterGroup").value;
    const groupName  = groupVal ? GROUP_LABEL[groupVal] : "";
    labelEl.textContent = [streamName, groupName, "Courses"].filter(Boolean).join(" · ");
  }

  // ── Search with debounce ───────────────────────────────────────────────────
  let debounceTimer;
  document.getElementById("searchQ").addEventListener("input", () => {
    clearTimeout(debounceTimer);
    debounceTimer = setTimeout(loadCourses, 350);
  });

  document.getElementById("filterGroup").addEventListener("change", () => {
    updateLabel();
    loadCourses();
  });

  // ── Load & render courses ──────────────────────────────────────────────────
  async function loadCourses() {
    // Show skeleton
    coursesEl.textContent = "";
    for (let i = 0; i < 6; i++) {
      const sk = document.createElement("div");
      sk.className = "skeleton-card";
      sk.innerHTML = '<div class="skeleton-icon"></div><div class="skeleton-line wide"></div><div class="skeleton-line short"></div><div class="skeleton-line wide"></div>';
      coursesEl.appendChild(sk);
    }
    countEl.textContent = "…";

    const q      = document.getElementById("searchQ").value.trim();
    const group  = document.getElementById("filterGroup").value;
    let   path   = "/courses?";
    if (activeStream) path += `stream=${encodeURIComponent(activeStream)}&`;
    if (group)         path += `group=${encodeURIComponent(group)}&`;
    if (q)             path += `q=${encodeURIComponent(q)}&`;

    let courses = [];
    try {
      const res = await api(path);
      courses = res.success ? (res.data || []) : [];
    } catch {
      courses = [];
    }

    coursesEl.textContent = "";
    countEl.textContent   = courses.length;

    if (courses.length === 0) {
      const div = document.createElement("div");
      div.className = "empty-state";
      const icon = document.createElement("div");
      icon.className   = "icon";
      icon.textContent = "🔍";
      const h3 = document.createElement("h3");
      h3.textContent = "No courses found";
      const p = document.createElement("p");
      p.className   = "text-muted";
      p.textContent = q ? `No results for "${q}". Try a different keyword.` : "Try changing the stream or group filter.";
      div.appendChild(icon);
      div.appendChild(h3);
      div.appendChild(p);
      coursesEl.appendChild(div);
      return;
    }

    // Render cards using only textContent / createElement
    courses.forEach(course => {
      const id   = course._id?.$oid || course._id;
      const code = course.code || "";
      const meta = COURSE_META[code] || DEFAULT_META;
      const grp  = course.group || "common";

      const card = document.createElement("div");
      card.className    = "ccard";
      card.dataset.group = grp;
      card.setAttribute("tabindex", "0");
      card.setAttribute("role", "article");

      // ── Top section ────────────────────────────────────────────────
      const top  = document.createElement("div");
      top.className = "ccard-top";

      const iconEl = document.createElement("div");
      iconEl.className   = "ccard-icon";
      iconEl.textContent = meta.icon;

      const info = document.createElement("div");
      info.className = "ccard-info";

      const h3 = document.createElement("h3");
      h3.textContent = course.title;

      const codeEl = document.createElement("div");
      codeEl.className   = "code";
      codeEl.textContent = code;

      info.appendChild(h3);
      info.appendChild(codeEl);
      top.appendChild(iconEl);
      top.appendChild(info);
      card.appendChild(top);

      // ── Body ────────────────────────────────────────────────────────
      const body = document.createElement("div");
      body.className   = "ccard-body";
      body.textContent = meta.desc;
      card.appendChild(body);

      // ── Footer ──────────────────────────────────────────────────────
      const footer = document.createElement("div");
      footer.className = "ccard-footer";

      const badges = document.createElement("div");
      badges.className = "ccard-badges";

      const grpBadge = document.createElement("span");
      grpBadge.className   = `badge ${GROUP_BADGE_CLASS[grp] || "badge-common"}`;
      grpBadge.textContent = GROUP_LABEL[grp] || grp;
      badges.appendChild(grpBadge);

      if (course.level) {
        const lvlBadge = document.createElement("span");
        lvlBadge.className   = "badge";
        lvlBadge.style.background = "#f3f4f6";
        lvlBadge.style.color      = "#374151";
        lvlBadge.textContent = course.level.charAt(0).toUpperCase() + course.level.slice(1);
        badges.appendChild(lvlBadge);
      }

      footer.appendChild(badges);

      // Action button area
      const actions = document.createElement("div");
      actions.style.display = "flex";
      actions.style.gap     = ".4rem";

      // Open lessons button
      const openBtn = document.createElement("a");
      openBtn.className = "btn btn-outline btn-sm enroll-btn";
      openBtn.href      = `lesson.html?course=${id}`;
      openBtn.textContent = "Open";
      actions.appendChild(openBtn);

      // Enroll button for students
      if (user && user.role === "student") {
        const enrollBtn = document.createElement("button");
        enrollBtn.className   = "btn btn-primary btn-sm enroll-btn";
        enrollBtn.textContent = "Enroll";
        enrollBtn.addEventListener("click", async ev => {
          ev.preventDefault();
          enrollBtn.disabled    = true;
          enrollBtn.textContent = "…";
          const r = await api("/enrollments", "POST", { courseId: id });
          if (r.success) {
            enrollBtn.textContent = "✓ Enrolled";
            enrollBtn.className   = "btn btn-secondary btn-sm enroll-btn";
          } else {
            enrollBtn.textContent = r.message === "Already enrolled" ? "✓ Enrolled" : "Retry";
            enrollBtn.disabled    = r.message === "Already enrolled";
            if (r.message === "Already enrolled") enrollBtn.className = "btn btn-secondary btn-sm enroll-btn";
          }
        });
        actions.appendChild(enrollBtn);
      }

      // Guest: login prompt
      if (!user) {
        const loginBtn = document.createElement("a");
        loginBtn.className   = "btn btn-primary btn-sm enroll-btn";
        loginBtn.href        = "login.html";
        loginBtn.textContent = "Login to enroll";
        actions.appendChild(loginBtn);
      }

      footer.appendChild(actions);
      card.appendChild(footer);

      // Make the whole card keyboard-navigable to Open
      card.addEventListener("keydown", e => {
        if (e.key === "Enter") location.href = `lesson.html?course=${id}`;
      });

      coursesEl.appendChild(card);
    });
  }

  updateLabel();
  await loadCourses();
})();
