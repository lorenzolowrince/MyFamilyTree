# F. Family Relationship Modelling Strategy

This is the hardest part of the system to get right, and the hardest to change later. The design follows four rules.

## Rule 1: People are nodes, relationships are edges

Each person is **one row** in `family_members`. Relationships are rows in `family_relationships` that point at two existing people. Adding "Ahmad's daughter" when she is already in the tree links to her existing record rather than creating a copy.

To stop duplicates at entry time, the "Add relative" form searches first ("Is this one of these people?") using trigram name matching plus birth year. A new record is created only when the user confirms no match.

## Rule 2: Store only two kinds of edge

| Edge | Meaning | Direction |
|---|---|---|
| `parent_child` | A is a parent of B | A = parent, B = child |
| `partnership` | A and B are/were partners | Unordered (stored with A < B) |

Everything else is **derived**, never stored:

| Relationship | Derived from |
|---|---|
| Siblings | Share at least one parent |
| Full siblings | Share both biological parents |
| Half-siblings | Share exactly one parent |
| Step-siblings | A parent of one is a partner of a parent of the other, with no shared parent |
| Grandparents, cousins, uncles... | Walking parent edges |
| In-laws | A partnership edge plus a blood path |

**Why:** if siblings were stored separately, it would be possible to record "A and B are siblings" while their parents say otherwise. Derived relationships can never contradict each other.

**The "siblings with unknown parents" problem:** families often know two people were brothers without knowing the parents' names. The UI's "Connect as siblings" action creates a **placeholder parent** (`is_placeholder = true`, shown as "Unknown parent") and links both to it. When the real parent is identified later, the placeholder is merged into the real person in one step.

## Rule 3: The edge's subtype carries the meaning (biological vs legal/social)

Each `parent_child` edge has a subtype: `biological`, `adoptive`, `step`, `foster`, `guardian`, or `unknown`. So one child can have:

- two biological parents (at most; enforced by the database),
- and any number of adoptive, step, foster or guardian parents.

Each edge also has a `certainty` (`confirmed`, `probable`, `possible`, `disputed`) and optional start/end dates (e.g. an adoption date, or a guardianship that ended).

**Marriages are their own edges**, independent of children. That handles:
- **Multiple marriages:** several `partnership` edges per person, ordered by date or `sequence` when dates are unknown.
- **Polygamous marriages** (legal for Muslims in Malaysia): several concurrent `current` partnerships. Nothing in the model assumes monogamy.
- **Children outside a marriage**, or whose other parent is unknown: the child simply has one parent edge.
- **Remarriage to the same person:** two partnership edges with different dates.

**Which marriage does a child belong to?** It is derived: if a child's two biological parents are partners, the child is shown under that couple. This avoids a separate "family unit" table that could fall out of sync, while still giving the tree view its couple-with-children layout. For GEDCOM export, couple "FAM" records are built from these edges.

## Rule 4: The database refuses impossible structures

Enforced by constraints and a deferrable constraint trigger (not just application code):

| Invalid structure | Prevented by |
|---|---|
| Linking people from different families | Composite FK on `(family_id, person_id)` |
| Being your own parent or spouse | `CHECK (person_a_id <> person_b_id)` |
| Being your own ancestor (cycle) | Trigger runs a recursive ancestor query before accepting a new parent edge |
| Three or more biological parents | Trigger counts biological parent edges |
| Duplicate parent link | Partial unique index |
| A–B and B–A both recorded as partners | Canonical ordering `CHECK` |

The application runs the same checks first so users get friendly messages ("Siti cannot be Ahmad's parent because Ahmad is already Siti's grandfather"). The trigger is the safety net.

---

## Generations

`generation` is a derived, read-only number, recomputed by a queued job after relationship changes:
- The oldest known ancestor line in the tree is generation 1; children are parent + 1.
- When a person's parents are in different generations (it happens, e.g. a man marrying his niece's generation), the deeper value is used.
- People with no parent connections inherit a generation from their partner, otherwise null ("not connected").

"Total generations" on the dashboard is `max(generation)`.

---

## Relationship Finder (Module 7)

### How it works

1. **Load the graph.** For a family, load all member IDs and relationship edges (a few hundred KB even at 10,000 people) and cache it in Redis under `family:{id}:graph:{graph_version}`. Any edit bumps `graph_version`, so a stale graph is never used.
2. **Find common ancestors.** Breadth-first search upward from person X and person Y over parent edges, recording the distance to every ancestor. The **lowest common ancestors** are the shared ancestors with the smallest combined distance.
3. **Name the relationship** from the two distances (`dx` = X's steps up, `dy` = Y's steps up), from X's point of view ("Y is X's ..."):

| dx | dy | Y is X's |
|---|---|---|
| 0 | 1 | child |
| 1 | 0 | parent |
| 0 | n | grandchild, great-grandchild... (`n-2` "great"s) |
| n | 0 | grandparent, great-grandparent... |
| 1 | 1 | sibling (full/half determined by shared parents) |
| 1 | n≥2 | niece/nephew, grand-niece... |
| n≥2 | 1 | uncle/aunt, grand-uncle... |
| m≥2 | n≥2 | (min(m,n)−1)th cousin, \|m−n\| times removed |

Gendered words (father/mother, uncle/aunt) are used when gender is recorded, otherwise neutral ones (parent, parent's sibling).

4. **Qualify the path.** If any edge on the path is `adoptive`, the result is labelled "adoptive" (e.g. "adoptive grandfather"); `step` edges give "step-"; if two siblings share only one parent, "half-". Paths containing `possible`/`disputed` edges are flagged as uncertain.
5. **No blood path?** Search through partnership edges: "spouse", "father-in-law", "sister-in-law", "spouse's cousin". If still nothing, show the shortest connection of any kind ("connected through marriage via ...") or "not related in this tree".
6. **Multiple paths.** In families with cousin marriages there can be several valid relationships (e.g. both second cousins *and* first cousins once removed). All shortest distinct paths are shown, the closest first.
7. **Show the path visually.** The finder returns the ordered list of people and edges. The D3 tree highlights that path, and a compact "path card" shows X → parent → grandparent ← parent ← Y.

### Testing

The relationship calculator is pure PHP with no database access, so it is unit-tested exhaustively against a fixture family (four generations, two marriages, half-siblings, an adoption, a step-family, a cousin marriage). These tests are a release gate.

### Bahasa Malaysia and local kinship terms

The calculator returns a structured result (`{kind: 'cousin', degree: 1, removed: 0, half: false, line: 'maternal', ...}`), not a sentence. Labels come from translation files. That allows English, Bahasa Malaysia (`sepupu`, `dua pupu`, `datuk`, `nenek`, `pak cik`, `mak cik`) and later regional terms, including birth order where families use it (`abang`, `kakak`, `adik`).

---

## Tree visualisation model (D3)

The API returns a **focus-centred subgraph**, not the whole tree: the focus person, N generations of ancestors, N generations of descendants (N = 3 by default), and partners. Expanding a collapsed node fetches the next slice. This keeps the tree fast on phones with very large families.

- **Descendant view:** `d3.tree()` layout with couples rendered as a joined pair node, children below.
- **Ancestor view (pedigree):** mirrored `d3.tree()` growing upward.
- **Hourglass view:** both around the focus person.
- Multiple marriages appear as additional partner cards beside the person, each with its own children.
- When a person appears twice in a layout (cousin marriage), the second occurrence is drawn as a linked "see also" card instead of duplicating the subtree.
- `d3.zoom()` for pinch/wheel zoom and pan; keyboard navigation and a list view as an accessible alternative to the canvas.
