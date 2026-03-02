
////////////////////////
//
//  Decision Tree sketch. 
//  Node graph with multiple children, 
//  multiple parents.
//  ddelcourt 6.2025
//
////////////////////////


// Define DecisionTree class
var DecisionTree = function (data) {
  // Input validation
  if (!data || typeof data !== "object") {
    throw new Error("DecisionTree: Invalid data structure provided");
  }

  // Initialize properties
  this.initial = data.initial;
  this.choices = data.choices;
  this.data = data;

  // Add stepTitle to each choice node
  Object.keys(this.choices).forEach((id) => {
    if (!this.choices[id].stepTitle) {
      this.choices[id].stepTitle = this.choices[id].choice;
    }
  });

  // Build parent relationships dynamically
  this._buildParentRelationships();

  // Initialize the tree
  this.init();
};

// Core methods
DecisionTree.prototype._buildParentRelationships = function () {
  // Clear any existing parent references
  Object.keys(this.choices).forEach((id) => {
    if (Array.isArray(this.choices[id].parents)) {
      delete this.choices[id].parents;
    }
  });

  // Build parent relationships from children references
  const parentMap = {};

  Object.keys(this.choices).forEach((id) => {
    const choice = this.choices[id];
    if (choice.children && Array.isArray(choice.children)) {
      choice.children.forEach((childId) => {
        if (!parentMap[childId]) {
          parentMap[childId] = new Set();
        }
        parentMap[childId].add(id);
      });
    }
  });

  // Apply the parent relationships
  Object.entries(parentMap).forEach(([childId, parents]) => {
    this.choices[childId].parents = Array.from(parents);
  });
};

DecisionTree.prototype.init = function () {
  // Validate and initialize the tree structure
  const idList = [];

  Object.keys(this.choices).forEach((k) => {
    if (idList.indexOf(k) !== -1) {
      throw new Error(`DecisionTree: Duplicate ID "${k}" in choice set`);
    }

    const choice = this.getChoice(k);
    choice.id = k;

    const children = this.getChildren(k);
    children.forEach((child) => {
      if (child.parent) {
        throw new Error(
          `DecisionTree: Node "${k}" has conflicting parent relationships`
        );
      }
    });

    idList.push(k);
  });

  console.log("Tree initialized successfully");
};

DecisionTree.prototype.getChoice = function (id) {
  if (!(id in this.choices)) {
    throw new Error(`DecisionTree: Choice "${id}" not found`);
  }
  return this.choices[id];
};

DecisionTree.prototype.getChildren = function (parentId) {
  if (!(parentId in this.choices)) {
    throw new Error(`DecisionTree: Parent "${parentId}" not found`);
  }

  const choice = this.choices[parentId];
  if (!("children" in choice) || !Array.isArray(choice.children)) {
    return [];
  }

  return choice.children.map((childId) => this.getChoice(childId));
};

DecisionTree.prototype.getParents = function (id) {
  const choice = this.getChoice(id);
  if (!choice || !Array.isArray(choice.parents)) {
    return [];
  }

  return choice.parents.map((parentId) => this.getChoice(parentId));
};

DecisionTree.prototype.getParentIds = function (id) {
  const parents = this.getParents(id);
  return parents.map((parent) => parent.id);
};

DecisionTree.prototype.getParentName = function (id) {
  const parents = this.getParents(id);
  return parents.length > 0 ? parents[0].stepTitle || parents[0].choice : false;
};

DecisionTree.prototype.getInitial = function () {
  if (!this.initial || !Array.isArray(this.initial)) {
    throw new Error("DecisionTree: No initial choices specified");
  }
  return this.initial.map((id) => this.getChoice(id));
};

DecisionTree.prototype.getChildCount = function (parentId) {
  const children = this.getChildren(parentId);
  return children.length;
};

// Display results
function displaySelectList(selectList, tree) {
  // Validate inputs
  if (!Array.isArray(selectList)) {
    console.warn("Invalid selectList provided:", selectList);
    return;
  }
  const selectListElement = $("#selectList");
  // Check if element exists
  if (!selectListElement.length) {
    console.warn("Could not find #selectList element");
    return;
  }
  selectListElement.empty();
  // Iterate and append elements
  selectList.forEach((choiceId) => {
    const choiceObject = tree.getChoice(choiceId);
    // If choiceShort exists, use it, otherwise use choice
    const textToShow = choiceObject.choiceShort || choiceObject.choice;
    selectListElement.append(`<li>${textToShow}</li>`);
  });
}

// Load JSON data
async function loadJSON() {
  const response = await fetch("./lib/data.json");
  const data = await response.json();
  // Create instance
  const tree = new DecisionTree(data);
  return tree;
}

// Export DecisionTree
export { DecisionTree };

// Selection code
$(function () {
  // Load the tree and handle it when ready
  loadJSON().then((tree) => {
    var $list = $("#choices");
    var $title = $("h2");
    var current_id = null;
    var step = 0;
    var selectList = [];
    console.log("Step (init)", step);

    var renderList = function (items) {
      // Check if items array is empty
      if (!Array.isArray(items) || items.length === 0) {
        $title.text("Thank You!");
        $list.empty();
        return;
      }

      // Get title from first item
      var title = items[0].stepTitle || items[0].choice;
      if (title) {
        $title.text(title);
      } else {
        $title.text("Descripteurs");
      }

      $list.empty();
      for (var i = 0; i < items.length; i++) {
        var item = items[i];
        $list.append(
          '<li class="button choice animate__animated animate__fadeInUp"><a href="#" data-choice="' +
            item.id +
            '">' +
            item.choice +
            "</a></li>"
        );
      }
    };

    var _doInitial = function () {
      var initData = tree.getInitial();
      current_id = null;
      renderList(initData);
      step = 0;
      $("#back").hide(); // Hide the back button at init
    };

    $(document).on("click", "#choices a", function (e) {
      e.preventDefault();
      $("#back").show(); // Show the choices list
      step++;
      var choiceId = $(this).data("choice");
      console.log("Step ", step, ": Clicked", choiceId);
      selectList.push(choiceId);
      console.log("List : ", selectList);
      displaySelectList(selectList, tree);
      // Get and log child count for selected node
      var kids = tree.getChildren(choiceId);
      if (kids.length > 0) {
        current_id = choiceId;
        console.log(
          `Selected node ${choiceId} has ${kids.length} children !!!`
        );
        renderList(kids);
      } else {
        console.log(`Node ${choiceId} has no children (end of branch)`);
        selectList.pop();
        $("#choices").hide(); // Hide the choices list when reaching end of branch
        // $('#back').hide(); // Hide the back button when reaching end of branch
        $("#finalChoice").show(); // Show Validation or restart button when reaching end of branch
      }
    });

    $("#back").on("click", function (e) {
      e.preventDefault();
      if (!current_id) return false;
      const hasChildren = tree.getChildren(current_id).length > 0;
      console.log("Children : ", hasChildren);
      step--;
      console.log("Back button clicked. Going back to step ", step);
      selectList.pop();
      console.log("List : ", selectList);
      displaySelectList(selectList, tree);
      $("#choices").show(); // Show the choices list when navigating back
      var parents = tree.getParents(current_id);
      if (parents.length > 0) {
        var prev_node = parents.pop();
        console.log("popping");
        current_id = prev_node.id;
        const children = tree.getChildren(prev_node.id);
        if (children) {
          console.log(
            `Navigated to node ${prev_node.id} which has ${children.length} children`
          );
          renderList(children);
          $("#finalChoice").hide(); // Show Validation or restart button when reaching end of branch
        } else {
          console.log(
            `Navigated to node ${prev_node.id} which has no children (end of branch)`
          );
        }
      } else {
        _doInitial();
      }
    });

    $("#go").on("click", function (e) {
      e.preventDefault();
      var cid = $("#show-id").val();
      if (!cid || !(cid in tree.choices)) return false;
      console.log("Show parents for", cid);
      var parentData = tree.getParents(cid);
      $("#results").val(JSON.stringify(parentData, null, 4));
    });

    _doInitial();
  });
});
