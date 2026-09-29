<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EssayGrading;
use App\Models\ExamPaper;
use App\Models\Question;
use App\Models\SchoolClass;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;


class GradingProgressController extends Controller
{
    /**
     * 批阅进度：按班级 + 按题目 双维度统计。
     * GET /grading/progress?exam_paper_id=
     */
    public function index(Request $request)
    {
        $user = $request->user();
        if (!$user->canInitialGrade() && !$user->canReview()) {
            return response()->json(['message' => '无权访问'], 403);
        }

        $examPaperId = $request->input('exam_paper_id');

        $paperIds = $examPaperId
            ? collect([(int) $examPaperId])
            : ExamPaper::whereHas('questions', fn($q) => $q->where('type', Question::TYPE_ESSAY))
                ->pluck('id');

        /* ---------- 维度一：按班级 ---------- */
        $gradings = $this->baseGradings($paperIds)->get();
        $records = DB::table('exam_records')
            ->whereIn('exam_paper_id', $paperIds)
            ->whereIn('status', ['submitted', 'graded'])
            ->get(['id', 'user_id', 'exam_paper_id']);

        $classes = SchoolClass::where('status', 1)->orderBy('id')->get();

        $byClass = $classes->map(function ($class) use ($gradings, $records) {
            $studentIds = DB::table('class_student')->where('class_id', $class->id)->pluck('user_id');
            $recordIds = $records->whereIn('user_id', $studentIds)->pluck('id');
            $classGradings = $gradings->whereIn('exam_record_id', $recordIds);

            return $this->progressBlock($class->id, $class->name, $classGradings);
        });

        // 未分班学生
        $classStudentIds = DB::table('class_student')->pluck('user_id')->unique();
        $unclassifiedRecordIds = $records->whereNotIn('user_id', $classStudentIds)->pluck('id');
        $unclassified = $gradings->whereIn('exam_record_id', $unclassifiedRecordIds);
        if ($unclassified->count() > 0) {
            $byClass->push($this->progressBlock(null, '未分班', $unclassified));
        }

        /* ---------- 维度二：按题目（试卷 -> 题目） ---------- */
        $papers = ExamPaper::whereIn('id', $paperIds)
            ->with(['questions' => fn($q) => $q->where('type', Question::TYPE_ESSAY)])
            ->get();

        $byQuestion = $papers->map(function ($paper) use ($gradings, $records) {
            // 仅统计本试卷的答卷记录，避免同一道题出现在多张试卷时跨卷计数
            $paperRecordIds = $records->where('exam_paper_id', $paper->id)->pluck('id');

            return [
                'exam_paper_id' => $paper->id,
                'exam_paper_title' => $paper->title,
                'questions' => $paper->questions->map(function ($question) use ($gradings, $paperRecordIds) {
                    $list = $gradings
                        ->whereIn('exam_record_id', $paperRecordIds)
                        ->where('question_id', $question->id);

                    $block = $this->progressBlock($question->id, $question->title, $list);
                    $block['full_score'] = (float) ($question->pivot->score ?? $question->score);

                    return $block;
                })->values(),
            ];
        });

        /* ---------- 总览 ---------- */
        $overview = $this->progressBlock(null, '全部', $gradings);
        unset($overview['id']);

        return response()->json([
            'overview' => $overview,
            'by_class' => $byClass->values(),
            'by_question' => $byQuestion->values(),
        ]);
    }

    private function baseGradings($paperIds)
    {
        return EssayGrading::query()
            ->whereHas('examRecord', fn($q) => $q->whereIn('exam_paper_id', $paperIds))
            ->select(['id', 'exam_record_id', 'question_id', 'status', 'is_disputed', 'is_high_score_sample', 'initial_score', 'final_score']);
    }

    private function progressBlock($id, string $name, $gradings): array
    {
        $total = $gradings->count();
        $pending = $gradings->where('status', EssayGrading::STATUS_PENDING)->count();
        $reviewPending = $gradings->where('status', EssayGrading::STATUS_REVIEW_PENDING)->count();
        $finalized = $gradings->where('status', EssayGrading::STATUS_FINALIZED)->count();
        $disputed = $gradings->where('is_disputed', 1)->count();
        $samples = $gradings->where('is_high_score_sample', 1)->count();

        return [
            'id' => $id,
            'name' => $name,
            'total' => $total,
            'pending' => $pending,
            'review_pending' => $reviewPending,
            'finalized' => $finalized,
            'disputed' => $disputed,
            'high_score_sample' => $samples,
            'graded_rate' => $total > 0 ? round($finalized / $total * 100, 1) : 0,
            'initial_graded' => $total - $pending,
        ];
    }
}
