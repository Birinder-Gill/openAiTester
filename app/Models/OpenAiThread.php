<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OpenAiThread extends Model
{
    protected $fillable = ['threadId', 'from'];

    use HasFactory;
}
