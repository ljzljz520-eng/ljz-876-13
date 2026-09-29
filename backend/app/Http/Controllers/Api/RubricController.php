<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\GradingRubricPoint;
use App\Models\Question;
use Illuminate\Http\Request;

class RubricController extends Controller
{
    /**
     * 某道主观题的全部评分点
     */
    public function index(Request $request, Question $question)
    {
        $user = $request->user();
        if (!$user->canInitialGrade() && !$user->canReview() && !$user->isStudent()) {
            return response()->json(['message' => '无权访问'], 403);
        }

        $points = $question->rubricPoints()->get()->map(fn($p) => [
            'id' => $p->id,
            'title' => $p->title,
            'description' => $p->description,
            'score' => (float) $p->score,
            'sort_order' => $p->sort_order,
        ]);

        return response()->json([
            'question_id' => $question->id,
            'rubric_points' => $points,
            'total_score' => round((float) $points->sum('score'), 2),
        ]);
    }

    public function store(Request $request, Question $question)
    {
        if (!$request->user()->canInitialGrade()) {
            return response()->json(['message' => '仅阅卷老师可以维护评分点'], 403);
        }
        if (!$question->isEssay()) {
            return response()->json(['message' => '只有主观题可以设置评分点'], 422);
        }

        $data = $this->validatePoint($request);
        $maxOrder = (int) $question->rubricPoints()->max('sort_order');
        $point = GradingRubricPoint::create([
            'question_id' => $question->id,
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'score' => $data['score'],
            'sort_order' => $data['sort_order'] ?? $maxOrder + 1,
        ]);

        $this->syncQuestionScore($question);

        return response()->json(['message' => '评分点已添加', 'rubric_point' => $point], 201);
    }

    public function update(Request $request, Question $question, GradingRubricPoint $rubric)
    {
        if (!$request->user()->canInitialGrade()) {
            return response()->json(['message' => '仅阅卷老师可以维护评分点'], 403);
        }
        if ($rubric->question_id !== $question->id) {
            return response()->json(['message' => '评分点与题目不匹配'], 422);
        }

        $data = $this->validatePoint($request);
        $rubric->update($data);
        $this->syncQuestionScore($question);

        return response()->json(['message' => '评分点已更新', 'rubric_point' => $rubric]);
    }

    public function destroy(Request $request, Question $question, GradingRubricPoint $rubric)
    {
        if (!$request->user()->canInitialGrade()) {
            return response()->json(['message' => '仅阅卷老师可以维护评分点'], 403);
        }
        if ($rubric->question_id !== $question->id) {
            return response()->json(['message' => '评分点与题目不匹配'], 422);
        }

        $rubric->delete();
        $this->syncQuestionScore($question);

        return response()->json(['message' => '评分点已删除']);
    }

    private function validatePoint(Request $request): array
    {
        return $request->validate([
            'title' => 'required|string|max:200',
            'description' => 'nullable|string|max:500',
            'score' => 'required|numeric|min:0|max:100',
            'sort_order' => 'nullable|integer|min:0',
        ]);
    }

    /**
     * 题目分值与评分点总分保持一致
     */
    private function syncQuestionScore(Question $question): void
    {
        $total = (float) $question->rubricPoints()->sum('score');
        if ($total > 0) {
            $question->update(['score' => round($total, 2)]);
        }
    }
}
