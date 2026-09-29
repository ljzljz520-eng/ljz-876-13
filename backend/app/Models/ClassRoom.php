<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ClassRoom extends Model
{
    use HasFactory;

    protected $table = 'classes';

    protected $fillable = [
        'name',
    ];

    public function students()
    {
        return $this->hasMany(User::class, 'class_id')->where('role', User::ROLE_STUDENT);
    }
}
