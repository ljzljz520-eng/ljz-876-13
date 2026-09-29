<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ClassRoom;
use Illuminate\Http\Request;

class ClassRoomController extends Controller
{
    /**
     * 班级列表（注册页与统计筛选共用，公开只读）。
     */
    public function index(Request $request)
    {
        $classes = ClassRoom::orderBy('id')->get(['id', 'name']);

        return response()->json(['classes' => $classes]);
    }
}
