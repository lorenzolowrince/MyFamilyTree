import { select } from 'd3-selection';
import { zoom, zoomIdentity } from 'd3-zoom';
import type { ZoomBehavior } from 'd3-zoom';
import {
    ChevronDown,
    ChevronUp,
    Maximize,
    Minus,
    MoreHorizontal,
    Plus,
} from 'lucide-react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { MemberAvatar } from '@/components/members/member-avatar';
import { CARD, layoutTree, pathToFocus } from '@/components/tree/tree-layout';
import type { LayoutCard } from '@/components/tree/tree-layout';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import type { MemberSummary, TreeSlice, TreeView } from '@/types';

const MIN_ZOOM = 0.15;
const MAX_ZOOM = 2;
const PADDING = 48;
/** Below this, names can no longer be read. */
const READABLE_ZOOM = 0.6;

/**
 * The tree drawing. d3-zoom handles pan and pinch/wheel zoom on the viewport; the layout is
 * computed by `layoutTree` and drawn by React as real buttons (so cards work with keyboard
 * and screen readers) over an SVG layer of connecting lines.
 */
export function FamilyTreeCanvas({
    slice,
    people,
    view,
    selectedKey,
    onSelect,
    onExpand,
}: {
    slice: TreeSlice;
    people: Record<string, MemberSummary>;
    view: TreeView;
    selectedKey: string | null;
    onSelect: (card: LayoutCard) => void;
    /** Load more of the tree around this person (they become the new centre). */
    onExpand: (personId: string) => void;
}) {
    const viewportRef = useRef<HTMLDivElement>(null);
    const stageRef = useRef<HTMLDivElement>(null);
    const zoomRef = useRef<ZoomBehavior<HTMLDivElement, unknown> | null>(null);
    const [collapsed, setCollapsed] = useState<Set<string>>(() => new Set());

    const layout = useMemo(
        () => layoutTree(slice, view, collapsed),
        [slice, view, collapsed],
    );

    const highlighted = useMemo(
        () => pathToFocus(layout, selectedKey),
        [layout, selectedKey],
    );

    // Attach pan and zoom once.
    useEffect(() => {
        const viewport = viewportRef.current;

        if (!viewport) {
            return;
        }

        const behaviour = zoom<HTMLDivElement, unknown>()
            .scaleExtent([MIN_ZOOM, MAX_ZOOM])
            .on(
                'zoom',
                (event: { transform: { x: number; y: number; k: number } }) => {
                    const { x, y, k } = event.transform;

                    if (stageRef.current) {
                        stageRef.current.style.transform = `translate(${x}px, ${y}px) scale(${k})`;
                    }
                },
            );

        zoomRef.current = behaviour;
        const selection = select(viewport);
        selection.call(behaviour).on('dblclick.zoom', null);

        return () => {
            selection.on('.zoom', null);
        };
    }, []);

    /**
     * Show the whole picture, never enlarged beyond natural size. With `readable`, a picture
     * too big to read at full fit (a phone) is shown larger, centred on the focus person.
     */
    const fit = useCallback(
        (readable = false) => {
            const viewport = viewportRef.current;
            const behaviour = zoomRef.current;

            if (!viewport || !behaviour) {
                return;
            }

            const { minX, minY, maxX, maxY } = layout.bounds;
            const width = maxX - minX + PADDING * 2;
            const height = maxY - minY + PADDING * 2;
            const whole = Math.min(
                1,
                viewport.clientWidth / width,
                viewport.clientHeight / height,
            );
            const focus = layout.cards.find(
                (card) => card.key === layout.focusKey,
            );

            if (readable && whole < READABLE_ZOOM && focus) {
                behaviour.transform(
                    select(viewport),
                    zoomIdentity
                        .translate(
                            viewport.clientWidth / 2 -
                                (focus.x + CARD.width / 2) * READABLE_ZOOM,
                            viewport.clientHeight / 2 -
                                (focus.y + CARD.height / 2) * READABLE_ZOOM,
                        )
                        .scale(READABLE_ZOOM),
                );

                return;
            }

            const scale = Math.max(MIN_ZOOM, whole);
            const x =
                (viewport.clientWidth - (maxX - minX) * scale) / 2 -
                minX * scale;
            const y =
                (viewport.clientHeight - (maxY - minY) * scale) / 2 -
                minY * scale;

            behaviour.transform(
                select(viewport),
                zoomIdentity.translate(x, y).scale(scale),
            );
        },
        [layout],
    );

    // Re-fit whenever a different part of the tree is shown.
    useEffect(() => {
        fit(true);
        // `fit` changes with every fold/unfold; only a new slice or view should re-fit.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [slice, view]);

    const zoomBy = (factor: number) => {
        const viewport = viewportRef.current;

        if (viewport && zoomRef.current) {
            zoomRef.current.scaleBy(select(viewport), factor);
        }
    };

    /** Keep a card that received keyboard focus inside the visible area. */
    const reveal = (card: LayoutCard) => {
        const viewport = viewportRef.current;
        const behaviour = zoomRef.current;

        if (!viewport || !behaviour) {
            return;
        }

        behaviour.translateTo(
            select(viewport),
            card.x + CARD.width / 2,
            card.y + CARD.height / 2,
        );
    };

    const toggle = (personId: string) =>
        setCollapsed((current) => {
            const next = new Set(current);

            if (next.has(personId)) {
                next.delete(personId);
            } else {
                next.add(personId);
            }

            return next;
        });

    return (
        <div className="relative h-full min-h-[24rem] overflow-hidden rounded-xl border bg-muted/30">
            <div
                ref={viewportRef}
                className="absolute inset-0 cursor-grab touch-none active:cursor-grabbing"
                role="group"
                aria-label="Family tree. Drag to move, pinch or use the buttons to zoom. Use Tab to move between people."
            >
                <div
                    ref={stageRef}
                    className="absolute top-0 left-0 origin-top-left will-change-transform"
                >
                    <svg
                        className="pointer-events-none absolute top-0 left-0 overflow-visible"
                        width="1"
                        height="1"
                        aria-hidden="true"
                    >
                        {layout.lines.map((line) => {
                            const active =
                                line.to !== null &&
                                highlighted.has(line.to) &&
                                highlighted.size > 1;

                            return (
                                <path
                                    key={line.key}
                                    d={line.path}
                                    fill="none"
                                    strokeDasharray={
                                        line.dashed ? '5 4' : undefined
                                    }
                                    strokeWidth={active ? 2.5 : 1.5}
                                    className={
                                        active
                                            ? 'stroke-primary'
                                            : 'stroke-muted-foreground/50'
                                    }
                                />
                            );
                        })}
                    </svg>

                    {layout.cards.map((card) => {
                        const person = people[card.personId];

                        if (!person) {
                            return null;
                        }

                        const selected = card.key === selectedKey;
                        const onPath =
                            highlighted.has(card.key) && highlighted.size > 1;

                        return (
                            <div
                                key={card.key}
                                className="absolute"
                                style={{
                                    left: card.x,
                                    top: card.y,
                                    width: CARD.width,
                                    height: CARD.height,
                                }}
                            >
                                <button
                                    type="button"
                                    onClick={() => onSelect(card)}
                                    onFocus={(event) => {
                                        if (
                                            event.target.matches(
                                                ':focus-visible',
                                            )
                                        ) {
                                            reveal(card);
                                        }
                                    }}
                                    aria-pressed={selected}
                                    aria-label={[
                                        person.name,
                                        person.lifespan,
                                        person.is_me ? 'this is you' : null,
                                        card.role === 'partner'
                                            ? 'partner'
                                            : null,
                                        card.repeat
                                            ? 'also shown elsewhere in the tree'
                                            : null,
                                    ]
                                        .filter(Boolean)
                                        .join(', ')}
                                    className={cn(
                                        'flex size-full items-center gap-2 rounded-lg border bg-card px-2.5 text-left shadow-xs transition-colors outline-none hover:bg-accent focus-visible:ring-[3px] focus-visible:ring-ring/60',
                                        card.key === layout.focusKey &&
                                            'border-primary/60',
                                        onPath && 'border-primary',
                                        selected &&
                                            'border-primary ring-2 ring-primary/40',
                                        card.repeat && 'border-dashed',
                                        person.hidden && 'opacity-70',
                                    )}
                                >
                                    <MemberAvatar member={person} />
                                    <span className="min-w-0 flex-1">
                                        <span className="line-clamp-2 text-[13px] leading-tight font-medium">
                                            {person.name}
                                        </span>
                                        <span className="block truncate text-[11px] text-muted-foreground">
                                            {card.repeat
                                                ? 'See also'
                                                : person.is_me
                                                  ? `You${person.lifespan ? ` · ${person.lifespan}` : ''}`
                                                  : (person.lifespan ??
                                                    (person.living &&
                                                    !person.hidden &&
                                                    !person.is_placeholder
                                                        ? 'Living'
                                                        : ''))}
                                        </span>
                                    </span>
                                </button>

                                {card.collapsible && (
                                    <button
                                        type="button"
                                        onClick={() => toggle(card.personId)}
                                        aria-expanded={!card.collapsed}
                                        aria-label={`${card.collapsed ? 'Show' : 'Hide'} descendants of ${person.name}`}
                                        className="absolute -bottom-2.5 left-4 flex size-5 items-center justify-center rounded-full border bg-background text-muted-foreground shadow-xs outline-none hover:text-foreground focus-visible:ring-[3px] focus-visible:ring-ring/60"
                                    >
                                        {card.collapsed ? (
                                            <ChevronDown className="size-3.5" />
                                        ) : (
                                            <ChevronUp className="size-3.5" />
                                        )}
                                    </button>
                                )}

                                {card.more && (
                                    <button
                                        type="button"
                                        onClick={() => onExpand(card.personId)}
                                        aria-label={`Show more ${card.more === 'up' ? 'ancestors' : 'descendants'} of ${person.name}`}
                                        title={`More ${card.more === 'up' ? 'ancestors' : 'descendants'}`}
                                        className={cn(
                                            'absolute right-4 flex size-5 items-center justify-center rounded-full border bg-primary text-primary-foreground shadow-xs outline-none focus-visible:ring-[3px] focus-visible:ring-ring/60',
                                            card.more === 'up'
                                                ? '-top-2.5'
                                                : '-bottom-2.5',
                                        )}
                                    >
                                        <MoreHorizontal className="size-3.5" />
                                    </button>
                                )}
                            </div>
                        );
                    })}
                </div>
            </div>

            <div className="absolute right-3 bottom-3 flex flex-col gap-1">
                <Button
                    type="button"
                    variant="outline"
                    size="icon"
                    className="size-8 bg-background"
                    aria-label="Zoom in"
                    onClick={() => zoomBy(1.3)}
                >
                    <Plus className="size-4" />
                </Button>
                <Button
                    type="button"
                    variant="outline"
                    size="icon"
                    className="size-8 bg-background"
                    aria-label="Zoom out"
                    onClick={() => zoomBy(1 / 1.3)}
                >
                    <Minus className="size-4" />
                </Button>
                <Button
                    type="button"
                    variant="outline"
                    size="icon"
                    className="size-8 bg-background"
                    aria-label="Fit the whole tree on screen"
                    onClick={() => fit()}
                >
                    <Maximize className="size-4" />
                </Button>
            </div>

            <p className="pointer-events-none absolute bottom-3 left-3 rounded-md bg-background/80 px-2 py-1 text-[11px] text-muted-foreground">
                Dashed line: adoptive, step, foster or guardian
            </p>
        </div>
    );
}
