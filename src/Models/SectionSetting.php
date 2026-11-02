<?php

namespace RatingsAndReviews\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $section
 * @property array<string, mixed> $settings
 * @property int|null $updated_by
 */
class SectionSetting extends Model
{
    protected $table = 'rr_section_settings';

    protected $fillable = ['section', 'settings', 'updated_by'];

    protected function casts(): array
    {
        return ['settings' => 'array', 'updated_by' => 'integer'];
    }
}
