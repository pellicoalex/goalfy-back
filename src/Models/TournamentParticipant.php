<?php

namespace App\Models;

use App\Traits\WithValidate;
use App\Traits\HasRelations;

class TournamentParticipant extends BaseModel
{
    use WithValidate, HasRelations;

    protected static ?string $table = 'tournament_participants';

    public ?int $tournament_id = null;
    public ?int $team_id = null;
    public ?int $seed = null;
    public ?string $created_at = null;

    protected static function validationRules(): array
    {
        return [
            'tournament_id' => ['required'],
            'team_id' => ['required'],
            'seed' => ['sometimes'],
        ];
    }

    public function tournament()
    {
        return $this->belongsTo(Tournament::class, 'tournament_id');
    }

    public function team()
    {
        return $this->belongsTo(Team::class, 'team_id');
    }
}
