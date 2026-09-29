<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GradingRecordPoint extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = [
        'grading_record_id',
        'rubric_point_id',
        'score',
    ];

    protected $casts = [
        'grading_record_id' => 'integer',
        'rubric_point_id' => 'integer',
        'score' => 'decimal:2',
    ];

    public function gradingRecord()
    {
        return $this->belongsTo(GradingRecord::class, 'grading_record_id');
    }

    public function rubricPoint()
    {
        return $this->belongsTo(QuestionRubricPoint::class, 'rubric_point_id');
    }
}
