<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CoachPairingToken extends Model
{
    protected $fillable = ['trainee_user_id', 'token_hash', 'expires_at', 'used_at'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'used_at' => 'datetime'];
    }

    public function trainee()
    {
        return $this->belongsTo(User::class, 'trainee_user_id');
    }
}
