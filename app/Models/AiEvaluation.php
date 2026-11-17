<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** One run of the AI evaluation for an agency (ai:evaluate, "Uji AI saya", after a knowledge change, nightly). */
#[Fillable(['trigger', 'passed', 'total', 'results', 'knowledge_version'])]
class AiEvaluation extends Model
{
    use BelongsToTenant;

    public const TRIGGERS = ['manual' => 'Manual', 'quick' => 'Uji AI saya', 'knowledge' => 'Pengetahuan berubah', 'nightly' => 'Tiap malam'];

    protected function casts(): array
    {
        return ['results' => 'array'];
    }

    /** Share of scenarios passed, 0 to 100. */
    public function score(): int
    {
        return $this->total > 0 ? (int) round($this->passed * 100 / $this->total) : 0;
    }
}
