<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Integration extends Model
{
    protected $fillable = [
        'name',
        'description',
        'status',
        'endpoint',
        'primary_action',
        'secondary_action',
    ];
}
