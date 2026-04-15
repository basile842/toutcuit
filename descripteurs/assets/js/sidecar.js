// Sidecar: 2-window mode for CERT add/modify flows.
// Mirrors the Feed 2 fenêtres pattern in session.html (openFeed2):
// a blank "main" popup on the left shows the CERT URL, and a sidecar popup
// on the right hosts the edit/review form.

console.log("[sidecar] module loaded");

const SIDECAR_KEY = "tc_sidecar_mode";
const SIDECAR_MAIN_NAME = "cert_sidecar_main";
const SIDECAR_SIDE_NAME = "cert_sidecar_side";
const SIDECAR_SIDE_W    = 520;

export function isSidecarOn() {
  try { return localStorage.getItem(SIDECAR_KEY) === "on"; } catch { return false; }
}

export function setSidecarOn(on) {
  try { localStorage.setItem(SIDECAR_KEY, on ? "on" : "off"); } catch {}
  document.dispatchEvent(new CustomEvent("tc-sidecar-change", { detail: { on } }));
}

// Opens two popups: left = URL, right = sidecarPageUrl.
// Copies the exact dimensions/options of openFeed2 in session.html.
export function openCertSidecar(urlToOpen, sidecarPageUrl) {
  const isMobile = window.matchMedia && window.matchMedia("(pointer: coarse)").matches;
  if (isMobile) {
    alert("Cette fonction n'est accessible que depuis un ordinateur desktop.");
    return false;
  }

  const W = Math.max(800, window.screen?.availWidth  || window.innerWidth  || 1200);
  const H = Math.max(600, window.screen?.availHeight || window.innerHeight || 900);
  const X = window.screen?.availLeft ?? 0;
  const Y = window.screen?.availTop  ?? 0;

  const sideW = Math.min(SIDECAR_SIDE_W, Math.floor(W * 0.45));
  const gap   = 10;
  const mainW = W - sideW - gap;
  const sideLeft = X + W - sideW;

  // Window 1 (left): CERT URL
  const main = window.open(urlToOpen || "about:blank", SIDECAR_MAIN_NAME,
    `popup=yes,width=${mainW},height=${H},left=${X},top=${Y},resizable=yes,scrollbars=yes`
  );
  if (!main) { alert("Pop-ups bloquées. Autorisez les pop-ups pour ce site."); return false; }
  try { main.focus(); } catch {}

  // Window 2 (right): sidecar edit/review page
  const side = window.open(sidecarPageUrl, SIDECAR_SIDE_NAME,
    `popup=yes,width=${sideW},height=${H},left=${sideLeft},top=${Y},resizable=yes,scrollbars=yes`
  );
  if (!side) {
    try { main.close(); } catch {}
    alert("Pop-ups bloquées. Autorisez les pop-ups pour ce site.");
    return false;
  }
  return true;
}

// Renders a persistent pill-style toggle into a container.
// Reflects state across tabs via the `storage` event.
export function renderSidecarToggle(container, { label = "Sidecar" } = {}) {
  if (!container) return;
  const btn = document.createElement("button");
  btn.type = "button";
  btn.className = "sidecar-toggle";
  const paint = () => {
    const on = isSidecarOn();
    btn.setAttribute("aria-pressed", on ? "true" : "false");
    btn.textContent = `${label} : ${on ? "ON" : "OFF"}`;
    btn.style.cssText = [
      "font-size:.78rem",
      "font-weight:600",
      "cursor:pointer",
      "border-radius:999px",
      "padding:4px 12px",
      "margin-right:14px",
      "transition:all .15s",
      on
        ? "background:var(--accent);color:#fff;border:1px solid var(--accent);"
        : "background:transparent;color:var(--muted);border:1px solid var(--border);",
    ].join(";");
  };
  btn.addEventListener("click", () => {
    const next = !isSidecarOn();
    console.log("[sidecar] toggle click →", next);
    setSidecarOn(next);
  });
  container.appendChild(btn);
  paint();

  window.addEventListener("storage", (e) => { if (e.key === SIDECAR_KEY) paint(); });
  document.addEventListener("tc-sidecar-change", paint);

  return btn;
}
