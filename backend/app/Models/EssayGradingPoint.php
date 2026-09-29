<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EssayGradingPoint extends Model
{
    protected $fillable = [
        'essay_grading_id',
        'rubric_point_id',
        'initial_score',
        'final_score',
    ];

    protected $casts = [
        'essay_grading_id' => 'integer',
        'rubric_point_id' => 'integer',
        'initial_score' => 'decimal:2',
        'final_score' => 'decimal:2',
    ];

    public function grading()
    {
        return $this->belongsTo(EssayGrading::class, 'essay_grading_id');
    }

    public function rubricPoint()
    {
        return $this->belongsTo(GradingRubricPoint::class, 'rubric_point_id');
    }
}
