/**
 * Lesson viewer page.
 * Loads lessons for a course, renders text with KaTeX for math, shows code
 * examples, handles Mark Complete, and navigates between lessons.
 */

(async () => {
  renderNavbar("lesson");

  const params   = new URLSearchParams(location.search);
  const courseId = params.get("course");
  const startId  = params.get("lesson");

  if (!courseId) {
    location.href = "courses.html";
    return;
  }

  const lessonListEl  = document.getElementById("lessonList");
  const lessonContent = document.getElementById("lessonContent");
  const lessonActions = document.getElementById("lessonActions");
  const completeBtn   = document.getElementById("completeBtn");
  const playgroundBtn = document.getElementById("playgroundBtn");
  const quizBtn       = document.getElementById("quizBtn");
  const prevBtn       = document.getElementById("prevBtn");
  const nextBtn       = document.getElementById("nextBtn");
  const progressWrap  = document.getElementById("progressWrap");
  const progressBar   = document.getElementById("progressBar");
  const progressLabel = document.getElementById("progressLabel");

  const user      = getUser();
  let lessons     = [];
  let currentIdx  = 0;
  let completedIds = new Set();

  // ── Load lessons ────────────────────────────────────────────────────────
  const res = await api(`/courses/${courseId}/lessons`);
  if (!res.success || !res.data?.length) {
    lessonContent.textContent = "No lessons available for this course yet.";
    return;
  }
  lessons = res.data;

  // ── Load progress ────────────────────────────────────────────────────────
  if (user) {
    const prog = await api("/progress/me");
    if (prog.success) {
      const enrollment = (prog.data || []).find(e => {
        const cid = e.courseId?.$oid || e.courseId;
        return cid === courseId;
      });
      if (enrollment) {
        (enrollment.completedLessons || []).forEach(id => {
          completedIds.add(id?.$oid || id);
        });
        const pct = enrollment.progressPercent || 0;
        progressWrap.hidden = false;
        progressBar.style.width = pct + "%";
        progressLabel.textContent = pct + "% complete";
      }
    }
  }

  // ── Build sidebar ────────────────────────────────────────────────────────
  function renderSidebar() {
    lessonListEl.textContent = "";
    lessons.forEach((l, i) => {
      const id  = l._id?.$oid || l._id;
      const li  = document.createElement("li");
      const txt = document.createElement("span");
      txt.textContent = l.title;
      li.appendChild(txt);
      if (completedIds.has(id)) li.classList.add("completed");
      if (i === currentIdx)      li.classList.add("active");
      li.setAttribute("tabindex", "0");
      li.addEventListener("click", () => showLesson(i));
      li.addEventListener("keydown", e => { if (e.key === "Enter") showLesson(i); });
      lessonListEl.appendChild(li);
    });
  }

  // ── Render lesson content ─────────────────────────────────────────────────
  function showLesson(idx) {
    currentIdx = idx;
    const lesson = lessons[idx];
    lessonContent.textContent = "";

    // Title
    const h2 = document.createElement("h2");
    h2.textContent = lesson.title;
    lessonContent.appendChild(h2);

    // Content: plain text with line-break support + KaTeX via auto-render
    // We use a <div> with textContent per paragraph so inline HTML is not executed.
    // The KaTeX auto-render script runs after DOM insertion and handles $...$ markers.
    const contentDiv = document.createElement("div");
    contentDiv.style.marginTop = "1rem";
    (lesson.content || "").split("\n").forEach(line => {
      if (line.trim() === "") {
        contentDiv.appendChild(document.createElement("br"));
        return;
      }
      const p = document.createElement("p");
      p.textContent = line;
      contentDiv.appendChild(p);
    });
    lessonContent.appendChild(contentDiv);

    // Re-run KaTeX auto-render on the new content
    if (window.renderMathInElement) {
      renderMathInElement(contentDiv, {
        delimiters: [
          { left: "$$", right: "$$", display: true },
          { left: "$",  right: "$",  display: false },
        ],
      });
    }

    // Code example
    if (lesson.codeExample) {
      const hr = document.createElement("hr");
      lessonContent.appendChild(hr);
      const label = document.createElement("p");
      label.textContent = "Code example:";
      label.style.fontWeight = "600";
      label.style.marginBottom = ".5rem";
      lessonContent.appendChild(label);
      const pre = document.createElement("pre");
      const code = document.createElement("code");
      code.textContent = lesson.codeExample;
      pre.appendChild(code);
      lessonContent.appendChild(pre);
      playgroundBtn.hidden = false;
      playgroundBtn.onclick = () => {
        const encoded = encodeURIComponent(lesson.codeExample);
        location.href = `playground.html?code=${encoded}`;
      };
    } else {
      playgroundBtn.hidden = true;
    }

    // Video
    if (lesson.videoUrl) {
      const vid = document.createElement("p");
      vid.className = "mt-1";
      const link = document.createElement("a");
      link.href   = lesson.videoUrl;
      link.target = "_blank";
      link.rel    = "noopener noreferrer";
      link.textContent = "▶ Watch video";
      vid.appendChild(link);
      lessonContent.appendChild(vid);
    }

    // Actions
    lessonActions.hidden = false;
    prevBtn.disabled = idx === 0;
    nextBtn.disabled = idx === lessons.length - 1;

    const lessonId = lesson._id?.$oid || lesson._id;
    completeBtn.disabled = completedIds.has(lessonId);
    completeBtn.textContent = completedIds.has(lessonId) ? "Completed ✓" : "Mark as Complete";

    renderSidebar();
  }

  // ── Complete lesson ──────────────────────────────────────────────────────
  completeBtn.addEventListener("click", async () => {
    if (!user) { location.href = "login.html"; return; }
    const lesson   = lessons[currentIdx];
    const lessonId = lesson._id?.$oid || lesson._id;

    completeBtn.disabled  = true;
    completeBtn.textContent = "Saving…";
    const res = await api("/progress/complete-lesson", "POST", { lessonId });
    if (res.success) {
      completedIds.add(lessonId);
      completeBtn.textContent = "Completed ✓";
      const pct = res.data?.progressPercent ?? 0;
      progressWrap.hidden = false;
      progressBar.style.width = pct + "%";
      progressLabel.textContent = pct + "% complete";
      renderSidebar();
    } else {
      completeBtn.disabled = false;
      completeBtn.textContent = "Mark as Complete";
      alert(res.message || "Could not save progress.");
    }
  });

  prevBtn.addEventListener("click", () => { if (currentIdx > 0) showLesson(currentIdx - 1); });
  nextBtn.addEventListener("click", () => { if (currentIdx < lessons.length - 1) showLesson(currentIdx + 1); });

  // ── Start ────────────────────────────────────────────────────────────────
  let startIdx = 0;
  if (startId) {
    const found = lessons.findIndex(l => (l._id?.$oid || l._id) === startId);
    if (found >= 0) startIdx = found;
  }
  showLesson(startIdx);
})();
