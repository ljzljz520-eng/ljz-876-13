<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EssayGrading extends Model
{
    protected $fillable = [
        'exam_record_answer_id',
        'exam_record_id',
        'question_id',
        'status',
        'initial_grader_id',
        'initial_score',
        'initial_comment',
        'initial_gradeds_at',
        'is_high_score_sample',
        'is_disputed',
        'reviewer_id',
        'final_score',
        'final_comment',
        'reviewed_at',
    ];

    protected $casts = [
        'exam_record_answer_id' => 'integer',
        'exam_record_id' => 'integer',
        'question_id' => 'integer',
        'initial_grader_id' => 'integer',
        'initial_score' => 'decimal:2',
        'is_high_score_sample' => 'boolean',
        'is_disputed' => 'boolean',
        'reviewer_id' => 'integer',
        'final_score' => 'decimal:2',
        'initial_gradeds_at' => 'datetime',
        'reviewed_at' => 'datetime',
    ];

    // 待初评
    public const STATUS_PENDING = 'pending';
    // 待复核（争议题 / 高分样卷）
    public const STATUS_REVIEW_PENDING = 'review_pending';
    // 已定稿
    public const STATUS_FINALIZED = 'finalized';

    public const STATUSES = [
        self::STATUS_PENDING => '待初评',
        self::STATUS_REVIEW_PENDING => '待复核',
        self::STATUS_FINALIZED => '已定稿',
    ];

    public function answer()
    {
        return $this->belongsTo(ExamRecordAnswer::class, 'exam_record_answer_id');
    }

    public function examRecord()
    {
        return $this->belongsTo(ExamRecord::class, 'exam_record_id');
    }

    public function question()
    {
        return $this->belongsTo(Question::class, 'question_id');
    }

    public function initialGrader()
    {
        return $this->belongsTo(User::class, 'initial_grader_id');
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }

    public function points()
    {
        return $this->hasMany(EssayGradingPoint::class, 'essay_grading_id');
    }

    public function auditLogs()
    {
        return $this->hasMany(GradingAuditLog::class, 'essay_grading_id')->orderBy('id');
    }

    public function comments()
    {
        return $this->hasMany(GradingComment::class, 'essay_grading_id')->orderBy('id');
    }

    public function internalComments()
    {
        return $this->comments()->where('visibility', GradingComment::VISIBILITY_INTERNAL);
    }

    public function studentComments()
    {
        return $this->comments()->where('visibility', GradingComment::VISIBILITY_STUDENT);
    }

    /**
     * 当前生效分数：已定稿取复核分，否则取初评分，未评为 0。
     */
    public function effectiveScore(): float
    {
        if ($this->status === self::STATUS_FINALIZED) {
            return (float) ($this->final_score ?? 0);
        }

        return (float) ($this->initial_score ?? 0);
    }
}
