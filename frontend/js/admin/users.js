/**
 * Admin — Manage Users page.
 * Requires admin role; renders a paginated table with role-change controls.
 */

(async () => {
  renderNavbar("admin-users");
  requireRole("admin");

  const tbody    = document.getElementById("usersBody");
  const alertBox = document.getElementById("alertBox");
  const paginEl  = document.getElementById("pagination");
  let   currentPage = 1;
  const limit       = 20;

  const ROLES = ["student", "tutor", "instructor", "admin"];

  async function loadUsers(page = 1) {
    currentPage = page;
    tbody.textContent = "";
    paginEl.textContent = "";

    const q    = document.getElementById("searchQ").value.trim();
    const role = document.getElementById("filterRole").value;
    let   path = `/admin/users?page=${page}&limit=${limit}`;
    if (q)    path += "&q="    + encodeURIComponent(q);
    if (role) path += "&role=" + encodeURIComponent(role);

    const res = await api(path);
    if (!res.success) {
      const div = document.createElement("div");
      div.className   = "alert alert-error";
      div.textContent = res.message || "Failed to load users.";
      alertBox.appendChild(div);
      return;
    }

    const users = res.data.users || [];
    const total = res.data.total || 0;

    if (!users.length) {
      const tr = document.createElement("tr");
      const td = document.createElement("td");
      td.colSpan   = 6;
      td.className = "text-muted text-center";
      td.textContent = "No users found.";
      tr.appendChild(td);
      tbody.appendChild(tr);
      return;
    }

    users.forEach(u => {
      const uid = u._id?.$oid || u._id;
      const tr  = document.createElement("tr");

      function td(text) {
        const el = document.createElement("td");
        el.textContent = text ?? "–";
        return el;
      }

      tr.appendChild(td(u.fullName));
      tr.appendChild(td(u.email));

      // Role cell with dropdown
      const roleTd  = document.createElement("td");
      const roleSel = document.createElement("select");
      roleSel.style.fontSize = ".85rem";
      ROLES.forEach(r => {
        const opt = document.createElement("option");
        opt.value = r;
        opt.textContent = r;
        if (r === u.role) opt.selected = true;
        roleSel.appendChild(opt);
      });
      roleTd.appendChild(roleSel);
      tr.appendChild(roleTd);

      tr.appendChild(td(u.stream || "–"));
      tr.appendChild(td(u.isActive ? "Yes" : "No"));

      // Save button
      const actionTd  = document.createElement("td");
      const saveBtn   = document.createElement("button");
      saveBtn.className   = "btn btn-primary btn-sm";
      saveBtn.textContent = "Save Role";
      saveBtn.addEventListener("click", async () => {
        const newRole = roleSel.value;
        saveBtn.disabled    = true;
        saveBtn.textContent = "Saving…";
        const r = await api(`/admin/users/${uid}/role`, "PUT", { role: newRole });
        if (r.success) {
          saveBtn.textContent = "Saved ✓";
        } else {
          alert(r.message || "Failed to update role.");
          saveBtn.disabled    = false;
          saveBtn.textContent = "Save Role";
        }
      });
      actionTd.appendChild(saveBtn);
      tr.appendChild(actionTd);

      tbody.appendChild(tr);
    });

    // Pagination
    const totalPages = Math.ceil(total / limit);
    for (let p = 1; p <= totalPages; p++) {
      const btn = document.createElement("button");
      btn.textContent = String(p);
      if (p === currentPage) btn.classList.add("active");
      btn.addEventListener("click", () => loadUsers(p));
      paginEl.appendChild(btn);
    }
  }

  document.getElementById("loadBtn").addEventListener("click", () => loadUsers(1));
  await loadUsers(1);
})();
