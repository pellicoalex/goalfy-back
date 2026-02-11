<?php

namespace App\Models;

use App\Traits\WithValidate;

class Player extends BaseModel
{
    use WithValidate;

    public ?int $id = null;
    public ?int $team_id = null;  //squadra di appartenenza

    public ?string $first_name = null;
    public ?string $last_name = null;
    public ?int $number = null;

    // avatar
    public ?string $avatar_url = null;

    // campi anagrafici
    public ?string $nationality = null;
    public ?string $role = null;           // GOALKEEPER | FIXO | ALA | PIVO | UNIVERSAL ruoli futsal
    public ?int $height_cm = null;
    public ?int $weight_kg = null;
    public ?string $birth_date = null;

    public ?string $created_at = null;
    public ?string $updated_at = null;

    protected static ?string $table = "players";

    public function __construct(array $data = [])
    {
        parent::__construct($data);
    }

    /**
     * Regole di validazione
     */
    protected static function validationRules(): array
    {
        return [
            // obbligatori
            "team_id" => ["required"],
            "first_name" => ["required", "min:1", "max:100"],
            "last_name" => ["required", "min:1", "max:100"],

            // opzionali 
            "number" => ["sometimes"],

            "avatar_url" => ["sometimes", "min:1", "max:500"],

            "nationality" => ["sometimes", "min:2", "max:50"],
            "role" => ["sometimes", "min:2", "max:50"],

            "height_cm" => ["sometimes"],
            "weight_kg" => ["sometimes"],

            "birth_date" => ["sometimes"],
        ];
    }

    /**
     * Nome completo 
     */
    public function fullName(): string
    {
        return trim(($this->first_name ?? '') . ' ' . ($this->last_name ?? ''));
    }

    /**
     * Relazione: Player belongsTo Team
     */
    protected function team()
    {
        return $this->belongsTo(Team::class, 'team_id');
    }
}
