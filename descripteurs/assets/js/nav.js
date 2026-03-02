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

  // 🌐 Définition des liens
  const links = [];

  // ➜ Macades UNIQUEMENT si on n'est PAS sur la page Liens
  if (!isLiensPage) {
    links.push(["Macades", "/descripteurs/index.html"]);
  }

  // ➜ Liens toujours présent
  links.push(["Liens", "/descripteurs/pages/liens.html"]);

  // 🌐 Rendu HTML
  nav.innerHTML = links
    .map(([label, href]) => {
      const active = activePath.endsWith(href)
        ? "aria-current='page'"
        : "";
      return `<a href="${href}" ${active}>${label}</a>`;
    })
    .join("");
}