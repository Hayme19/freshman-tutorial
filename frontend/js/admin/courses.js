/**
 * Admin / Instructor — Manage Courses & Lessons page.
 * Requires admin or instructor role.
 */

(async () => {
  renderNavbar("admin-courses");
  requireRole("admin", "instructor");

  const alertBox       = document.getElementById("alertBox");
  const courseAlertBox = document.getElementById("courseAlertBox");
  const coursesBody    = document.getElementById("coursesBody");
  const lessonEditor   = document.getElementById("lessonEditor");
  const lessonEditorTitle = document.getElementById("lessonEditorTitle");
  const lessonListEl   = document.getElementById("lessonList");
  const addLessonBtn   = document.getElementById("addLessonBtn");

  let selectedCourseId = null;

  // ── Load & render courses ─────────────────────────────────────────────────
  async function loadCourses() {
    coursesBody.textContent = "";
    const res = await api("/courses");
    const courses = res.success ? res.data : [];

    courses.forEach(c => {
      const courseId = c._id?.$oid || c._id;
      const tr = document.createElement("tr");

      function td(text) {
        const el = document.createElement("td");
        el.textContent = text ?? "–";
        return el;
      }

      tr.appendChild(td(c.code));
      tr.appendChild(td(c.title));
      tr.appendChild(td(c.group));
      tr.appendChild(td(c.isPublished ? "✓" : "✗"));

      const actionTd = document.createElement("td");
      actionTd.style.display = "flex";
      actionTd.style.gap = ".4rem";
      actionTd.style.flexWrap = "wrap";

      const lessonsBtn = document.createElement("button");
      lessonsBtn.className   = "btn btn-outline btn-sm";
      lessonsBtn.textContent = "Lessons";
      lessonsBtn.addEventListener("click", () => openLessonEditor(courseId, c.title));
      actionTd.appendChild(lessonsBtn);

      const toggleBtn = document.createElement("button");
      toggleBtn.className   = `btn ${c.isPublished ? "btn-danger" : "btn-secondary"} btn-sm`;
      toggleBtn.textContent = c.isPublished ? "Unpublish" : "Publish";
      toggleBtn.addEventListener("click", async () => {
        const r = await api(`/courses/${courseId}`, "PUT", { isPublished: !c.isPublished });
        if (r.success) loadCourses();
        else alert(r.message);
      });
      actionTd.appendChild(toggleBtn);

      const delBtn = document.createElement("button");
      delBtn.className   = "btn btn-danger btn-sm";
      delBtn.textContent = "Delete";
      delBtn.addEventListener("click", async () => {
        if (!confirm(`Delete course "${c.title}"?`)) return;
        const r = await api(`/courses/${courseId}`, "DELETE");
        if (r.success) loadCourses();
        else alert(r.message);
      });
      actionTd.appendChild(delBtn);

      tr.appendChild(actionTd);
      coursesBody.appendChild(tr);
    });
  }

  // ── Create course ─────────────────────────────────────────────────────────
  document.getElementById("createCourseBtn").addEventListener("click", async () => {
    courseAlertBox.textContent = "";
    const code  = document.getElementById("newCode").value.trim();
    const title = document.getElementById("newTitle").value.trim();
    const group = document.getElementById("newGroup").value;
    const desc  = document.getElementById("newDesc").value.trim();

    if (!code || !title) {
      const div = document.createElement("div");
      div.className   = "alert alert-error";
      div.textContent = "Code and title are required.";
      courseAlertBox.appendChild(div);
      return;
    }

    const streams = ["common", "digital"].includes(group)
      ? ["natural", "social"]
      : [group === "natural" ? "natural" : "social"];

    const res = await api("/courses", "POST", { code, title, group, description: desc, streams, isPublished: false });
    if (res.success) {
      document.getElementById("newCode").value  = "";
      document.getElementById("newTitle").value = "";
      document.getElementById("newDesc").value  = "";
      await loadCourses();
      const div = document.createElement("div");
      div.className   = "alert alert-success";
      div.textContent = "Course created.";
      courseAlertBox.appendChild(div);
    } else {
      const div = document.createElement("div");
      div.className   = "alert alert-error";
      div.textContent = res.message || "Failed to create course.";
      courseAlertBox.appendChild(div);
    }
  });

  // ── Lesson editor ─────────────────────────────────────────────────────────
  async function openLessonEditor(courseId, courseTitle) {
    selectedCourseId = courseId;
    lessonEditorTitle.textContent = `Lessons for: ${courseTitle}`;
    lessonEditor.hidden = false;
    lessonEditor.scrollIntoView({ behavior: "smooth" });
    await loadLessons();
  }

  async function loadLessons() {
    lessonListEl.textContent = "";
    const res = await api(`/courses/${selectedCourseId}/lessons`);
    const lessons = res.success ? res.data : [];

    if (!lessons.length) {
      const p = document.createElement("p");
      p.className   = "text-muted";
      p.textContent = "No lessons yet.";
      lessonListEl.appendChild(p);
      return;
    }

    lessons.forEach(l => {
      const lessonId = l._id?.$oid || l._id;
      const row = document.createElement("div");
      row.className = "flex justify-between items-center";
      row.style.borderBottom = "1px solid var(--border)";
      row.style.padding      = ".5rem 0";

      const span = document.createElement("span");
      span.textContent = `[Unit ${l.unit}, #${l.order}] ${l.title}${l.isFree ? " (free)" : ""}`;
      row.appendChild(span);

      const del = document.createElement("button");
      del.className   = "btn btn-danger btn-sm";
      del.textContent = "Delete";
      del.addEventListener("click", async () => {
        if (!confirm(`Delete lesson "${l.title}"?`)) return;
        const r = await api(`/lessons/${lessonId}`, "DELETE");
        if (r.success) await loadLessons();
        else alert(r.message);
      });
      row.appendChild(del);
      lessonListEl.appendChild(row);
    });
  }

  addLessonBtn.addEventListener("click", async () => {
    if (!selectedCourseId) return;
    const title   = document.getElementById("lessonTitle").value.trim();
    const content = document.getElementById("lessonContent").value;
    const unit    = parseInt(document.getElementById("lessonUnit").value, 10) || 1;
    const order   = parseInt(document.getElementById("lessonOrder").value, 10) || 1;
    const isFree  = document.getElementById("lessonFree").checked;
    const code    = document.getElementById("lessonCode").value;
    const video   = document.getElementById("lessonVideo").value.trim();

    if (!title) { alert("Title is required."); return; }

    const res = await api("/lessons", "POST", {
      courseId: selectedCourseId, title, content, unit, order, isFree,
      codeExample: code, videoUrl: video,
    });

    if (res.success) {
      document.getElementById("lessonTitle").value   = "";
      document.getElementById("lessonContent").value = "";
      document.getElementById("lessonCode").value    = "";
      document.getElementById("lessonVideo").value   = "";
      await loadLessons();
    } else {
      alert(res.message || "Failed to add lesson.");
    }
  });

  await loadCourses();
})();
