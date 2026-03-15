export function renderNav(activePath = "", options = {}) {
  const variant = options.variant || "default";

  // ✅ Permet de cibler le mode sidecar en CSS si besoin
  document.body.classList.toggle("is-sidecar", variant === "sidecar");

  const nav = document.querySelector("nav");
  if (!nav) return;

  // 🔒 Sidecar : aucune navigation
  if (variant === "sidecar") {
    nav.innerHTML = "";
    return;
  }

  const isLiensPage =
    activePath.endsWith("/pages/liens.html") ||
    activePath.endsWith("liens.html");

  const isCertsPage =
    activePath.endsWith("/pages/certs.html") ||
    activePath.endsWith("certs.html");

  const isIndexPage =
    activePath.endsWith("/descripteurs/index.html") ||
    activePath.endsWith("/descripteurs/");

  // 🌐 Définition des liens
  const links = [];

  // ➜ Macades si on n'est PAS sur Liens, Certs ni Index
  if (!isLiensPage && !isCertsPage && !isIndexPage) {
    links.push(["Macades", "/descripteurs/index.html"]);
  }

  // ➜ Liens si on n'est PAS sur Liens, Certs ni Index
  if (!isLiensPage && !isCertsPage && !isIndexPage) {
    links.push(["Liens", "/descripteurs/pages/liens.html"]);
  }

  // Propagate query string (e.g. ?session=...) to nav links
  const qs = window.location.search;

  // 🌐 Rendu HTML
  nav.innerHTML = links
    .map(([label, href]) => {
      const active = activePath.endsWith(href)
        ? "aria-current='page'"
        : "";
      return `<a href="${href}${qs}" ${active}>${label}</a>`;
    })
    .join("");
}