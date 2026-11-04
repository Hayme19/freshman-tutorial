/**
 * Quiz page — fetches a quiz, renders questions, submits answers, shows results.
 */

(async () => {
  renderNavbar("quiz");
  requireAuth();

  const params  = new URLSearchParams(location.search);
  const quizId  = params.get("quiz");
  const quizArea = document.getElementById("quizArea");

  if (!quizId) {
    quizArea.textContent = "No quiz specified.";
    return;
  }

  const res = await api(`/quizzes/${quizId}`);
  if (!res.success) {
    quizArea.textContent = res.message || "Quiz not found.";
    return;
  }

  const quiz      = res.data;
  const questions = quiz.questions || [];

  // ── Render quiz ──────────────────────────────────────────────────────────
  quizArea.textContent = "";

  const h1 = document.createElement("h1");
  h1.style.fontSize = "1.4rem";
  h1.textContent    = quiz.title || "Quiz";
  quizArea.appendChild(h1);

  const form = document.createElement("form");
  form.id    = "quizForm";

  questions.forEach((q, qi) => {
    const block = document.createElement("div");
    block.className = "question-block";

    const qp = document.createElement("p");
    qp.textContent = `${qi + 1}. ${q.text}`;
    block.appendChild(qp);

    (q.options || []).forEach((opt, oi) => {
      const label = document.createElement("label");
      label.className = "option-label";

      const radio = document.createElement("input");
      radio.type  = "radio";
      radio.name  = `q${qi}`;
      radio.value = String(oi);

      const txt = document.createElement("span");
      txt.textContent = opt;

      label.appendChild(radio);
      label.appendChild(txt);
      block.appendChild(label);
    });

    form.appendChild(block);
  });

  const submitBtn = document.createElement("button");
  submitBtn.type      = "submit";
  submitBtn.className = "btn btn-primary btn-lg";
  submitBtn.textContent = "Submit Quiz";
  form.appendChild(submitBtn);
  quizArea.appendChild(form);

  // Re-run KaTeX after DOM insert
  if (window.renderMathInElement) {
    renderMathInElement(quizArea, {
      delimiters: [
        { left: "$$", right: "$$", display: true },
        { left: "$",  right: "$",  display: false },
      ],
    });
  }

  // ── Submit ────────────────────────────────────────────────────────────────
  form.addEventListener("submit", async e => {
    e.preventDefault();
    const answers = questions.map((_, qi) => {
      const selected = form.querySelector(`input[name="q${qi}"]:checked`);
      return selected ? parseInt(selected.value, 10) : -1;
    });

    submitBtn.disabled    = true;
    submitBtn.textContent = "Submitting…";

    const r = await api(`/quizzes/${quizId}/attempt`, "POST", { answers });
    if (!r.success) {
      alert(r.message || "Submission failed.");
      submitBtn.disabled    = false;
      submitBtn.textContent = "Submit Quiz";
      return;
    }

    // ── Show results ─────────────────────────────────────────────────────
    quizArea.textContent = "";

    const score    = r.data.score;
    const total    = r.data.total;
    const pct      = total > 0 ? Math.round(score / total * 100) : 0;
    const passed   = pct >= 50;

    const resultCard = document.createElement("div");
    resultCard.className = `card alert ${passed ? "alert-success" : "alert-error"}`;
    resultCard.style.marginBottom = "1.5rem";

    const scoreH2 = document.createElement("h2");
    scoreH2.textContent = `Score: ${score} / ${total} (${pct}%)`;
    resultCard.appendChild(scoreH2);

    const verdict = document.createElement("p");
    verdict.textContent = passed ? "✓ Passed! Great work." : "✗ Not passed. Review the lessons and try again.";
    resultCard.appendChild(verdict);
    quizArea.appendChild(resultCard);

    // Per-question breakdown
    (r.data.answers || []).forEach((a, qi) => {
      const q     = questions[qi];
      const block = document.createElement("div");
      block.className = "question-block";

      const qp = document.createElement("p");
      qp.textContent = `${qi + 1}. ${q?.text || ""}`;
      block.appendChild(qp);

      (q?.options || []).forEach((opt, oi) => {
        const label = document.createElement("label");
        label.className = "option-label";
        if (oi === a.correct)                label.classList.add("option-correct");
        if (oi === a.given && !a.isCorrect)   label.classList.add("option-incorrect");
        const txt  = document.createElement("span");
        txt.textContent = opt + (oi === a.correct ? " ✓" : "") + (oi === a.given && !a.isCorrect ? " ✗" : "");
        label.appendChild(txt);
        block.appendChild(label);
      });

      quizArea.appendChild(block);
    });

    const backBtn = document.createElement("a");
    backBtn.className  = "btn btn-outline mt-1";
    backBtn.href       = "courses.html";
    backBtn.textContent = "← Back to Courses";
    quizArea.appendChild(backBtn);

    if (window.renderMathInElement) {
      renderMathInElement(quizArea, {
        delimiters: [
          { left: "$$", right: "$$", display: true },
          { left: "$",  right: "$",  display: false },
        ],
      });
    }
  });
})();
