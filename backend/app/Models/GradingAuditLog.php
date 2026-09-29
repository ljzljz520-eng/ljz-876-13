<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GradingAuditLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'essay_grading_id',
        'action',
        'operator_id',
        'score_before',
        'score_after',
        'reason',
        'detail',
        'created_at',
    ];

    protected $casts = [
        'essay_grading_id' => 'integer',
        'operator_id' => 'integer',
        'score_before' => 'decimal:2',
        'score_after' => 'decimal:2',
        'detail' => 'array',
        'created_at' => 'datetime',
    ];

    public const ACTION_INITIAL_SUBMIT = 'initial_submit';
    public const ACTION_AUTO_HIGH_SAMPLE = 'auto_high_sample';
    public const ACTION_REVIEW_CONFIRM = 'review_confirm';
    public const ACTION_REVIEW_ADJUST = 'review_adjust';
    public const ACTION_FLAG_DISPUTE = 'flag_dispute';
    public const ACTION_REMOVE_DISPUTE = 'remove_dispute';
    public const ACTION_FLAG_SAMPLE = 'flag_sample';
    public const ACTION_REMOVE_SAMPLE = 'remove_sample';
    public const ACTION_INTERNAL_COMMENT = 'internal_comment';

    public const ACTION_LABELS = [
        self::ACTION_INITIAL_SUBMIT => '初评提交',
        self::ACTION_AUTO_HIGH_SAMPLE => '自动标记高分样卷',
        self::ACTION_REVIEW_CONFIRM => '复核确认',
        self::ACTION_REVIEW_ADJUST => '复核改分',
        self::ACTION_FLAG_DISPUTE => '标记争议题',
        self::ACTION_REMOVE_DISPUTE => '撤销争议标记',
        self::ACTION_FLAG_SAMPLE => '标记高分样卷',
        self::ACTION_REMOVE_SAMPLE => '撤销高分样卷',
        self::ACTION_INTERNAL_COMMENT => '内部讨论',
    ];

    public function operator()
    {
        return $this->belongsTo(User::class, 'operator_id');
    }

    public function grading()
    {
        return $this->belongsTo(EssayGrading::class, 'essay_grading_id');
    }
}
