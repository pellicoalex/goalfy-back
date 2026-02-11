<?php

namespace App\Models;

use App\Traits\WithValidate;

class Team extends BaseModel
{
    use WithValidate;

    public ?int $id = null;
    public ?string $name = null;

    public ?string $logo_url = null;

    public ?string $created_at = null;
    public ?string $updated_at = null;
    public ?string $deleted_at = null;

    protected static ?string $table = "teams";

    public function __construct(array $data = [])
    {
        parent::__construct($data);
    }

    protected static function validationRules(): array
    {
        return [
            "name" => ["required", "min:1", "max:150"],

            //  opzionale (valida solo se presente)
            "logo_url" => ["sometimes", "min:1", "max:500"],
        ];
    }

    /**
     * Relazione: Team hasMany Players
     */
    protected function players(): array
    {
        // foreign key esplicita: team_id
        return $this->hasMany(Player::class, 'team_id');
    }
}
