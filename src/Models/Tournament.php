<?php

namespace App\Models;

use App\Traits\WithValidate;
use App\Traits\HasRelations;

class Tournament extends BaseModel
{
    use WithValidate, HasRelations;

    protected static ?string $table = 'tournaments';

    // public ?int $id = null;
    public ?string $name = null;
    public ?string $start_date = null; // timestamptz
    public ?string $status = null;     // draft|ongoing|completed stessa cosa per lo stato della competizione
    public ?int $winner_team_id = null;
    public ?string $created_at = null;
    public ?string $updated_at = null;

    protected static function validationRules(): array
    {
        return [
            'name' => ['required', 'min:1', 'max:120'],
            'start_date' => ['required'],
            'status' => ['sometimes'],
            'winner_team_id' => ['sometimes'],
        ];
    }

    public function participants()
    {
        return $this->hasMany(TournamentParticipant::class, 'tournament_id'); //relazione uno a molti
    }

    public function matches()
    {
        return $this->hasMany(MatchModel::class, 'tournament_id');
    }

    public function winner()
    {
        return $this->belongsTo(Team::class, 'winner_team_id');
    }
}
