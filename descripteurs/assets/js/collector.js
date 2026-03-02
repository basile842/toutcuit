/* ============
   Collector client
   - pseudo auto (localStorage)
   - code de séance obligatoire
   - POST vers Apps Script (sans preflight CORS)
   - max 2 entrées (contrôle serveur + affichage local)
============ */

const STORAGE_KEY = "certify.collector.user.v1";

/* ⚠️ mêmes valeurs que le feed */
const COLLECTOR_ENDPOINT = "https://script.google.com/macros/s/AKfycbw85ZgY-ayPxzfZyCTv7Rh1HSmjXSHaYPkj-GIFXkrneTNBRNVKEezb8q8p1RM7og/exec";
const COLLECTOR_TOKEN = "60b66cdf-3982-4e5c-9f51-z456f0da873b";

const MAX_PER_USER = 2;

/* ============
   Utils
============ */

function escapeHtml(s){
  return String(s ?? "")
    .replaceAll("&", "&amp;")
    .replaceAll("<", "&lt;")
    .replaceAll(">", "&gt;")
    .replaceAll('"', "&quot;")
    .replaceAll("'", "&#039;");
}

function normalizeUrl(raw){
  const s = String(raw || "").trim();
  if (!s) return "";
  if (/^https?:\/\//i.test(s)) return s;
  if (/^www\./i.test(s)) return "https://" + s;
  return s;
}

function isProbablyUrl(s){
  const v = String(s || "").trim();
  if (!v) return false;
  if (/^https?:\/\/\S+$/i.test(v)) return true;
  if (/^www\.\S+$/i.test(v)) return true;
  return false;
}

/* ============
   Pseudo generator
============ */

const WORDS = [
  "amitié","analyse","calme","certitude","choix","clarté","collaboration","comparaison",
  "compréhension","confiance","connaissance","contenu","contexte","courage","démocratie",
  "donnée","droits","égalité","ensemble","exploration","fiabilité","latérale","liberté",
  "lien","paix","patience","pensée","perspective","preuve","recherche","référence",
  "réflexion","solidarité","science","source","vérité"
];

function randInt(min, max){
  return Math.floor(Math.random() * (max - min + 1)) + min;
}

function buildUser(){
  const w = WORDS[randInt(0, WORDS.length - 1)];
  const n = randInt(1, 99);
  const id = `${w}${n}`;
  return {
    user_id: id,
    user_nick: id
  };
}

function loadOrCreateUser(){
  try{
    const raw = localStorage.getItem(STORAGE_KEY);
    if (raw){
      const obj = JSON.parse(raw);
      if (obj?.user_id && obj?.user_nick) return obj;
    }
  }catch{}
  const u = buildUser();
  localStorage.setItem(STORAGE_KEY, JSON.stringify(u));
  return u;
}

/* ============
   Network
============ */

async function fetchJsonLoose(url, opts){
  const res = await fetch(url, opts);
  const text = await res.text();
  if (!res.ok){
    throw new Error(`HTTP ${res.status} – ${text.slice(0, 600)}`);
  }
  try{
    return JSON.parse(text);
  }catch{
    throw new Error("Réponse non-JSON.\n\n" + text.slice(0, 900));
  }
}

function qs(params){
  const sp = new URLSearchParams();
  Object.entries(params || {}).forEach(([k,v]) => {
    if (v !== undefined && v !== null) sp.set(k, String(v));
  });
  return sp.toString();
}

async function getCollectorStatus({ user_id, session_code }){
  const url = `${COLLECTOR_ENDPOINT}?${qs({
    action: "collectorStatus",
    token: COLLECTOR_TOKEN,
    user_id,
    session_code
  })}`;
  return fetchJsonLoose(url, { cache: "no-store" });
}

/* ✅ FIX CORS ICI : POST sans Content-Type */
async function postCollect({ user_id, url, comment, session_code }){
  return fetchJsonLoose(COLLECTOR_ENDPOINT, {
    method: "POST",
    body: JSON.stringify({
      action: "collect",
      token: COLLECTOR_TOKEN,
      user_id,
      url,
      comment,
      session_code
    })
  });
}

/* ============
   UI
============ */

export function initCollector(){
  const nickEl = document.getElementById("nick");
  const remainingEl = document.getElementById("remaining");
  const closedBox = document.getElementById("closedBox");
  const form = document.getElementById("collectorForm");
  const msgEl = document.getElementById("msg");
  const statusEl = document.getElementById("status");
  const hintEl = document.getElementById("hint");
  const submitBtn = document.getElementById("submitBtn");
  const refreshBtn = document.getElementById("refreshBtn");

  const codeEl = document.getElementById("sessionCode");
  const urlEl = document.getElementById("url");
  const commentEl = document.getElementById("comment");

  const user = loadOrCreateUser();
  if (nickEl) nickEl.textContent = user.user_nick;

  // pré-remplissage via ?code=XXXX
  try{
    const u = new URL(location.href);
    const c = (u.searchParams.get("code") || "").trim();
    if (c && codeEl) codeEl.value = c;
  }catch{}

  function setMsg(html, kind = ""){
    if (!msgEl) return;
    if (!html){ msgEl.innerHTML = ""; return; }
    const cls = kind === "ok" ? "ok-box" : kind === "err" ? "error-box" : "status";
    msgEl.innerHTML = `<div class="${cls}">${html}</div>`;
  }

  function setStatus(text){
    if (statusEl) statusEl.textContent = text || "";
  }

  function setEnabledUI(enabled){
    if (closedBox) closedBox.classList.toggle("hidden", !!enabled);
    if (form){
      Array.from(form.querySelectorAll("input, textarea, button")).forEach(el => {
        if (el.id !== "refreshBtn") el.disabled = !enabled;
      });
    }
  }

  function setRemaining(n){
    const safe = Math.max(0, Math.min(MAX_PER_USER, Number(n) || 0));
    if (remainingEl) remainingEl.textContent = String(safe);
    if (hintEl){
      hintEl.textContent = safe > 0
        ? `Tu peux encore envoyer ${safe} lien(s).`
        : `Limite atteinte (2 liens).`;
    }
    if (submitBtn) submitBtn.disabled = safe <= 0;
  }

  async function refreshStatus(){
    setMsg("");
    setStatus("Vérification…");

    const session_code = String(codeEl?.value || "").trim();
    const payload = await getCollectorStatus({ user_id: user.user_id, session_code });

    if (!payload?.ok){
      setStatus("");
      setMsg(`Erreur: ${escapeHtml(payload?.error || "status_failed")}`, "err");
      setEnabledUI(false);
      return;
    }

    setEnabledUI(payload.enabled);

    if (!payload.enabled){
      setRemaining(0);
      setMsg("Collector fermé (le professeur l’a désactivé).", "err");
      return;
    }

    if (payload.session_ok === false){
      setMsg("Code de séance incorrect.", "err");
    }

    setRemaining(payload.remaining ?? MAX_PER_USER);
    setStatus("");
  }

  refreshBtn?.addEventListener("click", refreshStatus);

  form?.addEventListener("submit", async (ev) => {
    ev.preventDefault();
    setMsg("");

    const session_code = String(codeEl?.value || "").trim();
    const url = normalizeUrl(urlEl?.value || "");
    const comment = String(commentEl?.value || "").trim();

    if (!session_code) return setMsg("Code de séance requis.", "err");
    if (!isProbablyUrl(url)) return setMsg("URL invalide.", "err");
    if (!comment) return setMsg("Commentaire requis.", "err");

    submitBtn.disabled = true;
    setStatus("Envoi…");

    try{
      const res = await postCollect({ user_id: user.user_id, url, comment, session_code });

      if (!res?.ok){
        submitBtn.disabled = false;
        return setMsg(`Erreur: ${escapeHtml(res?.error || "collect_failed")}`, "err");
      }

      setRemaining(res.remaining);
      setMsg("✅ Merci ! Ton lien a été enregistré.", "ok");
      urlEl.value = "";
      commentEl.value = "";
    }catch(err){
      submitBtn.disabled = false;
      setMsg(escapeHtml(err.message), "err");
    }finally{
      setStatus("");
    }
  });
}