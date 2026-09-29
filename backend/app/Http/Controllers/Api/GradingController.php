<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ClassRoom;
use App\Models\ExamPaper;
use App\Models\ExamRecord;
use App\Models\ExamRecordAnswer;
use App\Models\GradingRecord;
use App\Models\GradingRecordPoint;
use App\Models\Question;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class GradingController extends Controller
{
    /**
     * 初评得分率达到该阈值时，自动作为高分样卷进入复核队列。
     */
    public const HIGH_SCORE_SAMPLE_RATE = 0.85;

    /**
     * 初评任务列表（阅卷老师）：只看待初评的主观题答案。
     */
    public function tasks(Request $request)
    {
        if (!$this->canGrade($request->user())) {
            return response()->json(['message' => '无权访问'], 403);
        }

        $validator = Validator::make($request->all(), [
            'exam_paper_id' => 'required|exists:exam_papers,id',
            'question_id' => 'nullable|exists:questions,id',
            'status' => 'nullable|in:pending,initial_graded,finalized',
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $query = ExamRecordAnswer::query()
            ->select('exam_record_answers.*')
            ->join('exam_records', 'exam_record_answers.exam_record_id', '=', 'exam_records.id')
            ->join('questions', 'exam_record_answers.question_id', '=', 'questions.id')
            ->where('exam_records.exam_paper_id', $request->exam_paper_id)
            ->whereIn('exam_records.status', [ExamRecord::STATUS_SUBMITTED, ExamRecord::STATUS_GRADED])
            ->where('questions.type', Question::TYPE_ESSAY)
            ->where('exam_record_answers.grading_status', $request->input('status', ExamRecordAnswer::GRADING_PENDING))
            ->with([
                'examRecord.user.classRoom',
                'question.rubricPoints',
            ])
            ->orderBy('exam_record_answers.id');

        if ($request->filled('question_id')) {
            $query->where('exam_record_answers.question_id', $request->question_id);
        }

        $answers = $query->paginate((int) $request->input('per_page', 10));

        $answers->getCollection()->transform(function ($answer) {
            return $this->serializeTask($answer);
        });

        return response()->json(['tasks' => $answers]);
    }

    /**
     * 提交初评：按评分点给分，可标记争议提交复核。
     */
    public function storeInitial(Request $request, ExamRecordAnswer $answer)
    {
        if (!$this->canGrade($request->user())) {
            return response()->json(['message' => '无权访问'], 403);
        }

        $answer->load(['question.rubricPoints', 'examRecord']);

        if (!$answer->question || !$answer->question->isSubjective()) {
            return response()->json(['message' => '该答案不是主观题，无需人工批阅'], 422);
        }
        if ($answer->grading_status !== ExamRecordAnswer::GRADING_PENDING) {
            return response()->json(['message' => '该答案已完成初评，请勿重复提交'], 422);
        }

        $fullScore = $this->fullScoreOf($answer);
        $rubricPoints = $answer->question->rubricPoints;

        $rules = [
            'comment' => 'nullable|string|max:1000',
            'internal_note' => 'nullable|string|max:1000',
            'needs_review' => 'nullable|boolean',
        ];
        if ($rubricPoints->isNotEmpty()) {
            $rules['points'] = 'required|array';
            $rules['points.*.rubric_point_id'] = 'required|exists:question_rubric_points,id';
            $rules['points.*.score'] = 'required|numeric|min:0';
        } else {
            $rules['total_score'] = "required|numeric|min:0|max:{$fullScore}";
        }

        $validator = Validator::make($request->all(), $rules);
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        [$totalScore, $pointScores] = $this->resolveScores($request, $rubricPoints, $fullScore);
        if ($pointScores === null) {
            return response()->json(['message' => '评分点数据无效或超出分值上限'], 422);
        }

        $needsReview = $request->boolean('needs_review');
        $reviewFlag = ExamRecordAnswer::FLAG_NONE;
        if ($needsReview) {
            $reviewFlag = ExamRecordAnswer::FLAG_DISPUTED;
        } elseif ($fullScore > 0 && ($totalScore / $fullScore) >= self::HIGH_SCORE_SAMPLE_RATE) {
            $reviewFlag = ExamRecordAnswer::FLAG_HIGH_SCORE;
        }

        DB::transaction(function () use ($request, $answer, $totalScore, $pointScores, $reviewFlag) {
            $record = GradingRecord::create([
                'exam_record_answer_id' => $answer->id,
                'grader_id' => $request->user()->id,
                'stage' => GradingRecord::STAGE_INITIAL,
                'total_score' => $totalScore,
                'comment' => $request->input('comment'),
                'internal_note' => $request->input('internal_note'),
                'reason' => $request->input('reason', '首次评阅'),
                'review_flag' => $reviewFlag,
            ]);
            $this->savePointScores($record, $pointScores);

            $finalized = $reviewFlag === ExamRecordAnswer::FLAG_NONE;
            $answer->update([
                'score' => $totalScore,
                'student_comment' => $request->input('comment'),
                'grading_status' => $finalized
                    ? ExamRecordAnswer::GRADING_FINALIZED
                    : ExamRecordAnswer::GRADING_INITIAL_GRADED,
                'review_flag' => $reviewFlag,
            ]);

            $this->refreshExamRecord($answer->examRecord);
        });

        return response()->json([
            'message' => $reviewFlag === ExamRecordAnswer::FLAG_NONE ? '初评完成，该题已定稿' : '初评完成，已进入复核队列',
            'review_flag' => $reviewFlag,
            'answer' => $this->serializeTask($answer->fresh(['examRecord.user.classRoom', 'question.rubricPoints'])),
        ]);
    }

    /**
     * 复核队列（复核老师）：只看争议题和高分样卷。
     */
    public function reviewQueue(Request $request)
    {
        if (!$request->user()->canReview()) {
            return response()->json(['message' => '无权访问复核队列'], 403);
        }

        $validator = Validator::make($request->all(), [
            'exam_paper_id' => 'required|exists:exam_papers,id',
            'flag' => 'nullable|in:disputed,high_score',
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $query = ExamRecordAnswer::query()
            ->select('exam_record_answers.*')
            ->join('exam_records', 'exam_record_answers.exam_record_id', '=', 'exam_records.id')
            ->where('exam_records.exam_paper_id', $request->exam_paper_id)
            ->where('exam_record_answers.grading_status', ExamRecordAnswer::GRADING_INITIAL_GRADED)
            ->whereIn('exam_record_answers.review_flag', [ExamRecordAnswer::FLAG_DISPUTED, ExamRecordAnswer::FLAG_HIGH_SCORE])
            ->with([
                'examRecord.user.classRoom',
                'question.rubricPoints',
                'gradingRecords' => fn ($q) => $q->with(['grader:id,username,real_name', 'points.rubricPoint'])->orderBy('id'),
            ])
            ->orderByRaw("CASE exam_record_answers.review_flag WHEN 'disputed' THEN 0 ELSE 1 END")
            ->orderBy('exam_record_answers.id');

        if ($request->filled('flag')) {
            $query->where('exam_record_answers.review_flag', $request->flag);
        }

        $answers = $query->paginate((int) $request->input('per_page', 10));

        $answers->getCollection()->transform(function ($answer) {
            $data = $this->serializeTask($answer);
            $data['initial_grading'] = $answer->gradingRecords
                ->where('stage', GradingRecord::STAGE_INITIAL)
                ->sortByDesc('id')
                ->map(fn ($r) => $this->serializeGradingRecord($r))
                ->first();
            return $data;
        });

        return response()->json(['queue' => $answers]);
    }

    /**
     * 提交复核：维持原判或调整分数（调整必须填写修改原因）。
     */
    public function storeReview(Request $request, ExamRecordAnswer $answer)
    {
        if (!$request->user()->canReview()) {
            return response()->json(['message' => '无权进行复核'], 403);
        }

        $answer->load(['question.rubricPoints', 'examRecord']);

        if ($answer->grading_status !== ExamRecordAnswer::GRADING_INITIAL_GRADED) {
            return response()->json(['message' => '该答案不在待复核状态'], 422);
        }

        $fullScore = $this->fullScoreOf($answer);
        $rubricPoints = $answer->question->rubricPoints;
        $action = $request->input('action', 'confirm');

        $rules = [
            'action' => 'required|in:confirm,adjust',
            'reason' => 'nullable|string|max:500',
            'comment' => 'nullable|string|max:1000',
            'internal_note' => 'nullable|string|max:1000',
        ];
        if ($action === 'adjust') {
            $rules['reason'] = 'required|string|max:500';
            if ($rubricPoints->isNotEmpty()) {
                $rules['points'] = 'required|array';
                $rules['points.*.rubric_point_id'] = 'required|exists:question_rubric_points,id';
                $rules['points.*.score'] = 'required|numeric|min:0';
            } else {
                $rules['total_score'] = "required|numeric|min:0|max:{$fullScore}";
            }
        }

        $validator = Validator::make($request->all(), $rules);
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        if ($action === 'adjust') {
            [$totalScore, $pointScores] = $this->resolveScores($request, $rubricPoints, $fullScore);
            if ($pointScores === null) {
                return response()->json(['message' => '评分点数据无效或超出分值上限'], 422);
            }
        } else {
            $totalScore = (float) $answer->score;
            $pointScores = $answer->gradingRecords()
                ->where('stage', GradingRecord::STAGE_INITIAL)
                ->latest('id')
                ->first()
                ?->points
                ->mapWithKeys(fn ($p) => [$p->rubric_point_id => (float) $p->score])
                ->toArray() ?? [];
        }

        DB::transaction(function () use ($request, $answer, $action, $totalScore, $pointScores) {
            $record = GradingRecord::create([
                'exam_record_answer_id' => $answer->id,
                'grader_id' => $request->user()->id,
                'stage' => GradingRecord::STAGE_REVIEW,
                'total_score' => $totalScore,
                'comment' => $request->input('comment', $answer->student_comment),
                'internal_note' => $request->input('internal_note'),
                'reason' => $action === 'adjust'
                    ? $request->input('reason')
                    : ($request->input('reason') ?: '复核确认维持原评'),
                'review_flag' => ExamRecordAnswer::FLAG_NONE,
            ]);
            $this->savePointScores($record, $pointScores);

            $answer->update([
                'score' => $totalScore,
                'student_comment' => $request->input('comment', $answer->student_comment),
                'grading_status' => ExamRecordAnswer::GRADING_FINALIZED,
                'review_flag' => ExamRecordAnswer::FLAG_NONE,
            ]);

            $this->refreshExamRecord($answer->examRecord);
        });

        return response()->json([
            'message' => $action === 'adjust' ? '复核完成，分数已调整' : '复核完成，维持原评',
            'answer' => $this->serializeTask($answer->fresh(['examRecord.user.classRoom', 'question.rubricPoints'])),
        ]);
    }

    /**
     * 批阅历史（教师端）：每次初评/复核的完整流水，含修改原因与内部备注。
     */
    public function history(Request $request, ExamRecordAnswer $answer)
    {
        if (!$this->canGrade($request->user())) {
            return response()->json(['message' => '无权访问'], 403);
        }

        $answer->load(['examRecord.user.classRoom', 'question.rubricPoints']);

        $records = $answer->gradingRecords()
            ->with(['grader:id,username,real_name', 'points.rubricPoint'])
            ->orderBy('id')
            ->get()
            ->map(fn ($r) => $this->serializeGradingRecord($r));

        return response()->json([
            'answer' => $this->serializeTask($answer),
            'history' => $records,
        ]);
    }

    /**
     * 批阅进度：按班级、按题目两个维度统计。
     */
    public function progress(Request $request, ExamPaper $examPaper)
    {
        if (!$this->canGrade($request->user())) {
            return response()->json(['message' => '无权访问'], 403);
        }

        $essayQuestionIds = $examPaper->questions()
            ->where('questions.type', Question::TYPE_ESSAY)
            ->pluck('questions.id');

        // ---- 按题目统计 ----
        $byQuestion = $examPaper->questions()
            ->where('questions.type', Question::TYPE_ESSAY)
            ->get()
            ->map(function ($question) use ($examPaper) {
                $stats = ExamRecordAnswer::query()
                    ->join('exam_records', 'exam_record_answers.exam_record_id', '=', 'exam_records.id')
                    ->where('exam_records.exam_paper_id', $examPaper->id)
                    ->whereIn('exam_records.status', [ExamRecord::STATUS_SUBMITTED, ExamRecord::STATUS_GRADED])
                    ->where('exam_record_answers.question_id', $question->id)
                    ->selectRaw("
                        COUNT(*) as total,
                        SUM(exam_record_answers.grading_status = 'pending') as pending,
                        SUM(exam_record_answers.grading_status = 'initial_graded') as reviewing,
                        SUM(exam_record_answers.grading_status = 'finalized') as finalized
                    ")
                    ->first();

                return [
                    'question_id' => $question->id,
                    'title' => $question->title,
                    'full_score' => (float) $question->pivot->score,
                    'total' => (int) $stats->total,
                    'pending' => (int) $stats->pending,
                    'reviewing' => (int) $stats->reviewing,
                    'finalized' => (int) $stats->finalized,
                ];
            })
            ->values();

        // ---- 按班级统计 ----
        $submittedRecords = ExamRecord::query()
            ->where('exam_paper_id', $examPaper->id)
            ->whereIn('status', [ExamRecord::STATUS_SUBMITTED, ExamRecord::STATUS_GRADED])
            ->get(['id', 'user_id', 'status']);

        $answerStatsByRecord = collect();
        if ($submittedRecords->isNotEmpty() && $essayQuestionIds->isNotEmpty()) {
            $answerStatsByRecord = ExamRecordAnswer::query()
                ->whereIn('exam_record_id', $submittedRecords->pluck('id'))
                ->whereIn('question_id', $essayQuestionIds)
                ->selectRaw("
                    exam_record_id,
                    SUM(grading_status = 'pending') as pending,
                    SUM(grading_status = 'initial_graded') as reviewing,
                    SUM(grading_status = 'finalized') as finalized
                ")
                ->groupBy('exam_record_id')
                ->get()
                ->keyBy('exam_record_id');
        }

        $students = User::where('role', User::ROLE_STUDENT)->where('status', 1)
            ->get(['id', 'class_id']);
        $userClassMap = $students->pluck('class_id', 'id');
        $studentCountByClass = $students->groupBy(fn ($s) => $s->class_id ?? 'none')->map->count();

        $classNames = ClassRoom::orderBy('id')->pluck('name', 'id');

        $buckets = [];
        foreach ($classNames as $classId => $className) {
            $buckets[$classId] = [
                'class_id' => $classId,
                'class_name' => $className,
                'total_students' => (int) ($studentCountByClass->get($classId) ?? 0),
                'submitted_records' => 0,
                'fully_graded_records' => 0,
                'pending_answers' => 0,
                'reviewing_answers' => 0,
                'finalized_answers' => 0,
            ];
        }
        $buckets['none'] = [
            'class_id' => null,
            'class_name' => '未分班',
            'total_students' => (int) ($studentCountByClass->get('none') ?? 0),
            'submitted_records' => 0,
            'fully_graded_records' => 0,
            'pending_answers' => 0,
            'reviewing_answers' => 0,
            'finalized_answers' => 0,
        ];

        foreach ($submittedRecords as $record) {
            $classId = $userClassMap->get($record->user_id);
            $key = ($classId !== null && isset($buckets[$classId])) ? $classId : 'none';
            $buckets[$key]['submitted_records']++;
            if ($record->status === ExamRecord::STATUS_GRADED) {
                $buckets[$key]['fully_graded_records']++;
            }
            $stats = $answerStatsByRecord->get($record->id);
            if ($stats) {
                $buckets[$key]['pending_answers'] += (int) $stats->pending;
                $buckets[$key]['reviewing_answers'] += (int) $stats->reviewing;
                $buckets[$key]['finalized_answers'] += (int) $stats->finalized;
            }
        }

        // 未分班且无学生、无答卷时隐藏该分组
        $byClass = collect(array_values($buckets))
            ->filter(fn ($row) => $row['total_students'] > 0 || $row['submitted_records'] > 0)
            ->values();

        return response()->json([
            'exam_paper' => [
                'id' => $examPaper->id,
                'title' => $examPaper->title,
            ],
            'by_class' => $byClass,
            'by_question' => $byQuestion,
        ]);
    }

    /**
     * 有主观题批阅任务的试卷列表（含整体进度摘要）。
     */
    public function papers(Request $request)
    {
        if (!$this->canGrade($request->user())) {
            return response()->json(['message' => '无权访问'], 403);
        }

        $papers = ExamPaper::query()
            ->where('status', 1)
            ->whereHas('questions', fn ($q) => $q->where('questions.type', Question::TYPE_ESSAY))
            ->with(['questions' => fn ($q) => $q->where('questions.type', Question::TYPE_ESSAY)])
            ->orderBy('id', 'desc')
            ->get()
            ->map(function ($paper) {
                $essayIds = $paper->questions->pluck('id');
                $stats = ExamRecordAnswer::query()
                    ->join('exam_records', 'exam_record_answers.exam_record_id', '=', 'exam_records.id')
                    ->where('exam_records.exam_paper_id', $paper->id)
                    ->whereIn('exam_records.status', [ExamRecord::STATUS_SUBMITTED, ExamRecord::STATUS_GRADED])
                    ->whereIn('exam_record_answers.question_id', $essayIds)
                    ->selectRaw("
                        COUNT(*) as total,
                        SUM(exam_record_answers.grading_status = 'pending') as pending,
                        SUM(exam_record_answers.grading_status = 'initial_graded') as reviewing,
                        SUM(exam_record_answers.grading_status = 'finalized') as finalized
                    ")
                    ->first();

                return [
                    'id' => $paper->id,
                    'title' => $paper->title,
                    'essay_questions' => $paper->questions->map(fn ($q) => [
                        'id' => $q->id,
                        'title' => $q->title,
                        'full_score' => (float) $q->pivot->score,
                    ])->values(),
                    'total_answers' => (int) ($stats->total ?? 0),
                    'pending' => (int) ($stats->pending ?? 0),
                    'reviewing' => (int) ($stats->reviewing ?? 0),
                    'finalized' => (int) ($stats->finalized ?? 0),
                ];
            });

        return response()->json(['papers' => $papers]);
    }

    private function canGrade(User $user): bool
    {
        return $user->isAdmin() || $user->isTeacher();
    }

    /**
     * 该题在所属试卷中的满分。
     */
    private function fullScoreOf(ExamRecordAnswer $answer): float
    {
        $pivotScore = DB::table('exam_paper_questions')
            ->where('exam_paper_id', $answer->examRecord->exam_paper_id)
            ->where('question_id', $answer->question_id)
            ->value('score');

        return (float) ($pivotScore ?? $answer->question->score ?? 0);
    }

    /**
     * 解析请求中的得分：有评分点时按评分点求和并逐项校验上限。
     *
     * @return array{0: float, 1: array|null} [总分, 评分点得分映射(null 表示校验失败)]
     */
    private function resolveScores(Request $request, $rubricPoints, float $fullScore): array
    {
        if ($rubricPoints->isEmpty()) {
            $total = (float) $request->input('total_score');
            return $total <= $fullScore ? [$total, []] : [0, null];
        }

        $input = collect($request->input('points', []))->keyBy('rubric_point_id');
        $pointScores = [];
        $total = 0.0;

        foreach ($rubricPoints as $point) {
            $item = $input->get($point->id);
            if ($item === null || !isset($item['score']) || !is_numeric($item['score'])) {
                return [0, null];
            }
            $score = (float) $item['score'];
            if ($score < 0 || $score > (float) $point->max_score) {
                return [0, null];
            }
            $pointScores[$point->id] = $score;
            $total += $score;
        }

        if ($total > $fullScore) {
            return [0, null];
        }

        return [$total, $pointScores];
    }

    private function savePointScores(GradingRecord $record, array $pointScores): void
    {
        foreach ($pointScores as $rubricPointId => $score) {
            GradingRecordPoint::create([
                'grading_record_id' => $record->id,
                'rubric_point_id' => $rubricPointId,
                'score' => $score,
            ]);
        }
    }

    /**
     * 重算答卷总分；当所有主观题定稿后，答卷状态流转为已评分。
     */
    private function refreshExamRecord(ExamRecord $record): void
    {
        $record->refresh();
        $total = (float) $record->answers()->sum('score');
        $unfinished = $record->answers()
            ->whereIn('grading_status', [
                ExamRecordAnswer::GRADING_PENDING,
                ExamRecordAnswer::GRADING_INITIAL_GRADED,
            ])
            ->count();

        $record->score = $total;
        if ($unfinished === 0 && $record->status === ExamRecord::STATUS_SUBMITTED) {
            $record->status = ExamRecord::STATUS_GRADED;
        }
        $record->save();
    }

    private function serializeTask(ExamRecordAnswer $answer): array
    {
        $user = $answer->examRecord?->user;

        return [
            'id' => $answer->id,
            'exam_record_id' => $answer->exam_record_id,
            'grading_status' => $answer->grading_status,
            'review_flag' => $answer->review_flag,
            'score' => (float) $answer->score,
            'student_answer' => $answer->answer,
            'student_comment' => $answer->student_comment,
            'student' => $user ? [
                'id' => $user->id,
                'username' => $user->username,
                'real_name' => $user->real_name,
                'class_name' => $user->classRoom?->name ?? '未分班',
            ] : null,
            'question' => $answer->question ? [
                'id' => $answer->question->id,
                'title' => $answer->question->title,
                'reference_answer' => $answer->question->answer,
                'full_score' => $this->fullScoreOf($answer),
                'rubric_points' => $answer->question->rubricPoints->map(fn ($p) => [
                    'id' => $p->id,
                    'title' => $p->title,
                    'max_score' => (float) $p->max_score,
                ])->values(),
            ] : null,
        ];
    }

    private function serializeGradingRecord(GradingRecord $record): array
    {
        return [
            'id' => $record->id,
            'stage' => $record->stage,
            'stage_label' => $record->stage === GradingRecord::STAGE_INITIAL ? '初评' : '复核',
            'grader' => $record->grader ? [
                'id' => $record->grader->id,
                'name' => $record->grader->real_name ?: $record->grader->username,
            ] : null,
            'total_score' => (float) $record->total_score,
            'comment' => $record->comment,
            'internal_note' => $record->internal_note,
            'reason' => $record->reason,
            'review_flag' => $record->review_flag,
            'points' => $record->points->map(fn ($p) => [
                'rubric_point_id' => $p->rubric_point_id,
                'title' => $p->rubricPoint?->title,
                'score' => (float) $p->score,
            ])->values(),
            'created_at' => $record->created_at?->toDateTimeString(),
        ];
    }
}
