<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StatusCuenta extends Model
{
    /** @use HasFactory<Factory<StatusCuenta>> */
    use HasFactory;

    protected $fillable = [
        'name',
    ];
}
