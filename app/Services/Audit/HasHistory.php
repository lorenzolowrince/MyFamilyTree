<?php

namespace App\Services\Audit;

/** A record whose creation, changes and removal are written to the audit log and activity feed. */
interface HasHistory
{
    /** Short name used in action keys: `member`, `relationship`, `timeline`... */
    public function historyNoun(): string;

    public function historyFamilyId(): string;

    /** The sentence for the activity feed (without the actor's name), or null for none. */
    public function historySummary(string $event): ?string;

    /** Who may see the activity line: `family`, `restricted` or `private`. */
    public function historyVisibility(): string;
}
