<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OpenAiMessageTrack extends Model
{
    protected $fillable = ['threadId', 'message'];

    use HasFactory;
}
