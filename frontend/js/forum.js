/**
 * Discussion forum page.
 * All user content is rendered with textContent — never innerHTML.
 */

(async () => {
  renderNavbar("forum");
  requireAuth();

  const postsEl       = document.getElementById("forumPosts");
  const postAlertBox  = document.getElementById("postAlertBox");
  const filterCourse  = document.getElementById("filterCourse");
  const postCourseId  = document.getElementById("postCourseId");

  // ── Load courses for dropdowns ────────────────────────────────────────────
  const cRes = await api("/courses");
  const courses = cRes.success ? cRes.data : [];

  function populateSelect(sel, withAll = false) {
    sel.textContent = "";
    if (withAll) {
      const opt = document.createElement("option");
      opt.value = "";
      opt.textContent = "All courses";
      sel.appendChild(opt);
    }
    courses.forEach(c => {
      const opt   = document.createElement("option");
      opt.value   = c._id?.$oid || c._id;
      opt.textContent = c.title;
      sel.appendChild(opt);
    });
  }

  populateSelect(filterCourse, true);
  populateSelect(postCourseId, false);

  // Pre-select from query string
  const params = new URLSearchParams(location.search);
  const qCourse = params.get("courseId");
  if (qCourse) {
    filterCourse.value = qCourse;
    postCourseId.value = qCourse;
  }

  // ── Load posts ────────────────────────────────────────────────────────────
  async function loadPosts() {
    postsEl.textContent = "";
    const courseId = filterCourse.value;
    let path = "/forum";
    if (courseId) path += "?courseId=" + courseId;
    const res = await api(path);
    const posts = res.success ? res.data : [];

    if (!posts.length) {
      const p = document.createElement("p");
      p.className   = "text-muted";
      p.textContent = "No posts yet. Be the first to ask a question!";
      postsEl.appendChild(p);
      return;
    }

    posts.forEach(post => renderPost(post));
  }

  function renderPost(post) {
    const postId = post._id?.$oid || post._id;
    const div    = document.createElement("div");
    div.className = "forum-post";
    div.id        = "post-" + postId;

    const h3 = document.createElement("h3");
    h3.textContent = post.title;
    div.appendChild(h3);

    const meta = document.createElement("p");
    meta.className   = "meta";
    const dateStr    = post.createdAt ? new Date(post.createdAt).toLocaleDateString() : "";
    meta.textContent = dateStr;
    div.appendChild(meta);

    const body = document.createElement("p");
    body.textContent = post.body;
    div.appendChild(body);

    // Replies
    const repliesDiv = document.createElement("div");
    repliesDiv.id    = "replies-" + postId;
    (post.replies || []).forEach(r => repliesDiv.appendChild(renderReply(r)));
    div.appendChild(repliesDiv);

    // Reply form
    const replyForm = document.createElement("div");
    replyForm.style.marginTop = ".75rem";

    const replyInput = document.createElement("textarea");
    replyInput.placeholder = "Write a reply…";
    replyInput.style.width  = "100%";
    replyInput.style.minHeight = "60px";
    replyInput.style.fontSize  = ".9rem";
    replyInput.style.border    = "1px solid var(--border)";
    replyInput.style.borderRadius = "var(--radius)";
    replyInput.style.padding  = ".5rem";
    replyInput.style.resize   = "vertical";
    replyForm.appendChild(replyInput);

    const replyBtn = document.createElement("button");
    replyBtn.className   = "btn btn-outline btn-sm mt-1";
    replyBtn.textContent = "Reply";
    replyBtn.addEventListener("click", async () => {
      const text = replyInput.value.trim();
      if (!text) return;
      replyBtn.disabled    = true;
      replyBtn.textContent = "Posting…";
      const r = await api(`/forum/${postId}/reply`, "POST", { body: text });
      if (r.success) {
        replyInput.value = "";
        document.getElementById("replies-" + postId).appendChild(renderReply(r.data));
      } else {
        alert(r.message || "Failed to post reply.");
      }
      replyBtn.disabled    = false;
      replyBtn.textContent = "Reply";
    });
    replyForm.appendChild(replyBtn);
    div.appendChild(replyForm);

    postsEl.appendChild(div);
  }

  function renderReply(reply) {
    const div = document.createElement("div");
    div.className = "reply";
    const meta = document.createElement("p");
    meta.className   = "meta";
    const dateStr    = reply.createdAt ? new Date(reply.createdAt).toLocaleDateString() : "";
    meta.textContent = dateStr;
    div.appendChild(meta);
    const body = document.createElement("p");
    body.textContent = reply.body;
    div.appendChild(body);
    return div;
  }

  // ── Submit new post ───────────────────────────────────────────────────────
  document.getElementById("submitPost").addEventListener("click", async () => {
    postAlertBox.textContent = "";
    const courseId = postCourseId.value;
    const title    = document.getElementById("postTitle").value.trim();
    const body     = document.getElementById("postBody").value.trim();

    if (!courseId || !title || !body) {
      const div = document.createElement("div");
      div.className   = "alert alert-error";
      div.textContent = "Please fill in all fields.";
      postAlertBox.appendChild(div);
      return;
    }

    const res = await api("/forum", "POST", { courseId, title, body });
    if (res.success) {
      document.getElementById("postTitle").value = "";
      document.getElementById("postBody").value  = "";
      await loadPosts();
    } else {
      const div = document.createElement("div");
      div.className   = "alert alert-error";
      div.textContent = res.message || "Could not post.";
      postAlertBox.appendChild(div);
    }
  });

  document.getElementById("loadPostsBtn").addEventListener("click", loadPosts);
  await loadPosts();
})();
