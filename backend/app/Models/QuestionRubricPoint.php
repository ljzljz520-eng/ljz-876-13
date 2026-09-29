<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class QuestionRubricPoint extends Model
{
    use HasFactory;

    protected $fillable = [
        'question_id',
        'title',
        'max_score',
        'sort_order',
    ];

    protected $casts = [
        'question_id' => 'integer',
        'max_score' => 'decimal:2',
        'sort_order' => 'integer',
    ];

    public function question()
    {
        return $this->belongsTo(Question::class, 'question_id');
    }
}
