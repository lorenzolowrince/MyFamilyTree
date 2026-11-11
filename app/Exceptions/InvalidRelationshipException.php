<?php

namespace App\Exceptions;

use DomainException;
use Illuminate\Database\QueryException;

/**
 * A relationship that would make the tree impossible (a cycle, three biological parents...).
 * The message is written for the person using the app and is safe to show as a form error.
 */
class InvalidRelationshipException extends DomainException
{
    /** SQLSTATEs raised by the `enforce_parent_child_rules` trigger and related constraints. */
    private const DATABASE_MESSAGES = [
        'MF001' => 'That would make someone their own ancestor.',
        'MF002' => 'A person cannot have more than two biological parents.',
        'MF003' => 'A person cannot be both parent and partner of the same person.',
    ];

    /** Translate a database refusal into a friendly error, or return null if it is something else. */
    public static function fromQueryException(QueryException $exception): ?self
    {
        $state = (string) ($exception->errorInfo[0] ?? $exception->getCode());
        $message = $exception->getMessage();

        return match (true) {
            isset(self::DATABASE_MESSAGES[$state]) => new self(__(self::DATABASE_MESSAGES[$state]), previous: $exception),
            str_contains($message, 'family_relationships_one_parent_link') => new self(__('These two people are already linked as parent and child.'), previous: $exception),
            str_contains($message, 'family_relationships_not_self_check') => new self(__('A person cannot be related to themselves.'), previous: $exception),
            default => null,
        };
    }
}
