<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ExamRecord extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'exam_paper_id',
        'start_time',
        'end_time',
        'score',
        'status',
    ];

    protected $casts = [
        'user_id' => 'integer',
        'exam_paper_id' => 'integer',
        'start_time' => 'datetime',
        'end_time' => 'datetime',
        'score' => 'decimal:2',
        'status' => 'string',
    ];

    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_SUBMITTED = 'submitted';
    public const STATUS_GRADED = 'graded';

    public const STATUSES = [
        self::STATUS_IN_PROGRESS => '进行中',
        self::STATUS_SUBMITTED => '已提交',
        self::STATUS_GRADED => '已评分',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function examPaper()
    {
        return $this->belongsTo(ExamPaper::class, 'exam_paper_id');
    }

    public function answers()
    {
        return $this->hasMany(ExamRecordAnswer::class, 'exam_record_id');
    }

    public function essayGradings()
    {
        return $this->hasMany(EssayGrading::class, 'exam_record_id');
    }

    /**
     * 重算该场考试总分：客观题取答案表，主观题取分层批阅的当前生效分数。
     * 仅当全部主观题定稿后才置为 graded，否则保持 submitted。
     */
    public function recalculateScore(): array
    {
        $this->loadMissing(['answers.question', 'answers.essayGrading']);

        $total = 0.0;
        $hasEssay = false;
        $allEssayFinalized = true;

        foreach ($this->answers as $answer) {
            if ($answer->question && $answer->question->type === Question::TYPE_ESSAY) {
                $hasEssay = true;
                $grading = $answer->essayGrading;
                if (!$grading || $grading->status !== EssayGrading::STATUS_FINALIZED) {
                    $allEssayFinalized = false;
                }
                if ($grading) {
                    $score = $grading->effectiveScore();
                    $answer->score = $score;
                    $answer->save();
                    $total += $score;
                }
            } else {
                $total += (float) $answer->score;
            }
        }

        $this->score = round($total, 2);

        if (!$hasEssay || $allEssayFinalized) {
            $this->status = self::STATUS_GRADED;
        } else {
            $this->status = self::STATUS_SUBMITTED;
        }

        $this->save();

        return ['score' => $this->score, 'status' => $this->status];
    }
}
