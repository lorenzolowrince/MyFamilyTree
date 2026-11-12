<?php

namespace App\Services\Reports;

use App\Services\Genealogy\FamilyGraph;

/**
 * Draws a descendant chart as SVG: a person with their partners side by side, their children
 * in a row below, and so on down. Drawn on the server because the PDF engine runs no scripts.
 *
 * Someone who appears twice (the child of two cousins who married descends from the same
 * ancestor on both sides) is drawn in full once and as a reference afterwards.
 *
 * @phpstan-type Card array{name: string, detail: string|null, hidden: bool}
 * @phpstan-type Unit array{id: string, partners: list<string>, children: list<array<string, mixed>>, width: float, repeat: bool}
 */
class TreeChart
{
    private const BOX_WIDTH = 150.0;

    private const BOX_HEIGHT = 46.0;

    private const GAP = 18.0;

    private const PARTNER_GAP = 6.0;

    private const ROW = 96.0;

    /** @var array<string, true> */
    private array $drawn = [];

    /** @var list<string> */
    private array $shapes = [];

    /**
     * @param  callable(string): Card  $card  what to write in a person's box
     * @return array{svg: string, width: float, height: float, people: int}
     */
    public function draw(FamilyGraph $graph, string $rootId, int $generations, callable $card): array
    {
        $this->drawn = [];
        $this->shapes = [];

        $root = $this->unit($graph, $rootId, $generations);
        $depth = $this->depth($root);

        $width = $root['width'] + 2 * self::GAP;
        $height = ($depth - 1) * self::ROW + self::BOX_HEIGHT + 2 * self::GAP;

        $this->place($root, self::GAP, self::GAP, $card);

        $svg = sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 %s %s" role="img" aria-label="%s">%s</svg>',
            round($width, 1),
            round($height, 1),
            e(__('Descendant chart')),
            implode('', $this->shapes),
        );

        return ['svg' => $svg, 'width' => $width, 'height' => $height, 'people' => count($this->drawn)];
    }

    /**
     * @return Unit
     */
    private function unit(FamilyGraph $graph, string $id, int $generations): array
    {
        if (isset($this->drawn[$id])) {
            return ['id' => $id, 'partners' => [], 'children' => [], 'width' => self::BOX_WIDTH, 'repeat' => true];
        }

        $this->drawn[$id] = true;

        $partners = [];

        foreach ($graph->partnerIds($id) as $partner) {
            // A partner who is drawn elsewhere as a descendant still stands beside this person.
            $partners[] = $partner;
        }

        $children = [];

        if ($generations > 1) {
            foreach ($graph->childIds($id) as $child) {
                $children[] = $this->unit($graph, $child, $generations - 1);
            }
        }

        $own = self::BOX_WIDTH + count($partners) * (self::BOX_WIDTH + self::PARTNER_GAP);
        $below = array_sum(array_column($children, 'width')) + max(0, count($children) - 1) * self::GAP;

        return ['id' => $id, 'partners' => $partners, 'children' => $children, 'width' => max($own, $below), 'repeat' => false];
    }

    /**
     * @param  Unit  $unit
     */
    private function depth(array $unit): int
    {
        $deepest = 0;

        foreach ($unit['children'] as $child) {
            /** @var Unit $child */
            $deepest = max($deepest, $this->depth($child));
        }

        return 1 + $deepest;
    }

    /**
     * @param  Unit  $unit
     * @param  callable(string): Card  $card
     * @return float the x of the middle of this person's own box, for the line from their parent
     */
    private function place(array $unit, float $left, float $top, callable $card): float
    {
        $own = self::BOX_WIDTH + count($unit['partners']) * (self::BOX_WIDTH + self::PARTNER_GAP);
        $x = $left + ($unit['width'] - $own) / 2;

        $this->box($x, $top, $card($unit['id']), $unit['repeat'] ? 'repeat' : 'person');

        foreach ($unit['partners'] as $index => $partner) {
            $partnerX = $x + ($index + 1) * (self::BOX_WIDTH + self::PARTNER_GAP);
            $this->box($partnerX, $top, $card($partner), 'partner');
            $this->shapes[] = sprintf(
                '<line x1="%s" y1="%s" x2="%s" y2="%s" class="marriage"/>',
                round($partnerX - self::PARTNER_GAP, 1), round($top + self::BOX_HEIGHT / 2, 1), round($partnerX, 1), round($top + self::BOX_HEIGHT / 2, 1),
            );
        }

        $middle = $x + self::BOX_WIDTH / 2;

        if ($unit['children'] === []) {
            return $middle;
        }

        $below = array_sum(array_column($unit['children'], 'width')) + (count($unit['children']) - 1) * self::GAP;
        $childLeft = $left + ($unit['width'] - $below) / 2;
        $childTop = $top + self::ROW;
        $rail = $top + self::BOX_HEIGHT + (self::ROW - self::BOX_HEIGHT) / 2;
        $tops = [];

        foreach ($unit['children'] as $child) {
            /** @var Unit $child */
            $tops[] = $this->place($child, $childLeft, $childTop, $card);
            $childLeft += $child['width'] + self::GAP;
        }

        // Down from the parent, along a rail, and down to each child.
        $this->shapes[] = sprintf('<path class="link" d="M%s %s V%s"/>', round($middle, 1), round($top + self::BOX_HEIGHT, 1), round($rail, 1));
        $this->shapes[] = sprintf('<path class="link" d="M%s %s H%s"/>', round(min($middle, ...$tops), 1), round($rail, 1), round(max($middle, ...$tops), 1));

        foreach ($tops as $childMiddle) {
            $this->shapes[] = sprintf('<path class="link" d="M%s %s V%s"/>', round($childMiddle, 1), round($rail, 1), round($childTop, 1));
        }

        return $middle;
    }

    /**
     * @param  Card  $card
     */
    private function box(float $x, float $y, array $card, string $class): void
    {
        $repeat = $class === 'repeat';
        $class .= $card['hidden'] ? ' hidden' : '';
        $name = mb_strlen($card['name']) > 24 ? mb_substr($card['name'], 0, 23).'…' : $card['name'];

        $this->shapes[] = sprintf(
            '<g class="%s"><rect x="%s" y="%s" width="%s" height="%s" rx="6"/><text x="%s" y="%s" class="name">%s</text><text x="%s" y="%s" class="detail">%s</text></g>',
            $class,
            round($x, 1), round($y, 1), self::BOX_WIDTH, self::BOX_HEIGHT,
            round($x + self::BOX_WIDTH / 2, 1), round($y + 19, 1), e($name),
            round($x + self::BOX_WIDTH / 2, 1), round($y + 35, 1), e($repeat ? __('shown elsewhere') : (string) $card['detail']),
        );
    }
}
