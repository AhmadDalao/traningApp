<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BodyWeightEntry extends Model
{
    protected $fillable = ['weight_kg', 'recorded_date', 'note'];

    protected function casts(): array
    {
        return ['weight_kg' => 'decimal:2', 'recorded_date' => 'date:Y-m-d'];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
