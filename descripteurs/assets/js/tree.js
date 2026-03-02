export async function initTree(jsonUrl) {
  const stepTitleEl = document.getElementById("stepTitle");
  const choicesEl = document.getElementById("choices");
  const breadcrumbEl = document.getElementById("breadcrumb");
  const backBtn = document.getElementById("backBtn");
  const resetBtn = document.getElementById("resetBtn");
  const resultCard = document.getElementById("resultCard");
  const resultJson = document.getElementById("resultJson");

  const root = await fetch(jsonUrl).then(r => {
    if (!r.ok) throw new Error("Failed to load tree.json");
    return r.json();
  });

  const stack = [];
  let node = root;

  function render() {
    resultCard.style.display = "none";
    resultJson.textContent = "";

    breadcrumbEl.innerHTML = stack.map(s => s.label).join(" → ") || "<small>Début</small>";
    stepTitleEl.textContent = node.stepTitle || "Choix";

    choicesEl.innerHTML = "";
    for (const c of node.choices || []) {
      const btn = document.createElement("button");
      btn.className = "btn";
      btn.textContent = c.label;
      btn.addEventListener("click", () => onChoose(c));
      choicesEl.appendChild(btn);
    }

    backBtn.disabled = stack.length === 0;
  }

  function onChoose(choice) {
    if (choice.result) {
      resultCard.style.display = "block";
      resultJson.textContent = JSON.stringify(choice.result, null, 2);
      return;
    }
    if (choice.children) {
      stack.push({ node, label: choice.label });
      node = choice.children;
      render();
      return;
    }
  }

  render();
}