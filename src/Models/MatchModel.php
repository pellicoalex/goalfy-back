<?php

namespace App\Models;

use App\Traits\HasRelations;

class MatchModel extends BaseModel
{
    use HasRelations;

    protected static ?string $table = 'matches';

    public ?int $id = null;
    public ?int $tournament_id = null;

    public ?int $round = null;        // 1=QF,2=SF,3=F
    public ?int $match_number = null; // progressivo nel round
    public ?string $status = null;    // waiting|scheduled|played

    public ?int $team_a_id = null;
    public ?int $team_b_id = null;

    public ?int $score_a = null;
    public ?int $score_b = null;

    public ?int $winner_team_id = null;

    public ?int $next_match_id = null;  //link automatico al match seccessivo
    public ?string $next_slot = null; // A e B

    public ?string $created_at = null;
    public ?string $updated_at = null;

    public function tournament()
    {
        return $this->belongsTo(Tournament::class, 'tournament_id'); //relazione molti a uno
    }

    public function teamA()
    {
        return $this->belongsTo(Team::class, 'team_a_id');
    }

    public function teamB()
    {
        return $this->belongsTo(Team::class, 'team_b_id');
    }

    public function winner()
    {
        return $this->belongsTo(Team::class, 'winner_team_id');
    }

    public function nextMatch()
    {
        return $this->belongsTo(MatchModel::class, 'next_match_id');
    }
}
