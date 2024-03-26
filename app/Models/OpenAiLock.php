<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OpenAiLock extends Model
{
    protected $fillable = ['threadId'];

    use HasFactory;
}
