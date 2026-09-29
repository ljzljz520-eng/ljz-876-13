<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ClassController;
use App\Http\Controllers\Api\ExamController;
use App\Http\Controllers\Api\ExamPaperController;
use App\Http\Controllers\Api\GradingController;
use App\Http\Controllers\Api\GradingProgressController;
use App\Http\Controllers\Api\QuestionController;
use App\Http\Controllers\Api\RubricController;
use App\Http\Controllers\Api\ScoreController;
use Illuminate\Support\Facades\Route;

Route::middleware('api')->prefix('auth')->group(function () {
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:5,1');
    Route::post('/register', [AuthController::class, 'register']);
});

Route::middleware(['api', 'auth:sanctum', 'throttle:60,1'])->group(function () {
    Route::prefix('auth')->group(function () {
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::get('/me', [AuthController::class, 'me']);
    });

    Route::prefix('questions')->group(function () {
        Route::get('/', [QuestionController::class, 'index']);
        Route::post('/', [QuestionController::class, 'store']);
        Route::get('/categories', [QuestionController::class, 'categories']);
        Route::post('/categories', [QuestionController::class, 'storeCategory']);
        Route::get('/{question}', [QuestionController::class, 'show']);
        Route::put('/{question}', [QuestionController::class, 'update']);
        Route::delete('/{question}', [QuestionController::class, 'destroy']);
    });

    Route::prefix('exam-papers')->group(function () {
        Route::get('/', [ExamPaperController::class, 'index']);
        Route::post('/', [ExamPaperController::class, 'store']);
        Route::get('/{examPaper}', [ExamPaperController::class, 'show']);
        Route::put('/{examPaper}', [ExamPaperController::class, 'update']);
        Route::delete('/{examPaper}', [ExamPaperController::class, 'destroy']);
        Route::post('/{examPaper}/questions', [ExamPaperController::class, 'addQuestions']);
        Route::delete('/{examPaper}/questions/{question}', [ExamPaperController::class, 'removeQuestion']);
    });

    Route::prefix('exams')->group(function () {
        Route::get('/', [ExamController::class, 'index']);
        Route::post('/{examPaper}/start', [ExamController::class, 'start']);
        Route::get('/{examPaper}/questions', [ExamController::class, 'getQuestions']);
        Route::post('/{examPaper}/submit', [ExamController::class, 'submit']);
        Route::get('/records', [ExamController::class, 'myRecords']);
        Route::get('/records/{record}', [ExamController::class, 'showRecord']);
    });

    Route::prefix('scores')->group(function () {
        Route::get('/statistics', [ScoreController::class, 'statistics']);
        Route::get('/ranking/{examPaper}', [ScoreController::class, 'ranking']);
        Route::get('/analysis/{examPaper}', [ScoreController::class, 'analysis']);
    });

    // 主观题分层批阅：评分点、初评、复核、审计、进度、班级
    Route::prefix('grading')->group(function () {
        // 批阅工作台
        Route::get('/papers', [GradingController::class, 'papers']);
        Route::get('/papers/{examPaper}/queue', [GradingController::class, 'queue']);
        Route::get('/gradings/{grading}', [GradingController::class, 'show'])->whereNumber('grading');

        // 初评
        Route::post('/gradings/{grading}/initial', [GradingController::class, 'initialSubmit'])->whereNumber('grading');

        // 复核
        Route::get('/review-queue', [GradingController::class, 'reviewQueue']);
        Route::post('/gradings/{grading}/review', [GradingController::class, 'reviewSubmit'])->whereNumber('grading');

        // 内部讨论 / 学生评语
        Route::post('/gradings/{grading}/comments', [GradingController::class, 'storeComment'])->whereNumber('grading');

        // 争议题 / 高分样卷标记
        Route::post('/gradings/{grading}/dispute', [GradingController::class, 'toggleDispute'])->whereNumber('grading');
        Route::post('/gradings/{grading}/sample', [GradingController::class, 'toggleSample'])->whereNumber('grading');

        // 修改轨迹（每次修改原因可追溯）
        Route::get('/gradings/{grading}/audit', [GradingController::class, 'auditTrail'])->whereNumber('grading');

        // 批阅进度（按班级 + 按题目）
        Route::get('/progress', [GradingProgressController::class, 'index']);
    });

    // 主观题评分点
    Route::prefix('questions')->group(function () {
        Route::get('/{question}/rubric', [RubricController::class, 'index']);
        Route::post('/{question}/rubric', [RubricController::class, 'store']);
        Route::put('/{question}/rubric/{rubric}', [RubricController::class, 'update']);
        Route::delete('/{question}/rubric/{rubric}', [RubricController::class, 'destroy']);
    });

    // 班级管理
    Route::prefix('classes')->group(function () {
        Route::get('/options/students', [ClassController::class, 'studentOptions']);
        Route::get('/', [ClassController::class, 'index']);
        Route::post('/', [ClassController::class, 'store']);
        Route::put('/{class}', [ClassController::class, 'update']);
        Route::delete('/{class}', [ClassController::class, 'destroy']);
        Route::get('/{class}/students', [ClassController::class, 'students']);
        Route::put('/{class}/students', [ClassController::class, 'syncStudents']);
    });
});
