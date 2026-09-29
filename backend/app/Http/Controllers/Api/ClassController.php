<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SchoolClass;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class ClassController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        if (!$user->canInitialGrade() && !$user->canReview()) {
            return response()->json(['message' => '无权访问'], 403);
        }

        $classes = SchoolClass::withCount(['students'])
            ->where('status', 1)
            ->orderBy('id')
            ->get()
            ->map(fn($c) => [
                'id' => $c->id,
                'name' => $c->name,
                'description' => $c->description,
                'student_count' => $c->students_count,
            ]);

        return response()->json(['classes' => $classes]);
    }

    public function store(Request $request)
    {
        $user = $request->user();
        if (!$user->isAdmin()) {
            return response()->json(['message' => '仅管理员可以创建班级'], 403);
        }

        $data = $this->validateData($request);
        $class = SchoolClass::create([
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'created_by' => $user->id,
            'status' => 1,
        ]);

        return response()->json(['message' => '班级创建成功', 'class' => $class], 201);
    }

    public function update(Request $request, SchoolClass $class)
    {
        if (!$request->user()->isAdmin()) {
            return response()->json(['message' => '仅管理员可以修改班级'], 403);
        }

        $data = $this->validateData($request, $class->id);
        $class->update([
            'name' => $data['name'],
            'description' => $data['description'] ?? $class->description,
        ]);

        return response()->json(['message' => '班级更新成功', 'class' => $class]);
    }

    public function destroy(Request $request, SchoolClass $class)
    {
        if (!$request->user()->isAdmin()) {
            return response()->json(['message' => '仅管理员可以删除班级'], 403);
        }

        DB::transaction(function () use ($class) {
            DB::table('class_student')->where('class_id', $class->id)->delete();
            $class->delete();
        });

        return response()->json(['message' => '班级已删除']);
    }

    public function students(Request $request, SchoolClass $class)
    {
        $user = $request->user();
        if (!$user->canInitialGrade() && !$user->canReview()) {
            return response()->json(['message' => '无权访问'], 403);
        }

        $students = $class->students()
            ->where('status', 1)
            ->orderBy('id')
            ->get(['users.id', 'username', 'real_name', 'email'])
            ->map(fn($s) => [
                'id' => $s->id,
                'username' => $s->username,
                'real_name' => $s->real_name,
                'email' => $s->email,
            ]);

        return response()->json([
            'class' => ['id' => $class->id, 'name' => $class->name],
            'students' => $students,
        ]);
    }

    /**
     * 设置班级学生（全量覆盖）
     */
    public function syncStudents(Request $request, SchoolClass $class)
    {
        if (!$request->user()->isAdmin()) {
            return response()->json(['message' => '仅管理员可以分配学生'], 403);
        }

        $validator = Validator::make($request->all(), [
            'student_ids' => 'present|array',
            'student_ids.*' => 'integer|exists:users,id',
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $ids = User::whereIn('id', $request->input('student_ids', []))
            ->where('role', User::ROLE_STUDENT)
            ->pluck('id');

        DB::transaction(function () use ($class, $ids) {
            DB::table('class_student')->where('class_id', $class->id)->delete();
            $rows = $ids->map(fn($id) => ['class_id' => $class->id, 'user_id' => $id, 'created_at' => now(), 'updated_at' => now()])->all();
            DB::table('class_student')->insert($rows);
        });

        return response()->json(['message' => '学生分配已更新', 'student_count' => $ids->count()]);
    }

    /**
     * 可选学生列表（用于分配）
     */
    public function studentOptions(Request $request)
    {
        if (!$request->user()->isAdmin()) {
            return response()->json(['message' => '无权访问'], 403);
        }

        $students = User::where('role', User::ROLE_STUDENT)
            ->where('status', 1)
            ->orderBy('id')
            ->get(['id', 'username', 'real_name', 'email']);

        return response()->json(['students' => $students]);
    }

    private function validateData(Request $request, ?int $ignoreId = null): array
    {
        $rule = 'required|string|max:100|unique:classes,name' . ($ignoreId ? ",{$ignoreId}" : '');

        return $request->validate([
            'name' => $rule,
            'description' => 'nullable|string|max:255',
        ]);
    }
}
