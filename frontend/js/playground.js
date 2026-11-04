/**
 * Live code playground — DESIGN §6 mod5, §14
 *
 * - Three editors: HTML / CSS / JS
 * - Builds a preview via iframe srcdoc (sandbox="allow-scripts")
 * - Can pre-load code passed from lesson.html via ?code= query param
 * - Never executes PHP server-side
 */

renderNavbar("playground");

const htmlEditor = document.getElementById("htmlEditor");
const cssEditor  = document.getElementById("cssEditor");
const jsEditor   = document.getElementById("jsEditor");
const frame      = document.getElementById("preview-frame");
const runBtn     = document.getElementById("runBtn");
const clearBtn   = document.getElementById("clearBtn");

/** Build the srcdoc from the three editors and push it into the iframe. */
function runCode() {
  const html = htmlEditor.value;
  const css  = cssEditor.value;
  const js   = jsEditor.value;

  const doc = `<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<style>${css}</style>
</head>
<body>
${html}
<script>
try {
${js}
} catch(e) {
  document.body.insertAdjacentHTML('beforeend',
    '<pre style="color:red;padding:1rem;font-family:monospace">Error: ' + e.message + '</pre>');
}
<\/script>
</body>
</html>`;

  frame.srcdoc = doc;
}

// ── Load code from lesson (query param ?code=...) ─────────────────────────
const params  = new URLSearchParams(location.search);
const preCode = params.get("code");
if (preCode) {
  // Heuristic: if it starts with `<!DOCTYPE` or `<html`, put it in HTML;
  // otherwise put it in HTML and let the user move it.
  const decoded = decodeURIComponent(preCode);
  if (/^\s*</.test(decoded)) {
    htmlEditor.value = decoded;
  } else {
    jsEditor.value = decoded;
  }
  runCode();
}

// ── Buttons ────────────────────────────────────────────────────────────────
runBtn.addEventListener("click", runCode);

clearBtn.addEventListener("click", () => {
  if (confirm("Clear all editors?")) {
    htmlEditor.value = "";
    cssEditor.value  = "";
    jsEditor.value   = "";
    frame.srcdoc     = "";
  }
});

// Auto-run on Ctrl+Enter / Cmd+Enter in any editor
[htmlEditor, cssEditor, jsEditor].forEach(el => {
  el.addEventListener("keydown", e => {
    if ((e.ctrlKey || e.metaKey) && e.key === "Enter") {
      e.preventDefault();
      runCode();
    }
  });
});
