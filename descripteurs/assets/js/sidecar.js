// Sidecar: 2-window mode for CERT add/modify flows.
// Mirrors the Feed 2 fenêtres pattern in session.html (openFeed2):
// a blank "main" popup on the left shows the CERT URL, and a sidecar popup
// on the right hosts the edit/review form.

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

// Renders a persistent checkbox into a container.
// Reflects state across tabs via the `storage` event.
export function renderSidecarToggle(container, { label = "Sidecar" } = {}) {
  if (!container) return;
  const wrap = document.createElement("label");
  wrap.className = "sidecar-toggle";
  wrap.style.cssText = "display:inline-flex;align-items:center;gap:6px;font-size:.82rem;color:#5e6673;cursor:pointer;user-select:none;";

  const cb = document.createElement("input");
  cb.type = "checkbox";
  cb.checked = isSidecarOn();
  cb.style.cssText = "accent-color:#6f5cf7;cursor:pointer;margin:0;";
  cb.addEventListener("change", () => setSidecarOn(cb.checked));

  const txt = document.createElement("span");
  txt.textContent = label;

  wrap.appendChild(cb);
  wrap.appendChild(txt);
  container.appendChild(wrap);

  window.addEventListener("storage", (e) => { if (e.key === SIDECAR_KEY) cb.checked = isSidecarOn(); });
  document.addEventListener("tc-sidecar-change", () => { cb.checked = isSidecarOn(); });

  return wrap;
}
