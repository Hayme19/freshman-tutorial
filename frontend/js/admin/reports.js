/**
 * Admin / Instructor — Reports & Analytics page.
 * Requires admin or instructor role.
 */

(async () => {
  renderNavbar("admin-reports");
  requireRole("admin", "instructor");

  const res = await api("/reports/summary");
  if (!res.success) {
    document.querySelector(".container").textContent = "Could not load reports.";
    return;
  }

  const d = res.data;

  document.getElementById("statEnrollments").textContent   = d.totalEnrollments ?? "–";
  document.getElementById("statCompleted").textContent     = d.completedCourses  ?? "–";
  document.getElementById("statCompletionRate").textContent = (d.completionRate  ?? 0) + "%";
  document.getElementById("statAvgQuiz").textContent        = (d.avgQuizScorePct ?? 0) + "%";

  // ── Stream chart ──────────────────────────────────────────────────────────
  const activeUsers = d.activeUsers || {};
  new Chart(document.getElementById("streamChart"), {
    type: "doughnut",
    data: {
      labels: ["Natural Science", "Social Science"],
      datasets: [{
        data: [activeUsers.natural || 0, activeUsers.social || 0],
        backgroundColor: ["#2563eb", "#10b981"],
        borderWidth: 2,
      }],
    },
    options: {
      responsive: true,
      plugins: {
        legend: { position: "bottom" },
        tooltip: { callbacks: { label: ctx => `${ctx.label}: ${ctx.parsed} students` } },
      },
    },
  });

  // ── Top courses chart ─────────────────────────────────────────────────────
  const top = (d.topEnrolledCourses || []).slice(0, 5);
  new Chart(document.getElementById("topCoursesChart"), {
    type: "bar",
    data: {
      labels: top.map(c => c.courseTitle || "Course"),
      datasets: [{
        label: "Enrollments",
        data: top.map(c => c.count),
        backgroundColor: "#2563eb",
        borderRadius: 6,
      }],
    },
    options: {
      responsive: true,
      indexAxis: "y",
      scales: { x: { beginAtZero: true } },
      plugins: { legend: { display: false } },
    },
  });
})();
