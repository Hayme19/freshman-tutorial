/**
 * Student progress dashboard — Chart.js charts from /progress/me
 */

(async () => {
  renderNavbar("dashboard");
  requireAuth();

  const enrollmentList = document.getElementById("enrollmentList");

  const res = await api("/progress/me");
  if (!res.success) {
    enrollmentList.textContent = "Could not load progress data.";
    return;
  }

  const data = res.data || [];

  // ── Stats ────────────────────────────────────────────────────────────────
  const completed = data.filter(e => e.completedAt).length;
  const avgProg   = data.length
    ? Math.round(data.reduce((s, e) => s + (e.progressPercent || 0), 0) / data.length)
    : 0;

  document.getElementById("statEnrolled").textContent  = data.length;
  document.getElementById("statCompleted").textContent = completed;
  document.getElementById("statAvgProgress").textContent = avgProg + "%";

  // ── Chart ────────────────────────────────────────────────────────────────
  const labels  = data.map(e => e.courseTitle || "Course");
  const values  = data.map(e => e.progressPercent || 0);
  const colors  = values.map(v => v >= 100 ? "#10b981" : v >= 50 ? "#2563eb" : "#f59e0b");

  new Chart(document.getElementById("progressChart"), {
    type: "bar",
    data: {
      labels,
      datasets: [{
        label: "Progress (%)",
        data: values,
        backgroundColor: colors,
        borderRadius: 6,
      }],
    },
    options: {
      responsive: true,
      scales: {
        y: { beginAtZero: true, max: 100, ticks: { callback: v => v + "%" } },
      },
      plugins: {
        legend: { display: false },
        tooltip: { callbacks: { label: ctx => ctx.parsed.y + "%" } },
      },
    },
  });

  // ── Enrollment list ───────────────────────────────────────────────────────
  if (data.length === 0) {
    const p = document.createElement("p");
    p.className   = "text-muted";
    p.textContent = "You are not enrolled in any courses yet.";
    enrollmentList.appendChild(p);
    const a = document.createElement("a");
    a.href      = "courses.html";
    a.className = "btn btn-primary mt-1";
    a.textContent = "Browse Courses";
    enrollmentList.appendChild(a);
    return;
  }

  data.forEach(e => {
    const courseId = e.courseId?.$oid || e.courseId;
    const card     = document.createElement("div");
    card.className = "card mb-1";
    card.style.marginBottom = "1rem";

    // Row: title + progress
    const row = document.createElement("div");
    row.className = "flex justify-between items-center";

    const title = document.createElement("strong");
    title.textContent = e.courseTitle || "Course";
    row.appendChild(title);

    const badge = document.createElement("span");
    badge.className   = `badge ${e.completedAt ? "badge-success" : "badge-warning"}`;
    badge.textContent = e.completedAt ? "Completed" : `${e.progressPercent || 0}%`;
    row.appendChild(badge);

    card.appendChild(row);

    // Progress bar
    const wrap = document.createElement("div");
    wrap.className = "progress-bar-wrap mt-1";
    const bar  = document.createElement("div");
    bar.className = "progress-bar";
    bar.style.width = (e.progressPercent || 0) + "%";
    wrap.appendChild(bar);
    card.appendChild(wrap);

    // Links
    const links = document.createElement("div");
    links.className = "flex gap-1 mt-1";
    links.style.flexWrap = "wrap";

    const lessonLink = document.createElement("a");
    lessonLink.href       = `lesson.html?course=${courseId}`;
    lessonLink.className  = "btn btn-primary btn-sm";
    lessonLink.textContent = "Continue";
    links.appendChild(lessonLink);

    if (e.completedAt) {
      const certBtn = document.createElement("a");
      certBtn.href       = "certificate.html?course=" + courseId;
      certBtn.className  = "btn btn-secondary btn-sm";
      certBtn.textContent = "🎓 Certificate";
      links.appendChild(certBtn);
    }

    card.appendChild(links);
    enrollmentList.appendChild(card);
  });
})();
