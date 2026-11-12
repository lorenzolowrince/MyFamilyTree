<?php

namespace App\Services\Audit;

use Illuminate\Database\Eloquent\Model;

/**
 * Hooks a model's lifecycle into the HistoryRecorder. Because the hooks live on the model,
 * no controller, job or import can change a record without leaving a trace.
 *
 * @mixin Model
 *
 * @phpstan-require-implements HasHistory
 */
trait RecordsHistory
{
    public static function bootRecordsHistory(): void
    {
        foreach (['created', 'updated', 'deleted'] as $event) {
            static::{$event}(function (Model $model) use ($event): void {
                if ($model instanceof HasHistory) {
                    app(HistoryRecorder::class)->model($event, $model);
                }
            });
        }
    }

    public function historyFamilyId(): string
    {
        return (string) $this->getAttribute('family_id');
    }

    public function historyVisibility(): string
    {
        return 'family';
    }
}
