<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'username',
        'email',
        'password',
        'real_name',
        'role',
        'status',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
        'status' => 'boolean',
    ];

    public const ROLE_ADMIN = 'admin';
    public const ROLE_TEACHER = 'teacher';
    public const ROLE_REVIEWER = 'reviewer';
    public const ROLE_STUDENT = 'student';

    public const ROLES = [
        self::ROLE_ADMIN,
        self::ROLE_TEACHER,
        self::ROLE_REVIEWER,
        self::ROLE_STUDENT,
    ];

    public function isAdmin(): bool
    {
        return $this->role === self::ROLE_ADMIN;
    }

    public function isTeacher(): bool
    {
        return $this->role === self::ROLE_TEACHER;
    }

    public function isReviewer(): bool
    {
        return $this->role === self::ROLE_REVIEWER;
    }

    /**
     * 是否可承担初评（阅卷老师 / 管理员）
     */
    public function canInitialGrade(): bool
    {
        return $this->isAdmin() || $this->isTeacher();
    }

    /**
     * 是否可承担复核（复核老师 / 管理员）
     */
    public function canReview(): bool
    {
        return $this->isAdmin() || $this->isReviewer();
    }

    public function isStudent(): bool
    {
        return $this->role === self::ROLE_STUDENT;
    }

    public function questions()
    {
        return $this->hasMany(Question::class, 'created_by');
    }

    public function examPapers()
    {
        return $this->hasMany(ExamPaper::class, 'created_by');
    }

    public function examRecords()
    {
        return $this->hasMany(ExamRecord::class, 'user_id');
    }

    public function classes()
    {
        return $this->belongsToMany(SchoolClass::class, 'class_student', 'user_id', 'class_id')
            ->withTimestamps();
    }
}
