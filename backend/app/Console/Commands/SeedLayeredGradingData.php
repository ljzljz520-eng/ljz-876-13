<?php

namespace App\Console\Commands;

use App\Models\ExamPaper;
use App\Models\GradingRubricPoint;
use App\Models\Question;
use App\Models\SchoolClass;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * 幂等填充：分层批阅所需的复核老师账号、班级、演示主观题及评分点。
 * 可重复执行，已存在的数据不会重复创建。
 */
class SeedLayeredGradingData extends Command
{
    protected $signature = 'grading:seed-demo';

    protected $description = '幂等填充分层批阅演示数据（复核老师、班级、主观题、评分点）';

    public function handle(): int
    {
        // 1. 复核老师
        $reviewer = User::firstOrCreate(
            ['email' => 'reviewer@example.com'],
            [
                'username' => 'reviewer',
                'password' => Hash::make('password'),
                'real_name' => '复核老师',
                'role' => User::ROLE_REVIEWER,
                'status' => 1,
            ]
        );
        $this->info("复核老师账号：{$reviewer->email} / password");

        // 2. 学生
        $student1 = User::firstOrCreate(
            ['email' => 'student1@example.com'],
            ['username' => 'student1', 'password' => Hash::make('password'), 'real_name' => '学生用户1', 'role' => 'student', 'status' => 1]
        );
        $student2 = User::firstOrCreate(
            ['email' => 'student2@example.com'],
            ['username' => 'student2', 'password' => Hash::make('password'), 'real_name' => '学生用户2', 'role' => 'student', 'status' => 1]
        );

        // 3. 班级
        $teacher = User::where('email', 'teacher@example.com')->first();
        $class1 = SchoolClass::firstOrCreate(['name' => '计算机一班'], ['description' => '2026 级计算机专业 1 班', 'created_by' => $teacher?->id, 'status' => 1]);
        $class2 = SchoolClass::firstOrCreate(['name' => '计算机二班'], ['description' => '2026 级计算机专业 2 班', 'created_by' => $teacher?->id, 'status' => 1]);
        DB::table('class_student')->insertOrIgnore([
            ['class_id' => $class1->id, 'user_id' => $student1->id],
            ['class_id' => $class1->id, 'user_id' => $student2->id],
        ]);
        $this->info("班级：{$class1->name}、{$class2->name}");

        // 4. 演示主观题 + 评分点
        $q1 = Question::where('type', Question::TYPE_ESSAY)
            ->where('title', 'like', '%ACID%')->first();
        if (!$q1) {
            $q1 = Question::create([
                'category_id' => 8,
                'type' => Question::TYPE_ESSAY,
                'title' => '请简述事务的 ACID 特性，并说明隔离性的作用。',
                'options' => null,
                'answer' => '原子性、一致性、隔离性、持久性；隔离性保证并发事务互不干扰。',
                'analysis' => '分别解释四个特性，答出 ACID 全称并解释隔离性即可满分',
                'difficulty' => 2,
                'score' => 5,
                'created_by' => $teacher?->id,
                'status' => 1,
            ]);
            GradingRubricPoint::create(['question_id' => $q1->id, 'title' => 'ACID 四个特性', 'description' => '答出原子性、一致性、隔离性、持久性四项，每项 0.75 分', 'score' => 3, 'sort_order' => 1]);
            GradingRubricPoint::create(['question_id' => $q1->id, 'title' => '隔离性的作用', 'description' => '说明隔离性保证并发事务之间互不干扰，避免脏读/不可重复读等', 'score' => 2, 'sort_order' => 2]);
        }

        $q2 = Question::where('type', Question::TYPE_ESSAY)
            ->where('title', 'like', '%数组与链表%')->first();
        if (!$q2) {
            $q2 = Question::create([
                'category_id' => 5,
                'type' => Question::TYPE_ESSAY,
                'title' => '请比较数组与链表在内存布局和插入/删除效率上的差异。',
                'options' => null,
                'answer' => '数组连续存储、支持随机访问但中间插入删除需移动元素；链表离散存储、插入删除 O(1) 但不支持随机访问。',
                'analysis' => '从内存连续性、随机访问、插入删除代价三个方面比较',
                'difficulty' => 2,
                'score' => 5,
                'created_by' => $teacher?->id,
                'status' => 1,
            ]);
            GradingRubricPoint::create(['question_id' => $q2->id, 'title' => '内存布局差异', 'description' => '数组连续存储可随机访问；链表离散存储靠指针链接', 'score' => 1.5, 'sort_order' => 1]);
            GradingRubricPoint::create(['question_id' => $q2->id, 'title' => '插入删除效率差异', 'description' => '数组中间插入删除需移动元素 O(n)；链表修改指针即可 O(1)', 'score' => 1.5, 'sort_order' => 2]);
        }

        // 5. 演示试卷
        $paper = ExamPaper::firstOrCreate(
            ['title' => '主观题模拟测验'],
            ['description' => '包含客观题与主观题，主观题采用分层批阅（初评 + 复核）', 'total_score' => 8, 'total_time' => 30, 'question_count' => 2, 'type' => 'fixed', 'created_by' => $teacher?->id, 'status' => 1]
        );
        DB::table('exam_paper_questions')->insertOrIgnore([
            ['exam_paper_id' => $paper->id, 'question_id' => $q1->id, 'sort_order' => 1, 'score' => 5, 'created_at' => now()],
            ['exam_paper_id' => $paper->id, 'question_id' => $q2->id, 'sort_order' => 2, 'score' => 3, 'created_at' => now()],
        ]);
        $paper->updateQuestionCountAndScore();

        $this->info('演示试卷：' . $paper->title);
        $this->info('分层批阅演示数据已就绪。');

        return self::SUCCESS;
    }
}
