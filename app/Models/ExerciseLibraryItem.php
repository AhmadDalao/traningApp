<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExerciseLibraryItem extends Model
{
    protected $fillable = ['user_id', 'name', 'focus_area', 'icon', 'thumbnail_path', 'custom_icon_path'];
}
