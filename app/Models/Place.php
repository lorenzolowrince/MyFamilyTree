<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A place a family refers to, written the way the family writes it.
 *
 * @property string $id
 * @property string $family_id
 * @property string $name
 * @property string|null $locality
 * @property string|null $district
 * @property string|null $state
 * @property string|null $country_code
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'locality', 'district', 'state', 'country_code'])]
class Place extends Model
{
    use HasUuids;

    /** @return BelongsTo<Family, $this> */
    public function family(): BelongsTo
    {
        return $this->belongsTo(Family::class);
    }

    /**
     * The family's place with this name, created when it is new. Names are matched without
     * regard to case, so "Ranau" and "ranau" are one place.
     */
    public static function resolve(Family $family, ?string $name): ?self
    {
        $name = trim((string) preg_replace('/\s+/u', ' ', (string) $name));

        if ($name === '') {
            return null;
        }

        $existing = self::query()
            ->where('family_id', $family->id)
            ->whereRaw('lower(name) = lower(?)', [$name])
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $place = new self(['name' => $name]);
        $place->family_id = $family->id;
        $place->save();

        return $place;
    }
}
