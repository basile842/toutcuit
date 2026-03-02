export function initWebWindow() {
  const input = document.getElementById("urlInput");
  const openBtn = document.getElementById("openBtn");
  const newTabBtn = document.getElementById("newTabBtn");
  const frame = document.getElementById("frame");
  const hint = document.querySelector("#frameHint small");

  function normalizeUrl(u) {
    const s = (u || "").trim();
    if (!s) return "";
    if (s.startsWith("http://") || s.startsWith("https://")) return s;
    return "https://" + s;
  }

  function openInFrame() {
    const url = normalizeUrl(input.value);
    if (!url) return;
    hint.textContent = "";
    frame.src = url;
    setTimeout(() => {
      hint.textContent = "Si la zone reste vide, c’est probablement un blocage iframe.";
    }, 1200);
  }

  openBtn?.addEventListener("click", openInFrame);
  newTabBtn?.addEventListener("click", () => {
    const url = normalizeUrl(input.value);
    if (!url) return;
    window.open(url, "_blank", "noopener,noreferrer");
  });

  input?.addEventListener("keydown", (e) => {
    if (e.key === "Enter") openInFrame();
  });
}