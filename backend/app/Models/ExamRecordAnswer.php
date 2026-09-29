<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ExamRecordAnswer extends Model
{
    use HasFactory;

    protected $fillable = [
        'exam_record_id',
        'question_id',
        'answer',
        'is_correct',
        'score',
        'grading_status',
        'review_flag',
        'student_comment',
    ];

    protected $casts = [
        'exam_record_id' => 'integer',
        'question_id' => 'integer',
        'is_correct' => 'boolean',
        'score' => 'decimal:2',
    ];

    public const GRADING_NONE = 'none';
    public const GRADING_PENDING = 'pending';
    public const GRADING_INITIAL_GRADED = 'initial_graded';
    public const GRADING_FINALIZED = 'finalized';

    public const GRADING_STATUSES = [
        self::GRADING_PENDING => '待初评',
        self::GRADING_INITIAL_GRADED => '待复核',
        self::GRADING_FINALIZED => '已定稿',
    ];

    public const FLAG_NONE = 'none';
    public const FLAG_DISPUTED = 'disputed';
    public const FLAG_HIGH_SCORE = 'high_score';

    public function examRecord()
    {
        return $this->belongsTo(ExamRecord::class, 'exam_record_id');
    }

    public function question()
    {
        return $this->belongsTo(Question::class, 'question_id');
    }

    public function gradingRecords()
    {
        return $this->hasMany(GradingRecord::class, 'exam_record_answer_id');
    }

    public function latestGradingRecord()
    {
        return $this->hasOne(GradingRecord::class, 'exam_record_answer_id')->latestOfMany();
    }
}
