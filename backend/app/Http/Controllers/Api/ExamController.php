<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EssayGrading;
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
            'answers' => 'required|array',
            'answers.*.question_id' => 'required|exists:questions,id',
            'answers.*.answer' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $record = ExamRecord::where('id', $request->exam_record_id)
            ->where('user_id', $request->user()->id)
            ->where('exam_paper_id', $examPaper->id)
            ->where('status', 'in_progress')
            ->firstOrFail();

        $objectiveScore = 0;
        $hasEssay = false;
        $questionMap = $examPaper->questions->keyBy('id');

        foreach ($request->answers as $answerData) {
            $question = $questionMap->get($answerData['question_id']);
            if (!$question) {
                continue;
            }

            if ($question->type === Question::TYPE_ESSAY) {
                // 主观题无法自动判分：0 分占位，创建待初评的批阅单
                $hasEssay = true;
                $answer = ExamRecordAnswer::create([
                    'exam_record_id' => $record->id,
                    'question_id' => $answerData['question_id'],
                    'answer' => $answerData['answer'],
                    'is_correct' => 0,
                    'score' => 0,
                ]);

                EssayGrading::create([
                    'exam_record_answer_id' => $answer->id,
                    'exam_record_id' => $record->id,
                    'question_id' => $question->id,
                    'status' => EssayGrading::STATUS_PENDING,
                    'is_high_score_sample' => 0,
                    'is_disputed' => 0,
                ]);
                continue;
            }

            $isCorrect = $this->checkAnswer($question, $answerData['answer']);
            $score = $isCorrect ? $question->pivot->score : 0;

            ExamRecordAnswer::create([
                'exam_record_id' => $record->id,
                'question_id' => $answerData['question_id'],
                'answer' => $answerData['answer'],
                'is_correct' => $isCorrect,
                'score' => $score,
            ]);

            $objectiveScore += $score;
        }

        // 含主观题时先置为 submitted，全部定稿后才转为 graded
        $record->update([
            'end_time' => now(),
            'score' => $objectiveScore,
            'status' => $hasEssay ? ExamRecord::STATUS_SUBMITTED : ExamRecord::STATUS_GRADED,
        ]);

        return response()->json([
            'message' => $hasEssay ? '提交成功，主观题等待老师批阅' : '提交成功',
            'score' => $objectiveScore,
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
        $user = $request->user();
        $isOwner = $record->user_id === $user->id;
        $isStaff = $user->canInitialGrade() || $user->canReview();

        if (!$isOwner && !$isStaff) {
            return response()->json(['message' => '无权查看此记录'], 403);
        }

        $record->load([
            'examPaper.questions',
            'user',
            'answers.question',
            'answers.essayGrading.points.rubricPoint',
        ]);

        // 学生视图：只暴露得分点与对学生可见的简短评语，内部讨论/争议/样卷标记一律不下发
        $paperQuestionScores = $record->examPaper->questions->pluck('pivot.score', 'id');

        $answers = $record->answers->map(function ($answer) use ($paperQuestionScores, $isOwner) {
            $question = $answer->question;
            $isEssay = $question && $question->type === Question::TYPE_ESSAY;

            $data = [
                'id' => $answer->id,
                'question_id' => $answer->question_id,
                'answer' => $answer->answer,
                'score' => $answer->score,
                'is_correct' => (bool) $answer->is_correct,
                'question' => [
                    'id' => $question->id,
                    'type' => $question->type,
                    'title' => $question->title,
                    'options' => $question->options,
                    'answer' => $question->answer,
                    'analysis' => $question->analysis,
                    'full_score' => $paperQuestionScores[$question->id] ?? $question->score,
                ],
            ];

            if ($isEssay) {
                $grading = $answer->essayGrading;

                if (!$grading) {
                    $data['essay_grading'] = null;
                } elseif ($isOwner && $grading->status !== EssayGrading::STATUS_FINALIZED) {
                    // 学生视角：未定稿（待初评/待复核）时只给状态，不泄露初评分数、评语、得分点
                    $data['essay_grading'] = [
                        'status' => $grading->status,
                        'status_text' => EssayGrading::STATUSES[$grading->status] ?? $grading->status,
                        'score' => null,
                        'comment' => null,
                        'rubric_points' => [],
                        'student_comments' => [],
                    ];
                } else {
                    // 已定稿（学生）或教职工：暴露得分点与对学生可见的简短评语；
                    // 注意：内部讨论(is_disputed/样卷标记/internal 评论)绝不下发
                    $data['essay_grading'] = [
                        'status' => $grading->status,
                        'status_text' => EssayGrading::STATUSES[$grading->status] ?? $grading->status,
                        'score' => $grading->final_score ?? $grading->initial_score,
                        'comment' => $grading->final_comment ?? $grading->initial_comment,
                        'rubric_points' => $grading->points->map(function ($p) {
                            return [
                                'title' => $p->rubricPoint->title ?? '',
                                'description' => $p->rubricPoint->description ?? '',
                                'full_score' => $p->rubricPoint->score ?? 0,
                                'score' => $p->final_score ?? $p->initial_score,
                            ];
                        }),
                        'student_comments' => $grading->studentComments()
                            ->with('author:id,username,real_name')
                            ->get()
                            ->map(fn($c) => [
                                'content' => $c->content,
                                'author' => $c->author->real_name ?: $c->author->username,
                                'created_at' => $c->created_at,
                            ])->values(),
                    ];
                }
            }

            return $data;
        });

        return response()->json([
            'record' => [
                'id' => $record->id,
                'score' => $record->score,
                'status' => $record->status,
                'start_time' => $record->start_time,
                'end_time' => $record->end_time,
                'created_at' => $record->created_at,
                'exam_paper' => [
                    'id' => $record->examPaper->id,
                    'title' => $record->examPaper->title,
                    'total_score' => $record->examPaper->total_score,
                ],
                'student' => $record->user ? [
                    'id' => $record->user->id,
                    'username' => $record->user->username,
                    'real_name' => $record->user->real_name,
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
