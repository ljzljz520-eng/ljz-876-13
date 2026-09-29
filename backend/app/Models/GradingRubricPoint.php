<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GradingRubricPoint extends Model
{
    protected $fillable = [
        'question_id',
        'title',
        'description',
        'score',
        'sort_order',
    ];

    protected $casts = [
        'question_id' => 'integer',
        'score' => 'decimal:2',
        'sort_order' => 'integer',
    ];

    public function question()
    {
        return $this->belongsTo(Question::class, 'question_id');
    }
}
