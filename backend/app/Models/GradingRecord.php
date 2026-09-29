<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GradingRecord extends Model
{
    use HasFactory;

    public const STAGE_INITIAL = 'initial';
    public const STAGE_REVIEW = 'review';

    public const UPDATED_AT = null;

    protected $fillable = [
        'exam_record_answer_id',
        'grader_id',
        'stage',
        'total_score',
        'comment',
        'internal_note',
        'reason',
        'review_flag',
    ];

    protected $casts = [
        'exam_record_answer_id' => 'integer',
        'grader_id' => 'integer',
        'total_score' => 'decimal:2',
    ];

    public function answer()
    {
        return $this->belongsTo(ExamRecordAnswer::class, 'exam_record_answer_id');
    }

    public function grader()
    {
        return $this->belongsTo(User::class, 'grader_id');
    }

    public function points()
    {
        return $this->hasMany(GradingRecordPoint::class, 'grading_record_id');
    }
}
