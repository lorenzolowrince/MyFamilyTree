import type { TreeAncestor, TreeSlice, TreeUnit, TreeView } from '@/types';

/*
| Turns a tree slice into positions on a plane. Pure functions: no DOM, no React.
|
| Descendants grow downward. Each "unit" is a person with their partners side by side; the
| children of each couple hang below. Ancestors grow upward from the same focus person, one
| card per person. The hourglass view is both at once.
*/

export const CARD = { width: 176, height: 64 } as const;
const GAP = { card: 14, sibling: 28, level: 72 } as const;

export type LayoutCard = {
    /** Unique within the picture (a person can appear more than once). */
    key: string;
    personId: string;
    x: number;
    y: number;
    role: 'person' | 'partner' | 'ancestor';
    /** Key of the card one step closer to the focus person, for path highlighting. */
    towardsFocus: string | null;
    /** This person is drawn in full elsewhere in the picture. */
    repeat: boolean;
    /** More relatives exist beyond what was loaded, in this direction. */
    more: 'up' | 'down' | null;
    /** Has children in the picture that can be folded away. */
    collapsible: boolean;
    collapsed: boolean;
};

export type LayoutLine = {
    key: string;
    path: string;
    kind: 'descent' | 'couple';
    /** Adoptive, step, foster and guardian links are drawn dashed. */
    dashed: boolean;
    /** The card this line leads to (away from the focus), for path highlighting. */
    to: string | null;
};

export type TreeLayout = {
    cards: LayoutCard[];
    lines: LayoutLine[];
    bounds: { minX: number; minY: number; maxX: number; maxY: number };
    focusKey: string | null;
};

const isDashed = (link: string | null) =>
    link !== null && link !== 'biological';

function unitWidth(unit: TreeUnit): number {
    const cards = 1 + unit.unions.filter((union) => union.partner).length;

    return cards * CARD.width + (cards - 1) * GAP.card;
}

function visibleChildren(unit: TreeUnit, collapsed: Set<string>): TreeUnit[] {
    return collapsed.has(unit.person)
        ? []
        : unit.unions.flatMap((union) => union.children);
}

function subtreeWidth(unit: TreeUnit, collapsed: Set<string>): number {
    const children = visibleChildren(unit, collapsed);
    const childrenWidth =
        children.reduce(
            (sum, child) => sum + subtreeWidth(child, collapsed),
            0,
        ) +
        Math.max(0, children.length - 1) * GAP.sibling;

    return Math.max(unitWidth(unit), childrenWidth);
}

function placeUnit(
    unit: TreeUnit,
    left: number,
    top: number,
    path: string,
    parentKey: string | null,
    collapsed: Set<string>,
    layout: TreeLayout,
): { personKey: string; personCentre: number } {
    const width = subtreeWidth(unit, collapsed);
    const rowLeft = left + (width - unitWidth(unit)) / 2;
    const personKey = `d:${path}`;
    const hasChildren = unit.unions.some((union) => union.children.length > 0);
    const isCollapsed = collapsed.has(unit.person);

    layout.cards.push({
        key: personKey,
        personId: unit.person,
        x: rowLeft,
        y: top,
        role: 'person',
        towardsFocus: parentKey,
        repeat: unit.repeat,
        more: unit.more ? 'down' : null,
        collapsible: hasChildren,
        collapsed: isCollapsed && hasChildren,
    });

    // Partners sit to the right of the person, in marriage order.
    const anchors = new Map<number, number>();
    let partnerIndex = 0;

    unit.unions.forEach((union, unionIndex) => {
        if (!union.partner) {
            anchors.set(unionIndex, rowLeft + CARD.width / 2);

            return;
        }

        partnerIndex += 1;
        const x = rowLeft + partnerIndex * (CARD.width + GAP.card);
        const key = `${personKey}|p${unionIndex}`;

        layout.cards.push({
            key,
            personId: union.partner,
            x,
            y: top,
            role: 'partner',
            towardsFocus: personKey,
            repeat: false,
            more: null,
            collapsible: false,
            collapsed: false,
        });

        layout.lines.push({
            key: `couple:${key}`,
            path: `M ${x - GAP.card} ${top + CARD.height / 2} H ${x}`,
            kind: 'couple',
            dashed: union.relationship === null,
            to: key,
        });

        // The first couple's children hang from between the two; later ones from the partner.
        anchors.set(
            unionIndex,
            partnerIndex === 1 ? x - GAP.card / 2 : x + CARD.width / 2,
        );
    });

    const children = visibleChildren(unit, collapsed);

    if (children.length === 0) {
        return { personKey, personCentre: rowLeft + CARD.width / 2 };
    }

    const childrenWidth =
        children.reduce(
            (sum, child) => sum + subtreeWidth(child, collapsed),
            0,
        ) +
        (children.length - 1) * GAP.sibling;

    const childTop = top + CARD.height + GAP.level;
    let cursor = left + (width - childrenWidth) / 2;
    let childIndex = 0;

    unit.unions.forEach((union, unionIndex) => {
        if (union.children.length === 0) {
            return;
        }

        const anchorX = anchors.get(unionIndex) ?? rowLeft + CARD.width / 2;
        const anchorY = union.partner
            ? top + CARD.height / 2
            : top + CARD.height;
        // Each couple gets its own height for the horizontal bar, so bars never overlap.
        const busY = top + CARD.height + 22 + (unionIndex % 4) * 9;

        union.children.forEach((child) => {
            const childWidth = subtreeWidth(child, collapsed);
            const placed = placeUnit(
                child,
                cursor,
                childTop,
                `${path}.${childIndex}`,
                personKey,
                collapsed,
                layout,
            );

            layout.lines.push({
                key: `descent:${placed.personKey}`,
                path: `M ${anchorX} ${anchorY} V ${busY} H ${placed.personCentre} V ${childTop}`,
                kind: 'descent',
                dashed: isDashed(child.link),
                to: placed.personKey,
            });

            cursor += childWidth + GAP.sibling;
            childIndex += 1;
        });
    });

    return { personKey, personCentre: rowLeft + CARD.width / 2 };
}

function ancestorWidth(node: TreeAncestor): number {
    const parentsWidth =
        node.parents.reduce((sum, parent) => sum + ancestorWidth(parent), 0) +
        Math.max(0, node.parents.length - 1) * GAP.sibling;

    return Math.max(CARD.width, parentsWidth);
}

function placeAncestor(
    node: TreeAncestor,
    left: number,
    top: number,
    path: string,
    childKey: string | null,
    /** The focus person is already drawn by the descendant half of an hourglass. */
    existingKey: string | null,
    layout: TreeLayout,
): { key: string; centre: number } {
    const width = ancestorWidth(node);
    const x = left + (width - CARD.width) / 2;
    const key = existingKey ?? `a:${path}`;

    if (existingKey === null) {
        layout.cards.push({
            key,
            personId: node.person,
            x,
            y: top,
            role: 'ancestor',
            towardsFocus: childKey,
            repeat: node.repeat,
            more: node.more ? 'up' : null,
            collapsible: false,
            collapsed: false,
        });
    } else if (node.more) {
        const card = layout.cards.find((item) => item.key === existingKey);

        if (card && card.more === null) {
            card.more = 'up';
        }
    }

    if (node.parents.length > 0) {
        const parentsWidth =
            node.parents.reduce(
                (sum, parent) => sum + ancestorWidth(parent),
                0,
            ) +
            (node.parents.length - 1) * GAP.sibling;
        const parentTop = top - GAP.level - CARD.height;
        const busY = top - GAP.level / 2;
        let cursor = left + (width - parentsWidth) / 2;

        node.parents.forEach((parent, index) => {
            const placed = placeAncestor(
                parent,
                cursor,
                parentTop,
                `${path}.${index}`,
                key,
                null,
                layout,
            );

            layout.lines.push({
                key: `ascent:${placed.key}`,
                path: `M ${x + CARD.width / 2} ${top} V ${busY} H ${placed.centre} V ${parentTop + CARD.height}`,
                kind: 'descent',
                dashed: isDashed(parent.link),
                to: placed.key,
            });

            cursor += ancestorWidth(parent) + GAP.sibling;
        });
    }

    return { key, centre: x + CARD.width / 2 };
}

export function layoutTree(
    slice: TreeSlice,
    view: TreeView,
    collapsed: Set<string>,
): TreeLayout {
    const layout: TreeLayout = {
        cards: [],
        lines: [],
        bounds: { minX: 0, minY: 0, maxX: CARD.width, maxY: CARD.height },
        focusKey: null,
    };

    if (view === 'ancestors') {
        layout.focusKey = placeAncestor(
            slice.ancestors,
            0,
            0,
            '0',
            null,
            null,
            layout,
        ).key;
    } else {
        const root = placeUnit(
            slice.descendants,
            0,
            0,
            '0',
            null,
            collapsed,
            layout,
        );
        layout.focusKey = root.personKey;

        if (view === 'hourglass') {
            // Line the ancestor half up so its root sits exactly on the focus person's card.
            const width = ancestorWidth(slice.ancestors);

            placeAncestor(
                slice.ancestors,
                root.personCentre - width / 2,
                0,
                '0',
                null,
                root.personKey,
                layout,
            );
        }
    }

    for (const card of layout.cards) {
        layout.bounds.minX = Math.min(layout.bounds.minX, card.x);
        layout.bounds.minY = Math.min(layout.bounds.minY, card.y);
        layout.bounds.maxX = Math.max(layout.bounds.maxX, card.x + CARD.width);
        layout.bounds.maxY = Math.max(layout.bounds.maxY, card.y + CARD.height);
    }

    return layout;
}

/** Keys of the cards on the line between a card and the focus person (both included). */
export function pathToFocus(
    layout: TreeLayout,
    key: string | null,
): Set<string> {
    const byKey = new Map(layout.cards.map((card) => [card.key, card]));
    const path = new Set<string>();
    let current = key === null ? undefined : byKey.get(key);

    while (current && !path.has(current.key)) {
        path.add(current.key);
        current =
            current.towardsFocus === null
                ? undefined
                : byKey.get(current.towardsFocus);
    }

    return path;
}

/** The partners drawn beside a person in the descendant half, for "add a child". */
export function partnersOf(unit: TreeUnit, personId: string): string[] {
    if (unit.person === personId) {
        return unit.unions.flatMap((union) =>
            union.partner ? [union.partner] : [],
        );
    }

    for (const union of unit.unions) {
        for (const child of union.children) {
            const found = partnersOf(child, personId);

            if (found.length > 0) {
                return found;
            }
        }
    }

    return [];
}
