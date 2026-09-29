<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SchoolClass extends Model
{
    protected $table = 'classes';

    protected $fillable = [
        'name',
        'description',
        'created_by',
        'status',
    ];

    protected $casts = [
        'created_by' => 'integer',
        'status' => 'boolean',
    ];

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function students()
    {
        return $this->belongsToMany(User::class, 'class_student', 'class_id', 'user_id')
            ->withTimestamps();
    }
}
