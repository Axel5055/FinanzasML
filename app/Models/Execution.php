<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Execution extends Model
{
    protected $fillable = ['function_name', 'user_id', 'last_executed_at'];
}
