/**
 * Core fetch helper — DESIGN §11.6
 *
 * - Attaches Authorization: Bearer <token> from localStorage when present.
 * - Shows the #loading banner after 3 seconds (handles Render free-plan cold start).
 * - On 401: clears the token and redirects to login.html.
 * - Always returns the parsed JSON object, or throws on network error.
 */
async function api(path, method = "GET", body = undefined) {
  const token  = localStorage.getItem("token");
  const banner = document.getElementById("loading");
  let   slowTimer;

  if (banner) {
    // Show wake-up banner after 3 s (Render free-plan cold start can take 30–60 s)
    slowTimer = setTimeout(() => { banner.hidden = false; }, 3000);
  }

  // 90-second timeout — long enough for Render to wake up, short enough not to hang forever
  const controller = new AbortController();
  const timeoutId  = setTimeout(() => controller.abort(), 90000);

  try {
    const res = await fetch(API_BASE_URL + path, {
      method,
      signal: controller.signal,
      headers: {
        "Content-Type": "application/json",
        ...(token ? { Authorization: "Bearer " + token } : {}),
      },
      body: body !== undefined ? JSON.stringify(body) : undefined,
    });

    const json = await res.json();

    if (res.status === 401) {
      localStorage.removeItem("token");
      localStorage.removeItem("user");
      location.href = "login.html";
      return json;
    }

    return json;
  } catch (err) {
    if (err.name === "AbortError") {
      throw new Error("Request timed out. The server may still be waking up — please try again.");
    }
    throw err;
  } finally {
    clearTimeout(slowTimer);
    clearTimeout(timeoutId);
    if (banner) banner.hidden = true;
  }
}
