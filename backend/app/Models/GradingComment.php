<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GradingComment extends Model
{
    protected $fillable = [
        'essay_grading_id',
        'author_id',
        'content',
        'visibility',
    ];

    protected $casts = [
        'essay_grading_id' => 'integer',
        'author_id' => 'integer',
    ];

    // 老师内部讨论（学生不可见）
    public const VISIBILITY_INTERNAL = 'internal';
    // 对学生可见的简短评语
    public const VISIBILITY_STUDENT = 'student';

    public function author()
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function grading()
    {
        return $this->belongsTo(EssayGrading::class, 'essay_grading_id');
    }
}
