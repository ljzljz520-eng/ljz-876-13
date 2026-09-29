<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EssayGrading;
use App\Models\EssayGradingPoint;
use App\Models\ExamPaper;
use App\Models\ExamRecord;
use App\Models\GradingAuditLog;
use App\Models\GradingComment;
use App\Models\GradingRubricPoint;
use App\Models\Question;
use App\Models\SchoolClass;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class GradingController extends Controller
{
    // 高分样卷阈值：初评得分 >= 满分 * 该比例 自动进入复核
    private const HIGH_SAMPLE_RATIO = 0.9;

    /* ==================== 老师：批阅工作台 ==================== */

    /**
     * 含主观题的试卷列表 + 每卷批阅概况
     */
    public function papers(Request $request)
    {
        $user = $request->user();
        if (!$user->canInitialGrade() && !$user->canReview()) {
            return response()->json(['message' => '无权访问'], 403);
        }

        $papers = ExamPaper::where('status', 1)
            ->whereHas('questions', fn($q) => $q->where('type', Question::TYPE_ESSAY))
            ->orderBy('id', 'desc')
            ->get();

        $list = $papers->map(function ($paper) {
            $recordIds = ExamRecord::where('exam_paper_id', $paper->id)->pluck('id');
            $gradings = EssayGrading::whereIn('exam_record_id', $recordIds)->get();

            return [
                'id' => $paper->id,
                'title' => $paper->title,
                'total_score' => $paper->total_score,
                'question_count' => $paper->question_count,
                'essay_question_count' => $paper->questions()->where('type', Question::TYPE_ESSAY)->count(),
                'grading_summary' => [
                    'total' => $gradings->count(),
                    'pending' => $gradings->where('status', EssayGrading::STATUS_PENDING)->count(),
                    'review_pending' => $gradings->where('status', EssayGrading::STATUS_REVIEW_PENDING)->count(),
                    'finalized' => $gradings->where('status', EssayGrading::STATUS_FINALIZED)->count(),
                    'disputed' => $gradings->where('is_disputed', 1)->count(),
                    'high_score_sample' => $gradings->where('is_high_score_sample', 1)->count(),
                ],
            ];
        });

        return response()->json(['papers' => $list]);
    }

    /**
     * 批阅队列：按题目/状态/班级筛选
     */
    public function queue(Request $request, ExamPaper $examPaper)
    {
        $user = $request->user();
        if (!$user->canInitialGrade() && !$user->canReview()) {
            return response()->json(['message' => '无权访问'], 403);
        }

        $validator = Validator::make($request->all(), [
            'question_id' => 'nullable|exists:questions,id',
            'class_id' => 'nullable|exists:classes,id',
            'status' => 'nullable|in:pending,review_pending,finalized',
            'is_disputed' => 'nullable|boolean',
            'is_high_score_sample' => 'nullable|boolean',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $essayQuestionIds = $examPaper->questions()
            ->where('type', Question::TYPE_ESSAY)
            ->pluck('questions.id')->all();

        $query = EssayGrading::query()
            ->with([
                'question:id,title,score,type',
                'initialGrader:id,username,real_name',
                'reviewer:id,username,real_name',
                'examRecord.user.classes',
                'examRecord.examPaper:id,title',
                'answer:id,exam_record_id,score',
            ])
            ->whereIn('question_id', $essayQuestionIds)
            ->whereHas('examRecord', fn($q) => $q->where('exam_paper_id', $examPaper->id));

        // 初评老师默认只看待初评/待复核；复核老师默认看待复核
        if (!$request->filled('status')) {
            $query->where('status', '!=', EssayGrading::STATUS_FINALIZED);
        }

        if ($request->filled('question_id')) {
            $query->where('question_id', $request->input('question_id'));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }
        if ($request->filled('is_disputed')) {
            $query->where('is_disputed', (int) $request->input('is_disputed'));
        }
        if ($request->filled('is_high_score_sample')) {
            $query->where('is_high_score_sample', (int) $request->input('is_high_score_sample'));
        }
        if ($request->filled('class_id')) {
            $classId = (int) $request->input('class_id');
            $studentIds = DB::table('class_student')->where('class_id', $classId)->pluck('user_id');
            $query->whereHas('examRecord', fn($q) => $q->whereIn('user_id', $studentIds));
        }

        $query->orderByRaw("FIELD(status, 'pending', 'review_pending', 'finalized')")
            ->orderBy('id');

        $paginated = $query->paginate($request->input('per_page', 15));

        // 全卷主观题概况（用于进度统计概览）
        $allGradings = EssayGrading::whereIn('question_id', $essayQuestionIds)
            ->whereHas('examRecord', fn($q) => $q->where('exam_paper_id', $examPaper->id))
            ->get();

        $summary = [
            'total' => $allGradings->count(),
            'pending' => $allGradings->where('status', EssayGrading::STATUS_PENDING)->count(),
            'review_pending' => $allGradings->where('status', EssayGrading::STATUS_REVIEW_PENDING)->count(),
            'finalized' => $allGradings->where('status', EssayGrading::STATUS_FINALIZED)->count(),
            'disputed' => $allGradings->where('is_disputed', 1)->count(),
            'high_score_sample' => $allGradings->where('is_high_score_sample', 1)->count(),
        ];

        $items = collect($paginated->items())->map(fn($g) => $this->formatGradingListItem($g));

        return response()->json([
            'queue' => [
                'data' => $items,
                'current_page' => $paginated->currentPage(),
                'last_page' => $paginated->lastPage(),
                'total' => $paginated->total(),
            ],
            'summary' => $summary,
        ]);
    }

    /**
     * 批阅详情：含考生答案、评分点、审计轨迹、内部讨论（仅老师）
     */
    public function show(Request $request, EssayGrading $grading)
    {
        $user = $request->user();
        if (!$user->canInitialGrade() && !$user->canReview()) {
            return response()->json(['message' => '无权访问'], 403);
        }

        $grading->load([
            'question.rubricPoints',
            'answer',
            'examRecord.user.classes',
            'initialGrader:id,username,real_name',
            'reviewer:id,username,real_name',
            'points',
        ]);

        $fullScore = $this->answerFullScore($grading);

        return response()->json([
            'grading' => $this->formatGradingDetail($grading, $fullScore),
        ]);
    }

    /**
     * 初评：按评分点给分 + 简短评语 + 可选争议/样卷标记
     */
    public function initialSubmit(Request $request, EssayGrading $grading)
    {
        $user = $request->user();
        if (!$user->canInitialGrade()) {
            return response()->json(['message' => '只有阅卷老师可以进行初评'], 403);
        }

        $rubricPoints = GradingRubricPoint::where('question_id', $grading->question_id)
            ->orderBy('sort_order')->get();

        $validator = Validator::make($request->all(), [
            'points' => 'required|array|min:1',
            'points.*.rubric_point_id' => 'required|exists:grading_rubric_points,id',
            'points.*.score' => 'required|numeric|min:0',
            'comment' => 'nullable|string|max:1000',
            'mark_disputed' => 'nullable|boolean',
            'dispute_reason' => 'required_if:mark_disputed,true|string|max:1000',
            'mark_high_sample' => 'nullable|boolean',
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // 每个评分点得分不得超过该点满分
        $maxMap = $rubricPoints->keyBy('id');
        $total = 0.0;
        $pointSnapshot = [];
        foreach ($request->input('points') as $p) {
            $rubric = $maxMap->get($p['rubric_point_id']);
            if (!$rubric) {
                return response()->json(['message' => '评分点不属于该题目'], 422);
            }
            if ((float) $p['score'] > (float) $rubric->score + 0.0001) {
                return response()->json([
                    'message' => "评分点「{$rubric->title}」得分不能超过 {$rubric->score} 分",
                ], 422);
            }
            $total += (float) $p['score'];
            $pointSnapshot[] = [
                'rubric_point_id' => $rubric->id,
                'title' => $rubric->title,
                'full_score' => (float) $rubric->score,
                'score' => (float) $p['score'],
            ];
        }

        $fullScore = $this->answerFullScore($grading);
        $total = round($total, 2);

        DB::transaction(function () use ($grading, $request, $user, $total, $pointSnapshot) {
            $scoreBefore = $grading->initial_score;

            $grading->initial_grader_id = $user->id;
            $grading->initial_score = $total;
            $grading->initial_comment = $request->input('comment');
            $grading->initial_gradeds_at = now();
            $grading->final_score = null;
            $grading->final_comment = null;
            $grading->reviewer_id = null;
            $grading->reviewed_at = null;
            $grading->is_disputed = $request->boolean('mark_disputed') ? 1 : 0;

            // 高分样卷：自动（达到阈值）或手动标记
            $autoSample = $this->isHighScore($grading, $total);
            $manualSample = $request->boolean('mark_high_sample');
            $grading->is_high_score_sample = ($autoSample || $manualSample) ? 1 : 0;

            $needsReview = $grading->is_disputed || $grading->is_high_score_sample;
            $grading->status = $needsReview
                ? EssayGrading::STATUS_REVIEW_PENDING
                : EssayGrading::STATUS_FINALIZED;

            if ($grading->status === EssayGrading::STATUS_FINALIZED) {
                $grading->final_score = $total;
                $grading->final_comment = $request->input('comment');
                $grading->reviewer_id = $user->id;
                $grading->reviewed_at = now();
            }

            $grading->save();

            // 评分点得分 upsert
            foreach ($request->input('points') as $p) {
                EssayGradingPoint::updateOrCreate(
                    ['essay_grading_id' => $grading->id, 'rubric_point_id' => $p['rubric_point_id']],
                    [
                        'initial_score' => $p['score'],
                        'final_score' => $grading->status === EssayGrading::STATUS_FINALIZED ? $p['score'] : null,
                    ]
                );
            }

            // 审计：初评提交
            GradingAuditLog::create([
                'essay_grading_id' => $grading->id,
                'action' => GradingAuditLog::ACTION_INITIAL_SUBMIT,
                'operator_id' => $user->id,
                'score_before' => $scoreBefore,
                'score_after' => $total,
                'reason' => '阅卷老师按评分点完成初评',
                'detail' => ['points' => $pointSnapshot, 'comment' => $request->input('comment')],
            ]);

            // 审计：争议标记
            if ($request->boolean('mark_disputed')) {
                GradingAuditLog::create([
                    'essay_grading_id' => $grading->id,
                    'action' => GradingAuditLog::ACTION_FLAG_DISPUTE,
                    'operator_id' => $user->id,
                    'score_before' => $total,
                    'score_after' => $total,
                    'reason' => $request->input('dispute_reason'),
                ]);
            }

            // 审计：自动/手动高分样卷
            if ($autoSample) {
                GradingAuditLog::create([
                    'essay_grading_id' => $grading->id,
                    'action' => GradingAuditLog::ACTION_AUTO_HIGH_SAMPLE,
                    'operator_id' => $user->id,
                    'score_before' => $total,
                    'score_after' => $total,
                    'reason' => '初评得分达到高分样卷阈值（≥90% 满分），自动提交复核',
                    'detail' => ['ratio' => self::HIGH_SAMPLE_RATIO],
                ]);
            } elseif ($manualSample) {
                GradingAuditLog::create([
                    'essay_grading_id' => $grading->id,
                    'action' => GradingAuditLog::ACTION_FLAG_SAMPLE,
                    'operator_id' => $user->id,
                    'score_before' => $total,
                    'score_after' => $total,
                    'reason' => '阅卷老师手动标记为高分样卷，提交复核',
                ]);
            }

            // 同步考生答案分数并重算整卷
            $grading->examRecord->recalculateScore();
        });

        $grading->load(['points', 'question.rubricPoints', 'initialGrader:id,username,real_name']);

        return response()->json([
            'message' => $grading->status === EssayGrading::STATUS_REVIEW_PENDING
                ? '初评已提交，等待复核老师复核'
                : '初评完成，该题已定稿',
            'grading' => $this->formatGradingDetail($grading, $fullScore ?? $this->answerFullScore($grading)),
        ]);
    }

    /* ==================== 复核老师 ==================== */

    /**
     * 复核队列：只有争议题 + 高分样卷（status = review_pending）
     */
    public function reviewQueue(Request $request)
    {
        $user = $request->user();
        if (!$user->canReview()) {
            return response()->json(['message' => '只有复核老师可以访问'], 403);
        }

        $validator = Validator::make($request->all(), [
            'exam_paper_id' => 'nullable|exists:exam_papers,id',
            'queue_type' => 'nullable|in:all,disputed,high_score_sample',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $query = EssayGrading::query()
            ->with([
                'question:id,title,score',
                'initialGrader:id,username,real_name',
                'examRecord.user.classes',
                'examRecord.examPaper:id,title',
            ])
            ->where('status', EssayGrading::STATUS_REVIEW_PENDING);

        if ($request->input('queue_type') === 'disputed') {
            $query->where('is_disputed', 1);
        } elseif ($request->input('queue_type') === 'high_score_sample') {
            $query->where('is_high_score_sample', 1);
        }
        if ($request->filled('exam_paper_id')) {
            $query->whereHas('examRecord', fn($q) => $q->where('exam_paper_id', $request->input('exam_paper_id')));
        }

        $query->orderByDesc('is_disputed')->orderBy('id');
        $paginated = $query->paginate($request->input('per_page', 15));

        $items = collect($paginated->items())->map(fn($g) => $this->formatGradingListItem($g));

        return response()->json([
            'queue' => [
                'data' => $items,
                'current_page' => $paginated->currentPage(),
                'last_page' => $paginated->lastPage(),
                'total' => $paginated->total(),
            ],
            'counts' => [
                'all' => EssayGrading::where('status', EssayGrading::STATUS_REVIEW_PENDING)->count(),
                'disputed' => EssayGrading::where('status', EssayGrading::STATUS_REVIEW_PENDING)->where('is_disputed', 1)->count(),
                'high_score_sample' => EssayGrading::where('status', EssayGrading::STATUS_REVIEW_PENDING)->where('is_high_score_sample', 1)->count(),
            ],
        ]);
    }

    /**
     * 复核：确认初评 或 调整分数（调整必须填写原因）
     */
    public function reviewSubmit(Request $request, EssayGrading $grading)
    {
        $user = $request->user();
        if (!$user->canReview()) {
            return response()->json(['message' => '只有复核老师可以复核'], 403);
        }

        if ($grading->status !== EssayGrading::STATUS_REVIEW_PENDING) {
            return response()->json(['message' => '该题当前不在待复核状态'], 422);
        }
        if ($grading->initial_score === null) {
            return response()->json(['message' => '该题尚未完成初评，无法复核'], 422);
        }

        $validator = Validator::make($request->all(), [
            'decision' => 'required|in:confirm,adjust',
            'reason' => 'required|string|max:1000',
            'points' => 'required_if:decision,adjust|array',
            'points.*.rubric_point_id' => 'required_with:points|exists:grading_rubric_points,id',
            'points.*.score' => 'required_with:points|numeric|min:0',
            'final_comment' => 'nullable|string|max:1000',
            'resolve_dispute' => 'nullable|boolean',
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $rubricPoints = GradingRubricPoint::where('question_id', $grading->question_id)->get()->keyBy('id');

        if ($request->input('decision') === 'adjust') {
            foreach ($request->input('points', []) as $p) {
                $rubric = $rubricPoints->get($p['rubric_point_id']);
                if (!$rubric) {
                    return response()->json(['message' => '评分点不属于该题目'], 422);
                }
                if ((float) $p['score'] > (float) $rubric->score + 0.0001) {
                    return response()->json([
                        'message' => "评分点「{$rubric->title}」得分不能超过 {$rubric->score} 分",
                    ], 422);
                }
            }
        }

        DB::transaction(function () use ($grading, $request, $user, $rubricPoints) {
            $scoreBefore = (float) $grading->initial_score;
            $pointRows = $grading->points()->get()->keyBy('rubric_point_id');

            $finalScores = [];
            $finalTotal = $scoreBefore;

            if ($request->input('decision') === 'confirm') {
                // 维持初评
                foreach ($pointRows as $rubricId => $row) {
                    $row->final_score = $row->initial_score;
                    $row->save();
                    $rubric = $rubricPoints->get($rubricId);
                    $finalScores[] = [
                        'rubric_point_id' => $rubricId,
                        'title' => $rubric->title ?? '',
                        'full_score' => (float) ($rubric->score ?? 0),
                        'score' => (float) $row->initial_score,
                    ];
                }
                $finalTotal = round($scoreBefore, 2);
            } else {
                // 调整：逐评分点给分
                $finalTotal = 0.0;
                $submitted = collect($request->input('points'))->keyBy('rubric_point_id');
                foreach ($pointRows as $rubricId => $row) {
                    $newScore = $submitted->has($rubricId) ? (float) $submitted[$rubricId]['score'] : (float) $row->initial_score;
                    $rubric = $rubricPoints->get($rubricId);
                    if ($rubric && $newScore > (float) $rubric->score + 0.0001) {
                        throw new \InvalidArgumentException("评分点「{$rubric->title}」得分不能超过 {$rubric->score} 分");
                    }
                    $row->final_score = $newScore;
                    $row->save();
                    $finalTotal += $newScore;
                    $finalScores[] = [
                        'rubric_point_id' => $rubricId,
                        'title' => $rubric->title ?? '',
                        'full_score' => (float) ($rubric->score ?? 0),
                        'score' => $newScore,
                    ];
                }
                $finalTotal = round($finalTotal, 2);
            }

            $grading->final_score = $finalTotal;
            $grading->final_comment = $request->input('final_comment', $grading->initial_comment);
            $grading->reviewer_id = $user->id;
            $grading->reviewed_at = now();
            $grading->status = EssayGrading::STATUS_FINALIZED;
            if ($request->boolean('resolve_dispute', true)) {
                $grading->is_disputed = 0;
            }
            $grading->save();

            GradingAuditLog::create([
                'essay_grading_id' => $grading->id,
                'action' => $request->input('decision') === 'confirm'
                    ? GradingAuditLog::ACTION_REVIEW_CONFIRM
                    : GradingAuditLog::ACTION_REVIEW_ADJUST,
                'operator_id' => $user->id,
                'score_before' => $scoreBefore,
                'score_after' => $finalTotal,
                'reason' => $request->input('reason'),
                'detail' => [
                    'points' => $finalScores,
                    'final_comment' => $grading->final_comment,
                ],
            ]);

            $grading->examRecord->recalculateScore();
        });

        $grading->load(['points', 'question.rubricPoints', 'reviewer:id,username,real_name']);

        return response()->json([
            'message' => '复核完成，最终分数已定稿',
            'grading' => $this->formatGradingDetail($grading, $this->answerFullScore($grading)),
        ]);
    }

    /* ==================== 内部讨论 & 标记（老师侧） ==================== */

    /**
     * 新增评论：internal(老师内部讨论，学生不可见) / student(对学生的简短评语)
     */
    public function storeComment(Request $request, EssayGrading $grading)
    {
        $user = $request->user();
        if (!$user->canInitialGrade() && !$user->canReview()) {
            return response()->json(['message' => '无权操作'], 403);
        }

        $validator = Validator::make($request->all(), [
            'content' => 'required|string|max:1000',
            'visibility' => 'required|in:internal,student',
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $comment = GradingComment::create([
            'essay_grading_id' => $grading->id,
            'author_id' => $user->id,
            'content' => $request->input('content'),
            'visibility' => $request->input('visibility'),
        ]);

        if ($comment->visibility === GradingComment::VISIBILITY_INTERNAL) {
            GradingAuditLog::create([
                'essay_grading_id' => $grading->id,
                'action' => GradingAuditLog::ACTION_INTERNAL_COMMENT,
                'operator_id' => $user->id,
                'score_before' => $grading->status === EssayGrading::STATUS_FINALIZED ? $grading->final_score : $grading->initial_score,
                'score_after' => $grading->status === EssayGrading::STATUS_FINALIZED ? $grading->final_score : $grading->initial_score,
                'reason' => \Illuminate\Support\Str::limit($request->input('content'), 200),
            ]);
        }

        return response()->json([
            'message' => '评论已添加',
            'comment' => [
                'id' => $comment->id,
                'content' => $comment->content,
                'visibility' => $comment->visibility,
                'author' => ['id' => $user->id, 'username' => $user->username, 'real_name' => $user->real_name],
                'created_at' => $comment->created_at,
            ],
        ]);
    }

    /**
     * 标记/撤销争议题（需要原因）
     */
    public function toggleDispute(Request $request, EssayGrading $grading)
    {
        $user = $request->user();
        if (!$user->canInitialGrade() && !$user->canReview()) {
            return response()->json(['message' => '无权操作'], 403);
        }

        $data = $request->validate([
            'is_disputed' => 'required|boolean',
            'reason' => 'required_if:is_disputed,true|string|max:1000',
        ]);

        return DB::transaction(function () use ($grading, $data, $user) {
            $mark = (bool) $data['is_disputed'];
            $currentScore = $grading->status === EssayGrading::STATUS_FINALIZED ? $grading->final_score : $grading->initial_score;

            $grading->is_disputed = $mark ? 1 : 0;
            if ($mark && $grading->status === EssayGrading::STATUS_FINALIZED) {
                // 定稿后又起争议，退回复核
                $grading->status = EssayGrading::STATUS_REVIEW_PENDING;
            }
            if (!$mark && $grading->status === EssayGrading::STATUS_REVIEW_PENDING && !$grading->is_high_score_sample) {
                $grading->status = EssayGrading::STATUS_FINALIZED;
            }
            $grading->save();

            GradingAuditLog::create([
                'essay_grading_id' => $grading->id,
                'action' => $mark ? GradingAuditLog::ACTION_FLAG_DISPUTE : GradingAuditLog::ACTION_REMOVE_DISPUTE,
                'operator_id' => $user->id,
                'score_before' => $currentScore,
                'score_after' => $currentScore,
                'reason' => $mark ? ($data['reason'] ?? '标记为争议题') : ($data['reason'] ?? '撤销争议标记'),
            ]);

            $grading->examRecord->recalculateScore();

            return response()->json(['message' => '操作成功', 'is_disputed' => (bool) $grading->is_disputed, 'status' => $grading->status]);
        });
    }

    /**
     * 标记/撤销高分样卷（撤销后若无需复核则直接定稿）
     */
    public function toggleSample(Request $request, EssayGrading $grading)
    {
        $user = $request->user();
        if (!$user->canInitialGrade() && !$user->canReview()) {
            return response()->json(['message' => '无权操作'], 403);
        }

        $data = $request->validate([
            'is_high_score_sample' => 'required|boolean',
            'reason' => 'required_if:is_high_score_sample,true|string|max:1000',
        ]);

        return DB::transaction(function () use ($grading, $data, $user) {
            $mark = (bool) $data['is_high_score_sample'];
            $currentScore = $grading->status === EssayGrading::STATUS_FINALIZED ? $grading->final_score : $grading->initial_score;

            $grading->is_high_score_sample = $mark ? 1 : 0;
            if ($mark && $grading->status === EssayGrading::STATUS_FINALIZED) {
                $grading->status = EssayGrading::STATUS_REVIEW_PENDING;
            }
            if (!$mark && $grading->status === EssayGrading::STATUS_REVIEW_PENDING && !$grading->is_disputed) {
                $grading->status = EssayGrading::STATUS_FINALIZED;
            }
            $grading->save();

            GradingAuditLog::create([
                'essay_grading_id' => $grading->id,
                'action' => $mark ? GradingAuditLog::ACTION_FLAG_SAMPLE : GradingAuditLog::ACTION_REMOVE_SAMPLE,
                'operator_id' => $user->id,
                'score_before' => $currentScore,
                'score_after' => $currentScore,
                'reason' => $mark ? ($data['reason'] ?? '标记为高分样卷') : ($data['reason'] ?? '撤销高分样卷标记'),
            ]);

            $grading->examRecord->recalculateScore();

            return response()->json(['message' => '操作成功', 'is_high_score_sample' => (bool) $grading->is_high_score_sample, 'status' => $grading->status]);
        });
    }

    /**
     * 批阅修改轨迹（每次分数变更/标记/讨论均可追溯）
     */
    public function auditTrail(Request $request, EssayGrading $grading)
    {
        $user = $request->user();
        if (!$user->canInitialGrade() && !$user->canReview()) {
            return response()->json(['message' => '无权访问'], 403);
        }

        $logs = $grading->auditLogs()->with('operator:id,username,real_name')->get()->map(fn($log) => [
            'id' => $log->id,
            'action' => $log->action,
            'action_text' => GradingAuditLog::ACTION_LABELS[$log->action] ?? $log->action,
            'operator' => $log->operator ? ($log->operator->real_name ?: $log->operator->username) : '系统',
            'operator_role' => $log->operator?->role,
            'score_before' => $log->score_before,
            'score_after' => $log->score_after,
            'reason' => $log->reason,
            'detail' => $log->detail,
            'created_at' => $log->created_at,
        ]);

        return response()->json(['audit_logs' => $logs]);
    }

    /* ==================== 私有辅助 ==================== */

    private function answerFullScore(EssayGrading $grading): float
    {
        $record = ExamRecord::with('examPaper.questions')->find($grading->exam_record_id);
        $paperQuestion = $record?->examPaper?->questions->firstWhere('id', $grading->question_id);

        return (float) ($paperQuestion->pivot->score ?? $grading->question?->score ?? 0);
    }

    private function isHighScore(EssayGrading $grading, float $score): bool
    {
        $full = $this->answerFullScore($grading);
        return $full > 0 && $score >= $full * self::HIGH_SAMPLE_RATIO - 0.0001;
    }

    private function formatGradingListItem(EssayGrading $g): array
    {
        $student = $g->examRecord?->user;

        return [
            'id' => $g->id,
            'question_id' => $g->question_id,
            'question_title' => $g->question?->title,
            'exam_record_id' => $g->exam_record_id,
            'exam_paper' => $g->examRecord?->examPaper ? [
                'id' => $g->examRecord->examPaper->id,
                'title' => $g->examRecord->examPaper->title,
            ] : null,
            'status' => $g->status,
            'status_text' => EssayGrading::STATUSES[$g->status] ?? $g->status,
            'initial_score' => $g->initial_score,
            'final_score' => $g->final_score,
            'is_disputed' => (bool) $g->is_disputed,
            'is_high_score_sample' => (bool) $g->is_high_score_sample,
            'initial_grader' => $g->initialGrader ? ($g->initialGrader->real_name ?: $g->initialGrader->username) : null,
            'reviewer' => $g->reviewer ? ($g->reviewer->real_name ?: $g->reviewer->username) : null,
            'student' => $student ? [
                'id' => $student->id,
                'username' => $student->username,
                'real_name' => $student->real_name,
                'classes' => $student->classes->map(fn($c) => ['id' => $c->id, 'name' => $c->name])->values(),
            ] : null,
            'answer_preview' => mb_substr((string) $g->answer?->answer, 0, 60),
            'initial_gradeds_at' => $g->initial_gradeds_at,
            'reviewed_at' => $g->reviewed_at,
        ];
    }

    private function formatGradingDetail(EssayGrading $grading, float $fullScore): array
    {
        $pointMap = $grading->points->keyBy('rubric_point_id');

        $rubric = $grading->question->rubricPoints->map(function ($rp) use ($pointMap) {
            $row = $pointMap->get($rp->id);

            return [
                'id' => $rp->id,
                'title' => $rp->title,
                'description' => $rp->description,
                'full_score' => (float) $rp->score,
                'initial_score' => $row?->initial_score !== null ? (float) $row->initial_score : null,
                'final_score' => $row?->final_score !== null ? (float) $row->final_score : null,
            ];
        })->values();

        $student = $grading->examRecord?->user;
        $student?->load('classes');

        return [
            'id' => $grading->id,
            'exam_record_id' => $grading->exam_record_id,
            'exam_record_answer_id' => $grading->exam_record_answer_id,
            'question_id' => $grading->question_id,
            'question' => [
                'id' => $grading->question->id,
                'title' => $grading->question->title,
                'type' => $grading->question->type,
                'answer' => $grading->question->answer,
                'analysis' => $grading->question->analysis,
            ],
            'student_answer' => $grading->answer?->answer,
            'full_score' => $fullScore,
            'status' => $grading->status,
            'status_text' => EssayGrading::STATUSES[$grading->status] ?? $grading->status,
            'initial_score' => $grading->initial_score !== null ? (float) $grading->initial_score : null,
            'initial_comment' => $grading->initial_comment,
            'initial_grader' => $grading->initialGrader ? [
                'id' => $grading->initialGrader->id,
                'name' => $grading->initialGrader->real_name ?: $grading->initialGrader->username,
            ] : null,
            'initial_gradeds_at' => $grading->initial_gradeds_at,
            'final_score' => $grading->final_score !== null ? (float) $grading->final_score : null,
            'final_comment' => $grading->final_comment,
            'reviewer' => $grading->reviewer ? [
                'id' => $grading->reviewer->id,
                'name' => $grading->reviewer->real_name ?: $grading->reviewer->username,
            ] : null,
            'reviewed_at' => $grading->reviewed_at,
            'is_disputed' => (bool) $grading->is_disputed,
            'is_high_score_sample' => (bool) $grading->is_high_score_sample,
            'rubric_points' => $rubric,
            'student' => $student ? [
                'id' => $student->id,
                'username' => $student->username,
                'real_name' => $student->real_name,
                'classes' => $student->classes->map(fn($c) => ['id' => $c->id, 'name' => $c->name])->values(),
            ] : null,
            'comments' => $grading->comments()->with('author:id,username,real_name,role')->get()->map(fn($c) => [
                'id' => $c->id,
                'content' => $c->content,
                'visibility' => $c->visibility,
                'author' => $c->author ? ($c->author->real_name ?: $c->author->username) : '',
                'author_role' => $c->author?->role,
                'created_at' => $c->created_at,
            ])->values(),
        ];
    }
}
