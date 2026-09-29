<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ExamPaper;
use App\Models\ExamRecord;
use App\Models\ExamRecordAnswer;
use App\Models\Question;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ExamController extends Controller
{
    public function index(Request $request)
    {
        $examPapers = ExamPaper::with('creator')
            ->where('status', 1)
            ->orderBy('id', 'desc')
            ->paginate($perPage = $request->input('per_page', 15));

        return response()->json([
            'exam_papers' => $examPapers,
        ]);
    }

    public function start(Request $request, ExamPaper $examPaper)
    {
        $existingRecord = ExamRecord::where('user_id', $request->user()->id)
            ->where('exam_paper_id', $examPaper->id)
            ->where('status', 'in_progress')
            ->first();

        if ($existingRecord) {
            return response()->json([
                'message' => '您已经开始这场考试',
                'exam_record' => $existingRecord,
            ]);
        }

        $record = ExamRecord::create([
            'user_id' => $request->user()->id,
            'exam_paper_id' => $examPaper->id,
            'start_time' => now(),
            'status' => 'in_progress',
        ]);

        $questions = $examPaper->questions()->get();

        $questionsData = $questions->map(function ($q) {
            return [
                'id' => $q->id,
                'type' => $q->type,
                'title' => $q->title,
                'options' => $q->options,
                'score' => $q->pivot->score,
            ];
        });

        return response()->json([
            'message' => '考试开始',
            'exam_record' => $record,
            'exam_paper' => [
                'id' => $examPaper->id,
                'title' => $examPaper->title,
                'total_time' => $examPaper->total_time,
                'total_score' => $examPaper->total_score,
            ],
            'questions' => $questionsData,
        ]);
    }

    public function getQuestions(Request $request, ExamPaper $examPaper)
    {
        $record = ExamRecord::where('user_id', $request->user()->id)
            ->where('exam_paper_id', $examPaper->id)
            ->where('status', 'in_progress')
            ->firstOrFail();

        $questions = $examPaper->questions()->get();

        $questionsData = $questions->map(function ($q) {
            return [
                'id' => $q->id,
                'type' => $q->type,
                'title' => $q->title,
                'options' => $q->options,
                'score' => $q->pivot->score,
            ];
        });

        return response()->json([
            'exam_record' => $record,
            'exam_paper' => [
                'id' => $examPaper->id,
                'title' => $examPaper->title,
                'total_time' => $examPaper->total_time,
                'total_score' => $examPaper->total_score,
            ],
            'questions' => $questionsData,
        ]);
    }

    public function submit(Request $request, ExamPaper $examPaper)
    {
        $validator = Validator::make($request->all(), [
            'exam_record_id' => 'required|exists:exam_records,id',
            'answers' => 'present|array',
            'answers.*.question_id' => 'required|exists:questions,id',
            'answers.*.answer' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $record = ExamRecord::where('id', $request->exam_record_id)
            ->where('user_id', $request->user()->id)
            ->where('exam_paper_id', $examPaper->id)
            ->where('status', 'in_progress')
            ->firstOrFail();

        $totalScore = 0;
        $hasSubjective = false;
        $questionMap = $examPaper->questions->keyBy('id');
        $submittedAnswers = collect($request->answers)->keyBy('question_id');

        // 遍历试卷全部题目：未作答的题目也生成记录，保证批阅进度统计完整。
        foreach ($questionMap as $question) {
            $answerData = $submittedAnswers->get($question->id);
            $userAnswer = $answerData['answer'] ?? '';

            if ($question->type === Question::TYPE_ESSAY) {
                $hasSubjective = true;
                ExamRecordAnswer::create([
                    'exam_record_id' => $record->id,
                    'question_id' => $question->id,
                    'answer' => $userAnswer,
                    'is_correct' => false,
                    'score' => 0,
                    'grading_status' => ExamRecordAnswer::GRADING_PENDING,
                ]);
                continue;
            }

            $isCorrect = $userAnswer !== '' && $this->checkAnswer($question, $userAnswer);
            $score = $isCorrect ? $question->pivot->score : 0;

            ExamRecordAnswer::create([
                'exam_record_id' => $record->id,
                'question_id' => $question->id,
                'answer' => $userAnswer,
                'is_correct' => $isCorrect,
                'score' => $score,
                'grading_status' => ExamRecordAnswer::GRADING_NONE,
            ]);

            $totalScore += $score;
        }

        $record->update([
            'end_time' => now(),
            'score' => $totalScore,
            'status' => $hasSubjective ? ExamRecord::STATUS_SUBMITTED : ExamRecord::STATUS_GRADED,
        ]);

        return response()->json([
            'message' => $hasSubjective ? '提交成功，主观题待老师批阅' : '提交成功',
            'score' => $totalScore,
            'has_subjective' => $hasSubjective,
            'exam_record' => $record->load('answers'),
        ]);
    }

    public function myRecords(Request $request)
    {
        $records = ExamRecord::with('examPaper')
            ->where('user_id', $request->user()->id)
            ->orderBy('id', 'desc')
            ->paginate($perPage = $request->input('per_page', 15));

        return response()->json([
            'records' => $records,
        ]);
    }

    public function showRecord(Request $request, ExamRecord $record)
    {
        if ($record->user_id !== $request->user()->id) {
            return response()->json(['message' => '无权查看此记录'], 403);
        }

        $record->load(['examPaper', 'answers.question']);

        // 学生可见视图：只暴露得分点与简短评语，不暴露内部讨论、修改原因与批阅人。
        $answers = $record->answers->map(function ($answer) {
            $question = $answer->question;
            $data = [
                'id' => $answer->id,
                'question_id' => $answer->question_id,
                'answer' => $answer->answer,
                'is_correct' => (bool) $answer->is_correct,
                'score' => (float) $answer->score,
                'grading_status' => $answer->grading_status,
                'question' => $question ? [
                    'id' => $question->id,
                    'type' => $question->type,
                    'title' => $question->title,
                    'options' => $question->options,
                    'analysis' => $question->analysis,
                ] : null,
                'rubric_scores' => null,
                'student_comment' => null,
            ];

            if ($answer->grading_status !== ExamRecordAnswer::GRADING_NONE
                && $answer->grading_status !== ExamRecordAnswer::GRADING_PENDING) {
                $finalGrading = $answer->gradingRecords()
                    ->with('points.rubricPoint')
                    ->orderByDesc('id')
                    ->first();

                if ($finalGrading) {
                    $data['rubric_scores'] = $finalGrading->points->map(function ($p) {
                        return [
                            'title' => $p->rubricPoint?->title,
                            'max_score' => (float) ($p->rubricPoint?->max_score ?? 0),
                            'score' => (float) $p->score,
                        ];
                    })->values();
                }
                $data['student_comment'] = $answer->student_comment;
            }

            return $data;
        })->values();

        return response()->json([
            'record' => [
                'id' => $record->id,
                'exam_paper_id' => $record->exam_paper_id,
                'start_time' => $record->start_time,
                'end_time' => $record->end_time,
                'score' => (float) $record->score,
                'status' => $record->status,
                'exam_paper' => $record->examPaper ? [
                    'id' => $record->examPaper->id,
                    'title' => $record->examPaper->title,
                    'total_score' => (float) $record->examPaper->total_score,
                    'total_time' => $record->examPaper->total_time,
                ] : null,
                'answers' => $answers,
            ],
        ]);
    }

    protected function checkAnswer(Question $question, string $userAnswer): bool
    {
        $correctAnswer = $question->answer;

        switch ($question->type) {
            case 'single_choice':
            case 'true_false':
                return strtoupper(trim($userAnswer)) === strtoupper(trim($correctAnswer));
            case 'multiple_choice':
                $userAnswers = explode(',', strtoupper(trim($userAnswer)));
                $correctAnswers = explode(',', strtoupper(trim($correctAnswer)));
                sort($userAnswers);
                sort($correctAnswers);
                return $userAnswers === $correctAnswers;
            case 'fill_blank':
                return strtoupper(trim($userAnswer)) === strtoupper(trim($correctAnswer));
            default:
                return false;
        }
    }
}
