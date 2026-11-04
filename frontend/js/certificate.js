/**
 * Certificate page — fetches a certificate by id or by courseId, renders it
 * as a printable document.
 */

(async () => {
  renderNavbar("certificate");
  requireAuth();

  const certArea = document.getElementById("certArea");
  const params   = new URLSearchParams(location.search);
  const certId   = params.get("id");
  const courseId = params.get("course");

  let cert = null;

  if (certId) {
    const res = await api(`/certificates/${certId}`);
    if (res.success) cert = res.data;
    else certArea.textContent = res.message || "Certificate not found.";
  } else if (courseId) {
    // Find the right certificate from the list
    const res = await api("/certificates");
    if (res.success) {
      cert = (res.data || []).find(c => {
        const cid = c.courseId?.$oid || c.courseId;
        return cid === courseId;
      }) || null;
    }
    if (!cert) {
      certArea.textContent = "No certificate found for this course. Complete the course and pass the quiz to earn one.";
    }
  } else {
    certArea.textContent = "No certificate id provided.";
  }

  if (!cert) return;

  // ── Render certificate ────────────────────────────────────────────────────
  const issuedDate = cert.issuedAt
    ? new Date(cert.issuedAt).toLocaleDateString("en-US", { year: "numeric", month: "long", day: "numeric" })
    : "";

  const div = document.createElement("div");
  div.className = "certificate";

  const h1 = document.createElement("h1");
  h1.textContent = "Certificate of Completion";
  div.appendChild(h1);

  const sub1 = document.createElement("p");
  sub1.textContent = "This is to certify that";
  div.appendChild(sub1);

  const name = document.createElement("div");
  name.className   = "student-name";
  name.textContent = cert.studentName || "Student";
  div.appendChild(name);

  const sub2 = document.createElement("p");
  sub2.textContent = "has successfully completed the course";
  div.appendChild(sub2);

  const courseTitle = document.createElement("div");
  courseTitle.className   = "course-title";
  courseTitle.textContent = cert.courseTitle || "";
  div.appendChild(courseTitle);

  const sub3 = document.createElement("p");
  sub3.textContent = "on the A+ Freshman Tutorial Platform";
  div.appendChild(sub3);

  if (issuedDate) {
    const dateEl = document.createElement("p");
    dateEl.className   = "date";
    dateEl.textContent = "Issued: " + issuedDate;
    div.appendChild(dateEl);
  }

  certArea.appendChild(div);

  document.getElementById("printBtn").addEventListener("click", () => window.print());
})();
