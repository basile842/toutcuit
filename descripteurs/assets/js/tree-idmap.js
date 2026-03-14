export async function initTreeFromIdMap(jsonUrl) {
  const ROOT_ID = "start";

  const choicesEl = document.getElementById("choices");
  const backBtn = document.getElementById("backBtn");
  const resetBtn = document.getElementById("resetBtn");

  const resultCard = document.getElementById("resultCard");
  const resultGraphicEl = document.getElementById("resultGraphic");

  const selectedBoxEl = document.getElementById("selectedBox");
  const pathLineSmallEl = document.querySelector("#pathLine small");
  const pathLineContainer = document.getElementById("pathLine");

  const treeSubtitleEl = document.getElementById("treeSubtitle");
  const bottomControlsEl = document.getElementById("bottomControls");

  const data = await fetch(jsonUrl).then((r) => {
    if (!r.ok) throw new Error(`Failed to load JSON: ${jsonUrl}`);
    return r.json();
  });

  const choices = data?.choices;
  const initialIds = Array.isArray(data?.initial) ? data.initial : [];

  if (!choices || typeof choices !== "object") throw new Error("data.json: missing `choices` object");
  if (initialIds.length === 0) throw new Error("data.json: missing/empty `initial` array");
  if (!choices[ROOT_ID]) throw new Error(`data.json: missing root node "${ROOT_ID}"`);

  /* -------------------------
     Icônes (chemins absolus)
  ------------------------- */
  const ICON_BASE = "/descripteurs/assets/icons/";
  const ICONS = {
    Journalisme: `${ICON_BASE}journalisme.svg`,
    Science: `${ICON_BASE}science.svg`,
    Gouvernement: `${ICON_BASE}gouvernement.svg`,
    Divertissement: `${ICON_BASE}divertissement.svg`,
    Organisation: `${ICON_BASE}organisation.svg`,
    Individu: `${ICON_BASE}individu.svg`,
    Inconnu: `${ICON_BASE}inconnu.svg`,

    "Vulgarisation scientifique": `${ICON_BASE}vulgarisation.svg`,
    Analyse: `${ICON_BASE}analyse.svg`,
    Reportage: `${ICON_BASE}reportage.svg`,
    Opinion: `${ICON_BASE}opinion.svg`,
    Rumeur: `${ICON_BASE}rumeur.svg`,
    Accrocheur: `${ICON_BASE}accrocheur.svg`,
    Trompeur: `${ICON_BASE}trompeur.svg`,
    Escroquerie: `${ICON_BASE}escroquerie.svg`,
    Partisan: `${ICON_BASE}partisan.svg`,
    Promotion: `${ICON_BASE}promotion.svg`,
    Annonce: `${ICON_BASE}annonce.svg`,
    Service: `${ICON_BASE}service.svg`,
    "Achat/Vente": `${ICON_BASE}achat-vente.svg`,
    "Première main": `${ICON_BASE}premiere-main.svg`,
    Tutoriel: `${ICON_BASE}tutoriel.svg`,
    Loisir: `${ICON_BASE}loisir.svg`,
    Humour: `${ICON_BASE}humour.svg`,
    Performance: `${ICON_BASE}performance.svg`,
  };

  function getIconForLabel(label) {
    return ICONS[label] || null;
  }
  function getIconForNode(id) {
    const n = choices[id];
    const key = n?.choiceShort || n?.choice;
    return ICONS[key] || null;
  }

  /* -------------------------
     State
  ------------------------- */
  const stack = [];
  let currentParentId = ROOT_ID;
  let currentIds = nodeChildrenIds(ROOT_ID);
  const selectionPath = [];
  let atResult = false;

  // Fiabilité
  let reliability = null; // "good" | "mid" | "bad" | null
  let reliabilityCardEl = null;

  // Publication
  let published = false;

  // Commentaire
  const COMMENT_KEY = "tree_comment_draft";
  let commentCardEl = null;

  // Mode résultat
  let inResultMode = false;

  // Bouton Valider
  let publishBtnEl = null;

  /* -------------------------
     Sidecar -> Page (robuste)
     ✅ FIX: n'émet vers Page que si published === true
  ------------------------- */
  const BROADCAST_PREFIX = "certify_broadcast_";

  function getContext() {
    return window.CERTIFY_CONTEXT && typeof window.CERTIFY_CONTEXT === "object"
      ? window.CERTIFY_CONTEXT
      : null;
  }

  function normalizePublishedComment(raw) {
    let t = String(raw || "").replace(/\r\n?/g, "\n");
    t = t.replace(/^\s*\n+/, "").replace(/\n+\s*$/, "");
    t = t
      .split("\n")
      .map((line) => line.replace(/^[ \t]+/g, ""))
      .join("\n");
    return t.trimEnd();
  }

  function emitResultToPage() {
    const ctx = getContext();
    if (!ctx?.id) return;
    if (!selectionPath.length) return;

    // ✅ IMPORTANT: tant que pas publié, on ne remonte rien vers Page
    if (!published) return;

    const firstLabel = labelForPath(selectionPath[0]);
    const lastLabel = labelForPath(selectionPath[selectionPath.length - 1]);

    const payload = {
      type: "CERTIFY_RESULT",
      id: String(ctx.id),
      title: String(ctx.title || ""),
      url: String(ctx.url || ""),

      firstLabel,
      lastLabel,
      firstIcon: getIconForLabel(firstLabel),
      lastIcon: getIconForLabel(lastLabel),

      reliability: reliability || null,
      comment: normalizePublishedComment(sessionStorage.getItem(COMMENT_KEY) || ""),

      published: true
    };

    // 1) postMessage
    try {
      const target = window.CERTIFY_PAGE || window.opener || null;
      target?.postMessage(payload, window.location.origin);
    } catch {}

    // 2) fallback storage-event
    try {
      const key = `${BROADCAST_PREFIX}${payload.id}`;
      const stamped = { ...payload, _ts: Date.now() };
      localStorage.setItem(key, JSON.stringify(stamped));
    } catch {}
  }

  let emitTimer = null;
  function scheduleEmit() {
    if (!getContext()?.id) return;
    if (!atResult) return;

    // ✅ pas d'émission "live" tant que pas publié
    if (!published) return;

    if (emitTimer) clearTimeout(emitTimer);
    emitTimer = setTimeout(() => {
      emitResultToPage();
      emitTimer = null;
    }, 200);
  }

  /* -------------------------
     Helpers
  ------------------------- */
  function labelForButton(id) {
    const n = choices[id];
    return n?.choice || n?.choiceShort || id;
  }
  function labelForPath(id) {
    const n = choices[id];
    return n?.choiceShort || n?.choice || id;
  }
  function nodeStepTitle(id) {
    const n = choices[id];
    return n?.stepTitle || "";
  }
  function nodeChildrenIds(id) {
    const n = choices[id];
    return Array.isArray(n?.children) ? n.children : [];
  }
  function isLeaf(id) {
    return nodeChildrenIds(id).length === 0;
  }
  function escapeHtml(s) {
    return String(s)
      .replaceAll("&", "&amp;")
      .replaceAll("<", "&lt;")
      .replaceAll(">", "&gt;")
      .replaceAll('"', "&quot;")
      .replaceAll("'", "&#039;");
  }

  function disableChoiceButtons() {
    choicesEl?.querySelectorAll("button.choice-btn").forEach((b) => (b.disabled = true));
  }
  function enableChoiceButtons() {
    choicesEl?.querySelectorAll("button.choice-btn").forEach((b) => (b.disabled = false));
  }

  /* -------------------------
     Place bottom controls
  ------------------------- */
  function placeBottomControlsToEnd() {
    if (!bottomControlsEl) return;
    const main = bottomControlsEl.closest("[data-tree-root], main")
              || document.querySelector("[data-tree-root]")
              || document.querySelector("main");
    if (!main) return;
    if (main.lastElementChild !== bottomControlsEl) main.appendChild(bottomControlsEl);
  }

  /* -------------------------
     Result mode
  ------------------------- */
  function enterResultMode() {
    inResultMode = true;
    if (choicesEl) choicesEl.style.display = "none";
    if (selectedBoxEl) selectedBoxEl.style.display = "none";
    // Cacher le .card parent qui contient le breadcrumb
    if (pathLineContainer) {
      const cardParent = pathLineContainer.closest('.card');
      if (cardParent && cardParent.contains(pathLineContainer)) {
        cardParent.style.display = "none";
      }
    }
  }
  function exitResultMode() {
    inResultMode = false;
    if (choicesEl) choicesEl.style.display = "";
    if (selectedBoxEl) selectedBoxEl.style.display = "";
    // Réafficher le .card parent
    if (pathLineContainer) {
      const cardParent = pathLineContainer.closest('.card');
      if (cardParent && cardParent.contains(pathLineContainer)) {
        cardParent.style.display = "";
      }
    }
  }

  /* -------------------------
     Publish button
  ------------------------- */
  function ensurePublishButton() {
    if (!bottomControlsEl) return null;
    if (publishBtnEl) return publishBtnEl;

    publishBtnEl = document.createElement("button");
    publishBtnEl.id = "publishBtn";
    publishBtnEl.className = "btn";
    publishBtnEl.textContent = "Valider";
    publishBtnEl.disabled = true;
    publishBtnEl.style.display = "none";

    bottomControlsEl.insertBefore(publishBtnEl, backBtn || bottomControlsEl.firstChild);

    publishBtnEl.addEventListener("click", () => {
      if (!reliability) return;

      // ✅ c'est CE clic qui rend published=true
      published = true;

      enterPublishedMode();
      if (reliabilityCardEl) reliabilityCardEl.style.display = "none";
      showPublishedComment();
      publishBtnEl.style.display = "none";

      // ✅ envoi final
      emitResultToPage();
    });

    return publishBtnEl;
  }

  function setPublishButtonState() {
    const btn = ensurePublishButton();
    if (!btn) return;

    if (atResult && !published) {
      btn.style.display = "";
      btn.disabled = !reliability;
      btn.style.opacity = reliability ? "1" : "";
    } else {
      btn.style.display = "none";
    }
  }

  /* -------------------------
     Cards: reliability & comment
  ------------------------- */
  function ensureReliabilityCard() {
    if (reliabilityCardEl) return reliabilityCardEl;

    reliabilityCardEl = document.createElement("section");
    reliabilityCardEl.id = "reliabilityCard";
    reliabilityCardEl.className = "card";
    reliabilityCardEl.style.display = "none";

    if (resultCard) resultCard.insertAdjacentElement("afterend", reliabilityCardEl);
    else document.body.appendChild(reliabilityCardEl);

    return reliabilityCardEl;
  }

  function ensureCommentCard() {
    if (commentCardEl) return commentCardEl;

    commentCardEl = document.createElement("section");
    commentCardEl.id = "commentCard";
    commentCardEl.className = "card";
    commentCardEl.style.display = "none";

    placeCommentCardAboveBottomControls();
    return commentCardEl;
  }

  function placeCommentCardAboveBottomControls() {
    if (!commentCardEl) return;
    placeBottomControlsToEnd();
    if (bottomControlsEl) bottomControlsEl.insertAdjacentElement("beforebegin", commentCardEl);
  }

  function showCommentEditor() {
    const card = ensureCommentCard();
    placeCommentCardAboveBottomControls();

    const saved = sessionStorage.getItem(COMMENT_KEY) || "";

    card.innerHTML = `
      <h2>Commentaire</h2>
      <textarea id="commentInput" rows="4" placeholder="Écrire un commentaire…"></textarea>
    `;

    const ta = card.querySelector("#commentInput");
    if (ta) {
      ta.value = saved;

      ta.addEventListener("input", () => {
        sessionStorage.setItem(COMMENT_KEY, ta.value || "");
        scheduleEmit();
      });
    }

    card.style.display = "block";
  }

  function showPublishedComment() {
    const card = ensureCommentCard();
    placeCommentCardAboveBottomControls();

    const raw = sessionStorage.getItem(COMMENT_KEY) || "";
    const text = normalizePublishedComment(raw);

    card.innerHTML = `
      <h2>Commentaire</h2>
      <div class="comment-published" style="white-space:pre-wrap; color: var(--muted); font-size: 1rem; line-height: 1.45;">
        ${text ? escapeHtml(text) : ""}
      </div>
    `;
    card.style.display = text ? "block" : "none";
  }

  function hideCommentCard() {
    if (!commentCardEl) return;
    commentCardEl.style.display = "none";
    commentCardEl.innerHTML = "";
  }

  /* -------------------------
     Published mode
  ------------------------- */
  function enterPublishedMode() {
    document.body.classList.add("solo-result");

    if (choicesEl) choicesEl.style.display = "none";
    if (selectedBoxEl) selectedBoxEl.style.display = "none";
    if (pathLineContainer) pathLineContainer.style.display = "none";
    if (treeSubtitleEl) treeSubtitleEl.style.display = "none";

    showPublishedComment();
    if (publishBtnEl) publishBtnEl.style.display = "none";
  }

  function exitPublishedMode() {
    published = false;
    document.body.classList.remove("solo-result");

    if (!inResultMode && choicesEl) choicesEl.style.display = "";
    if (!inResultMode && selectedBoxEl) selectedBoxEl.style.display = "";
    // Ne PAS réafficher le breadcrumb si on est encore en mode résultat
    if (!atResult && pathLineContainer) pathLineContainer.style.display = "";

    hideCommentCard();
  }

  /* -------------------------
     Reliability
  ------------------------- */
  function applyReliabilityToResult() {
    if (!resultGraphicEl) return;
    resultGraphicEl.classList.remove("rel-good", "rel-mid", "rel-bad");
    if (reliability === "good") resultGraphicEl.classList.add("rel-good");
    if (reliability === "mid") resultGraphicEl.classList.add("rel-mid");
    if (reliability === "bad") resultGraphicEl.classList.add("rel-bad");
  }

  function syncReliabilitySelectionUI() {
    if (!reliabilityCardEl) return;

    reliabilityCardEl.querySelectorAll("button[data-rel]").forEach((b) => {
      const isSel = reliability && b.dataset.rel === reliability;
      b.classList.toggle("is-selected", !!isSel);
      b.setAttribute("aria-pressed", isSel ? "true" : "false");
    });

    setPublishButtonState();
  }

  function setReliability(val) {
    reliability = val;
    applyReliabilityToResult();
    syncReliabilitySelectionUI();
    scheduleEmit();
  }

  function clearReliability() {
    reliability = null;
    applyReliabilityToResult();
    if (reliabilityCardEl) {
      reliabilityCardEl.style.display = "none";
      reliabilityCardEl.innerHTML = "";
    }
  }

  /* -------------------------
     Result
  ------------------------- */
  function clearResult() {
    if (resultCard) resultCard.style.display = "none";
    if (resultGraphicEl) resultGraphicEl.innerHTML = "";
    atResult = false;

    enableChoiceButtons();
    clearReliability();

    exitResultMode();
    if (published) exitPublishedMode();
    hideCommentCard();

    if (publishBtnEl) publishBtnEl.style.display = "none";
  }

  function showResultGraphic() {
    if (!selectionPath.length) return;

    const first = labelForPath(selectionPath[0]);
    const last = labelForPath(selectionPath[selectionPath.length - 1]);

    const firstIcon = getIconForLabel(first);
    const lastIcon = getIconForLabel(last);

    if (resultCard) resultCard.style.display = "block";

    resultGraphicEl.innerHTML = `
      <div class="result-stack">
        <div class="result-pill">
          ${firstIcon ? `<img class="result-icon" src="${firstIcon}" alt="" />` : ""}
          <span class="result-text">${escapeHtml(first)}</span>
        </div>
        <div class="result-pill">
          ${lastIcon ? `<img class="result-icon" src="${lastIcon}" alt="" />` : ""}
          <span class="result-text">${escapeHtml(last)}</span>
        </div>
      </div>
    `;

    const rel = ensureReliabilityCard();
    rel.innerHTML = `
      <h2>Fiabilité</h2>
      <div class="reliability-options">
        <button class="btn reliability-btn rel-good" data-rel="good" aria-pressed="false">Fiable</button>
        <button class="btn reliability-btn rel-mid" data-rel="mid" aria-pressed="false">Indéterminé</button>
        <button class="btn reliability-btn rel-bad" data-rel="bad" aria-pressed="false">Pas fiable</button>
      </div>
    `;
    rel.style.display = "block";

    rel.querySelectorAll("button[data-rel]").forEach((btn) => {
      btn.addEventListener("click", () => setReliability(btn.dataset.rel));
    });

    showCommentEditor();
    applyReliabilityToResult();
    syncReliabilitySelectionUI();

    ensurePublishButton();
    setPublishButtonState();

    placeBottomControlsToEnd();
    placeCommentCardAboveBottomControls();

    // ✅ plus de preview vers Page (emitResultToPage() est de toute façon bloqué tant que published=false)
    emitResultToPage();
  }

  function setControlsVisibility() {
    const started = selectionPath.length > 0;

    if (bottomControlsEl) bottomControlsEl.style.display = started ? "" : "none";
    if (backBtn) backBtn.style.display = started ? "" : "none";
    if (resetBtn) resetBtn.style.display = started ? "" : "none";

    if (selectedBoxEl) selectedBoxEl.style.display = atResult ? "none" : started ? "" : "none";

    placeBottomControlsToEnd();
    setPublishButtonState();

    if (!published) {
      if (atResult) showCommentEditor();
      else hideCommentCard();
    } else {
      showPublishedComment();
    }

    if (commentCardEl && commentCardEl.style.display !== "none") {
      placeCommentCardAboveBottomControls();
    }
  }

  /* -------------------------
     Breadcrumb
  ------------------------- */
  function renderPathLine() {
    if (!pathLineSmallEl) return;

    if (selectionPath.length === 0) {
      pathLineSmallEl.textContent = "";
      return;
    }

    const parts = selectionPath.map((id, idx) => {
      const label = escapeHtml(labelForPath(id));
      return `<a href="#" data-jump="${idx}">${label}</a>`;
    });

    pathLineSmallEl.innerHTML = parts.join(" → ");
  }

  function jumpToKeepCount(keepCount) {
    if (published) exitPublishedMode();
    if (atResult) {
      atResult = false;
      enableChoiceButtons();
      exitResultMode();
    }

    const target = selectionPath.slice(0, Math.max(0, keepCount));

    stack.length = 0;
    selectionPath.length = 0;

    currentParentId = ROOT_ID;
    currentIds = nodeChildrenIds(ROOT_ID).slice();

    for (const id of target) {
      selectionPath.push(id);
      stack.push({ parentId: currentParentId });
      currentParentId = id;
      currentIds = nodeChildrenIds(id).slice();
    }

    render();
  }

  pathLineContainer?.addEventListener("click", (e) => {
    const a = e.target.closest("a[data-jump]");
    if (!a) return;
    e.preventDefault();

    const idx = Number(a.dataset.jump);
    if (idx === 0) jumpToKeepCount(0);
    else jumpToKeepCount(idx);
  });

  /* -------------------------
     Subtitle
  ------------------------- */
  function updateTreeSubtitle() {
    if (!treeSubtitleEl) return;

    const atRoot = selectionPath.length === 0 && currentParentId === ROOT_ID;
    if (atRoot) {
      treeSubtitleEl.textContent = nodeStepTitle(ROOT_ID) || "";
      treeSubtitleEl.style.display = "";
    } else {
      treeSubtitleEl.textContent = "";
      treeSubtitleEl.style.display = "none";
    }
  }

  /* -------------------------
     Root inline toggle (i button)
  ------------------------- */
  function closeAllRootInlineDefs() {
    choicesEl?.querySelectorAll("button.choice-btn.is-key.has-def").forEach((btn) => {
      btn.classList.remove("has-def");
      btn.querySelectorAll(".choice-def-inline.root-inline").forEach((d) => d.remove());
      btn.querySelectorAll(".info-btn.is-active").forEach((b) => b.classList.remove("is-active"));
    });
  }

  function toggleRootInlineDef(btn, st, infoBtn) {
    const alreadyOpen = btn.classList.contains("has-def");
    closeAllRootInlineDefs();
    if (alreadyOpen) return;

    btn.classList.add("has-def");
    if (infoBtn) infoBtn.classList.add("is-active");

    let wrap = btn.querySelector(".label-wrap");
    if (!wrap) {
      const labelEl = btn.querySelector(".label");
      wrap = document.createElement("span");
      wrap.className = "label-wrap";
      if (labelEl) {
        labelEl.replaceWith(wrap);
        wrap.appendChild(labelEl);
      }
    }

    const def = document.createElement("div");
    def.className = "choice-def-inline root-inline";
    def.textContent = st || "";
    wrap.appendChild(def);
  }

  /* -------------------------
     Render choices
  ------------------------- */
  function renderChoices() {
    if (!choicesEl) return;
    choicesEl.innerHTML = "";

    const isRootScreen = currentParentId === ROOT_ID && selectionPath.length === 0;

    for (const id of currentIds) {
      const btn = document.createElement("button");
      btn.className = "btn choice-btn";

      const leaf = isLeaf(id);
      const iconSrc = getIconForNode(id);
      const title = labelForButton(id);
      const st = nodeStepTitle(id);

      if (isRootScreen) btn.classList.add("is-key");
      if (leaf) btn.classList.add("is-leaf");

      const left = document.createElement("span");
      left.className = "choice-left";

      if (iconSrc) {
        const img = document.createElement("img");
        img.src = iconSrc;
        img.className = "icon";
        img.alt = "";
        left.appendChild(img);
      }

      if (isRootScreen) {
        const label = document.createElement("span");
        label.className = "label";
        label.textContent = title;
        left.appendChild(label);
      } else {
        const hasInlineDef = !!st && st.trim() !== "" && st.trim() !== "2e descripteur...";

        if (hasInlineDef) {
          btn.classList.add("has-def");

          const wrap = document.createElement("span");
          wrap.className = "label-wrap";

          const label = document.createElement("span");
          label.className = "label";
          label.textContent = title;

          const def = document.createElement("div");
          def.className = "choice-def-inline";
          def.textContent = st;

          wrap.appendChild(label);
          wrap.appendChild(def);
          left.appendChild(wrap);
        } else {
          const label = document.createElement("span");
          label.className = "label";
          label.textContent = title;
          left.appendChild(label);
        }
      }

      btn.appendChild(left);

      const right = document.createElement("span");
      right.className = "choice-right";

      if (isRootScreen) {
        const info = document.createElement("button");
        info.type = "button";
        info.className = "info-btn";
        info.setAttribute("aria-label", "Afficher la définition");
        info.textContent = "i";

        info.addEventListener("click", (e) => {
          e.preventDefault();
          e.stopPropagation();
          toggleRootInlineDef(btn, st, info);
        });

        right.appendChild(info);
      } else {
        if (!leaf) {
          const chevron = document.createElement("span");
          chevron.className = "chevron";
          chevron.setAttribute("aria-hidden", "true");
          chevron.textContent = "▶";
          right.appendChild(chevron);
        }
      }

      btn.appendChild(right);

      btn.addEventListener("click", () => {
        if (atResult) return;
        closeAllRootInlineDefs();
        onChoose(id);
      });

      const item = document.createElement("div");
      item.className = "choice-item";
      item.appendChild(btn);

      choicesEl.appendChild(item);
    }
  }

  function render() {
    clearResult();
    setControlsVisibility();
    renderPathLine();
    updateTreeSubtitle();
    renderChoices();
  }

  function onChoose(id) {
    if (published) exitPublishedMode();

    selectionPath.push(id);

    if (isLeaf(id)) {
      atResult = true;
      enterResultMode();
      setControlsVisibility();
      showResultGraphic();
      disableChoiceButtons();
      return;
    }

    stack.push({ parentId: currentParentId });
    currentParentId = id;
    currentIds = nodeChildrenIds(id).slice();

    exitResultMode();
    render();
  }

  /* -------------------------
     Back / Reset
  ------------------------- */
  backBtn?.addEventListener("click", () => {
    if (published && atResult) {
      exitPublishedMode();
      if (reliabilityCardEl) reliabilityCardEl.style.display = "block";
      showCommentEditor();
      syncReliabilitySelectionUI();
      setControlsVisibility();
      // Garder le breadcrumb caché en mode résultat
      if (pathLineContainer) pathLineContainer.style.display = "none";
      return;
    }

    if (!selectionPath.length) return;

    atResult = false;
    enableChoiceButtons();

    selectionPath.pop();
    stack.pop();

    if (selectionPath.length === 0) {
      currentParentId = ROOT_ID;
      currentIds = nodeChildrenIds(ROOT_ID).slice();
    } else {
      currentParentId = selectionPath[selectionPath.length - 1];
      currentIds = nodeChildrenIds(currentParentId).slice();
    }

    exitResultMode();
    render();
  });

  resetBtn?.addEventListener("click", () => {
    atResult = false;
    enableChoiceButtons();

    stack.length = 0;
    selectionPath.length = 0;

    currentParentId = ROOT_ID;
    currentIds = nodeChildrenIds(ROOT_ID).slice();

    sessionStorage.removeItem(COMMENT_KEY);
    if (commentCardEl) {
      const ta = commentCardEl.querySelector("textarea");
      if (ta) ta.value = "";
    }

    if (published) exitPublishedMode();
    exitResultMode();
    render();
  });

  placeBottomControlsToEnd();
  ensurePublishButton();
  render();
}