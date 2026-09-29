<?php
/**
 * 分层批阅功能端到端验证脚本（SQLite 环境，不依赖 MySQL/Docker）
 * 运行: php e2e-test.php
 */
putenv('DB_CONNECTION=sqlite');
putenv('DB_DATABASE=' . dirname(__DIR__) . '/database/e2e.sqlite');
putenv('APP_ENV=testing');
putenv('CACHE_STORE=array');
putenv('SESSION_DRIVER=array');
putenv('QUEUE_CONNECTION=sync');

@unlink(dirname(__DIR__) . '/database/e2e.sqlite');
touch(dirname(__DIR__) . '/database/e2e.sqlite');

require dirname(__DIR__) . '/vendor/autoload.php';
$app = require dirname(__DIR__) . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

// ============ 建表（SQLite 兼容，与 docker-compose 中 MySQL 结构等价） ============
Schema::create('users', function (Blueprint $t) {
    $t->id();
    $t->string('username', 50)->unique();
    $t->string('email', 100)->unique();
    $t->string('password');
    $t->string('real_name', 50)->nullable();
    $t->string('role')->default('student');
    $t->boolean('status')->default(1);
    $t->unsignedBigInteger('class_id')->nullable();
    $t->boolean('can_review')->default(0);
    $t->timestamps();
});
Schema::create('personal_access_tokens', function (Blueprint $t) {
    $t->id();
    $t->morphs('tokenable');
    $t->string('name');
    $t->string('token', 64)->unique();
    $t->text('abilities')->nullable();
    $t->timestamp('last_used_at')->nullable();
    $t->timestamp('expires_at')->nullable();
    $t->timestamps();
});
Schema::create('classes', function (Blueprint $t) {
    $t->id();
    $t->string('name', 100);
    $t->timestamps();
});
Schema::create('question_categories', function (Blueprint $t) {
    $t->id();
    $t->string('name', 100);
    $t->unsignedBigInteger('parent_id')->default(0);
    $t->integer('sort_order')->default(0);
    $t->boolean('status')->default(1);
    $t->timestamps();
});
Schema::create('questions', function (Blueprint $t) {
    $t->id();
    $t->unsignedBigInteger('category_id');
    $t->string('type');
    $t->text('title');
    $t->text('options')->nullable();
    $t->text('answer');
    $t->text('analysis')->nullable();
    $t->tinyInteger('difficulty')->default(1);
    $t->decimal('score', 5, 2)->default(1);
    $t->unsignedBigInteger('created_by')->nullable();
    $t->boolean('status')->default(1);
    $t->timestamps();
});
Schema::create('question_rubric_points', function (Blueprint $t) {
    $t->id();
    $t->unsignedBigInteger('question_id');
    $t->string('title', 200);
    $t->decimal('max_score', 5, 2);
    $t->integer('sort_order')->default(0);
    $t->timestamps();
});
Schema::create('exam_papers', function (Blueprint $t) {
    $t->id();
    $t->string('title', 200);
    $t->text('description')->nullable();
    $t->decimal('total_score', 5, 2);
    $t->integer('total_time')->default(60);
    $t->integer('question_count');
    $t->string('type')->default('fixed');
    $t->boolean('status')->default(1);
    $t->unsignedBigInteger('created_by')->nullable();
    $t->timestamps();
});
Schema::create('exam_paper_questions', function (Blueprint $t) {
    $t->id();
    $t->unsignedBigInteger('exam_paper_id');
    $t->unsignedBigInteger('question_id');
    $t->integer('sort_order')->default(0);
    $t->decimal('score', 5, 2);
    $t->timestamp('created_at')->nullable();
});
Schema::create('exam_records', function (Blueprint $t) {
    $t->id();
    $t->unsignedBigInteger('user_id');
    $t->unsignedBigInteger('exam_paper_id');
    $t->timestamp('start_time');
    $t->timestamp('end_time')->nullable();
    $t->decimal('score', 5, 2)->default(0);
    $t->string('status')->default('in_progress');
    $t->timestamps();
});
Schema::create('exam_record_answers', function (Blueprint $t) {
    $t->id();
    $t->unsignedBigInteger('exam_record_id');
    $t->unsignedBigInteger('question_id');
    $t->text('answer');
    $t->boolean('is_correct')->default(0);
    $t->decimal('score', 5, 2)->default(0);
    $t->string('grading_status')->default('none');
    $t->string('review_flag')->default('none');
    $t->text('student_comment')->nullable();
    $t->timestamps();
});
Schema::create('grading_records', function (Blueprint $t) {
    $t->id();
    $t->unsignedBigInteger('exam_record_answer_id');
    $t->unsignedBigInteger('grader_id');
    $t->string('stage');
    $t->decimal('total_score', 5, 2);
    $t->text('comment')->nullable();
    $t->text('internal_note')->nullable();
    $t->string('reason', 500)->nullable();
    $t->string('review_flag')->default('none');
    $t->timestamp('created_at')->nullable();
});
Schema::create('grading_record_points', function (Blueprint $t) {
    $t->id();
    $t->unsignedBigInteger('grading_record_id');
    $t->unsignedBigInteger('rubric_point_id');
    $t->decimal('score', 5, 2);
    $t->timestamp('created_at')->nullable();
});

// ============ 测试数据 ============
$hash = password_hash('password', PASSWORD_BCRYPT);
DB::table('classes')->insert([['id'=>1,'name'=>'高三(1)班'],['id'=>2,'name'=>'高三(2)班']]);
DB::table('users')->insert([
    ['id'=>1,'username'=>'admin','email'=>'admin@example.com','password'=>$hash,'real_name'=>'管理员','role'=>'admin','status'=>1,'class_id'=>null,'can_review'=>0],
    ['id'=>2,'username'=>'teacher','email'=>'teacher@example.com','password'=>$hash,'real_name'=>'阅卷老师','role'=>'teacher','status'=>1,'class_id'=>null,'can_review'=>0],
    ['id'=>3,'username'=>'reviewer','email'=>'reviewer@example.com','password'=>$hash,'real_name'=>'复核老师','role'=>'teacher','status'=>1,'class_id'=>null,'can_review'=>1],
    ['id'=>4,'username'=>'student1','email'=>'student1@example.com','password'=>$hash,'real_name'=>'学生甲','role'=>'student','status'=>1,'class_id'=>1,'can_review'=>0],
    ['id'=>5,'username'=>'student2','email'=>'student2@example.com','password'=>$hash,'real_name'=>'学生乙','role'=>'student','status'=>1,'class_id'=>1,'can_review'=>0],
    ['id'=>6,'username'=>'student3','email'=>'student3@example.com','password'=>$hash,'real_name'=>'学生丙','role'=>'student','status'=>1,'class_id'=>2,'can_review'=>0],
]);
DB::table('question_categories')->insert([['id'=>1,'name'=>'操作系统']]);
DB::table('questions')->insert([
    ['id'=>1,'category_id'=>1,'type'=>'single_choice','title'=>'下列哪个不是操作系统？','options'=>'{"A":"Windows","B":"Linux","C":"Photoshop","D":"macOS"}','answer'=>'C','analysis'=>null,'difficulty'=>1,'score'=>2,'created_by'=>2,'status'=>1],
    ['id'=>2,'category_id'=>1,'type'=>'essay','title'=>'简述操作系统的主要功能。','options'=>null,'answer'=>'参考答案：进程管理、存储管理、文件管理、设备管理、用户接口。','analysis'=>null,'difficulty'=>2,'score'=>8,'created_by'=>2,'status'=>1],
    ['id'=>3,'category_id'=>1,'type'=>'essay','title'=>'什么是死锁？','options'=>null,'answer'=>'参考答案：死锁概念 + 四个必要条件。','analysis'=>null,'difficulty'=>2,'score'=>9,'created_by'=>2,'status'=>1],
]);
DB::table('question_rubric_points')->insert([
    ['id'=>1,'question_id'=>2,'title'=>'进程管理','max_score'=>2,'sort_order'=>1],
    ['id'=>2,'question_id'=>2,'title'=>'存储管理','max_score'=>2,'sort_order'=>2],
    ['id'=>3,'question_id'=>2,'title'=>'文件管理','max_score'=>2,'sort_order'=>3],
    ['id'=>4,'question_id'=>2,'title'=>'设备管理与用户接口','max_score'=>2,'sort_order'=>4],
    ['id'=>5,'question_id'=>3,'title'=>'死锁概念阐述','max_score'=>3,'sort_order'=>1],
    ['id'=>6,'question_id'=>3,'title'=>'互斥条件','max_score'=>1.5,'sort_order'=>2],
    ['id'=>7,'question_id'=>3,'title'=>'占有且等待条件','max_score'=>1.5,'sort_order'=>3],
    ['id'=>8,'question_id'=>3,'title'=>'不可剥夺条件','max_score'=>1.5,'sort_order'=>4],
    ['id'=>9,'question_id'=>3,'title'=>'循环等待条件','max_score'=>1.5,'sort_order'=>5],
]);
DB::table('exam_papers')->insert([['id'=>1,'title'=>'操作系统综合测验','description'=>'含主观题','total_score'=>19,'total_time'=>45,'question_count'=>3,'type'=>'fixed','status'=>1,'created_by'=>2]]);
DB::table('exam_paper_questions')->insert([
    ['exam_paper_id'=>1,'question_id'=>1,'sort_order'=>1,'score'=>2],
    ['exam_paper_id'=>1,'question_id'=>2,'sort_order'=>2,'score'=>8],
    ['exam_paper_id'=>1,'question_id'=>3,'sort_order'=>3,'score'=>9],
]);

// ============ HTTP 请求辅助 ============
$tokens = [];
function handleRequest($method, $uri, $server, $body) {
    global $kernel;
    // 限流(429)时清空限流计数并重试（仅测试环境）
    for ($i = 0; $i < 3; $i++) {
        app('auth')->forgetGuards();
        $req = Illuminate\Http\Request::create($uri, $method, [], [], [], $server, $body);
        $resp = $kernel->handle($req);
        if ($resp->getStatusCode() !== 429) return $resp;
        
        app('cache')->flush();
    }
    return $resp;
}
function loginAs($email) {
    global $tokens;
    $resp = handleRequest('POST', '/api/auth/login', ['CONTENT_TYPE'=>'application/json', 'HTTP_ACCEPT'=>'application/json'], json_encode(['email'=>$email,'password'=>'password']));
    $data = json_decode($resp->getContent(), true);
    if ($resp->getStatusCode() !== 200) { fail("登录失败 $email: " . $resp->getContent()); }
    $tokens[$email] = $data['token'];
    return $data['token'];
}
function api($method, $uri, $email, $body = null) {
    global $tokens;
    $server = ['CONTENT_TYPE'=>'application/json', 'HTTP_ACCEPT'=>'application/json'];
    if ($email) $server['HTTP_AUTHORIZATION'] = 'Bearer ' . $tokens[$email];
    $resp = handleRequest($method, $uri, $server, $body ? json_encode($body) : null);
    return ['status' => $resp->getStatusCode(), 'data' => json_decode($resp->getContent(), true)];
}

$passed = 0; $failed = 0;
function check($label, $condition, $extra = '') {
    global $passed, $failed;
    if ($condition) { $passed++; echo "  ✓ $label\n"; }
    else { $failed++; echo "  ✗ $label  $extra\n"; }
}
function fail($msg) { echo "FATAL: $msg\n"; exit(1); }

echo "== 登录各角色 ==\n";
loginAs('student1@example.com'); loginAs('student2@example.com'); loginAs('student3@example.com');
loginAs('teacher@example.com'); loginAs('reviewer@example.com'); loginAs('admin@example.com');
check('6 个角色登录成功', count($tokens) === 6);

echo "\n== 学生参加考试并提交（含主观题）==\n";
foreach (['student1@example.com','student2@example.com','student3@example.com'] as $i => $email) {
    $r = api('POST', '/api/exams/1/start', $email);
    check("$email 开始考试", $r['status'] === 200);
    $recordId = $r['data']['exam_record']['id'];
    $answers = [
        ['question_id'=>1,'answer'=>'C'],
        ['question_id'=>2,'answer'=>'操作系统功能包括进程管理、存储管理、文件管理、设备管理和用户接口。'],
        ['question_id'=>3,'answer'=>'死锁是多个进程互相等待资源造成的僵局。必要条件：互斥、占有且等待、不可剥夺、循环等待。'],
    ];
    if ($i === 2) { $answers[2]['answer'] = '不知道'; } // student3 答得差
    $r = api('POST', '/api/exams/1/submit', $email, ['exam_record_id'=>$recordId,'answers'=>$answers]);
    check("$email 提交成功且标记含主观题", $r['status'] === 200 && $r['data']['has_subjective'] === true, json_encode($r['data']));
    check("$email 客观题自动评分=2", $r['status'] === 200 && (float)$r['data']['score'] === 2.0);
    check("$email 答卷状态为待批阅", $r['data']['exam_record']['status'] === 'submitted');
}

echo "\n== 权限控制 ==\n";
$r = api('GET', '/api/grading/tasks?exam_paper_id=1', 'student1@example.com');
check('学生访问批阅任务被拒(403)', $r['status'] === 403);
$r = api('GET', '/api/grading/review-queue?exam_paper_id=1', 'teacher@example.com');
check('普通阅卷老师访问复核队列被拒(403)', $r['status'] === 403);
$r = api('GET', '/api/grading/review-queue?exam_paper_id=1', 'reviewer@example.com');
if ($r['status'] !== 200) { echo "DEBUG review-queue: " . substr(json_encode($r['data'], JSON_UNESCAPED_UNICODE), 0, 500) . "\n"; }
check('复核老师可访问复核队列', $r['status'] === 200);

echo "\n== 阅卷老师初评 ==\n";
$r = api('GET', '/api/grading/tasks?exam_paper_id=1', 'teacher@example.com');
if ($r['status'] !== 200) { echo "DEBUG tasks: " . substr(json_encode($r['data'], JSON_UNESCAPED_UNICODE), 0, 500) . "\n"; }
check('获取待初评任务', $r['status'] === 200 && $r['data']['tasks']['total'] === 6, 'total=' . ($r['data']['tasks']['total'] ?? '?'));
$tasks = collect($r['data']['tasks']['data']);
// 学生甲 Q2: 满分水平 8/8 → 应自动进高分样卷
$t1 = $tasks->firstWhere('student.username', 'student1');
$q2t1 = collect($tasks)->where('student.username','student1')->where('question.id',2)->first();
$q3t1 = collect($tasks)->where('student.username','student1')->where('question.id',3)->first();
$r = api('POST', "/api/grading/answers/{$q2t1['id']}/initial", 'teacher@example.com', [
    'points'=>[['rubric_point_id'=>1,'score'=>2],['rubric_point_id'=>2,'score'=>2],['rubric_point_id'=>3,'score'=>2],['rubric_point_id'=>4,'score'=>2]],
    'comment'=>'要点齐全，表述清晰','internal_note'=>'这份答得很好，建议作为样卷',
]);
check('学生甲Q2初评 8/8', $r['status'] === 200 && (float)$r['data']['answer']['score'] === 8.0, json_encode($r['data'] ?? []));
check('满分自动标记高分样卷', $r['data']['review_flag'] === 'high_score');
// 学生甲 Q3: 7.5/9 = 83% < 85%，不标记；但老师手动标记争议
$r = api('POST', "/api/grading/answers/{$q3t1['id']}/initial", 'teacher@example.com', [
    'points'=>[['rubric_point_id'=>5,'score'=>3],['rubric_point_id'=>6,'score'=>1.5],['rubric_point_id'=>7,'score'=>1.5],['rubric_point_id'=>8,'score'=>1.5],['rubric_point_id'=>9,'score'=>0]],
    'comment'=>'循环等待条件未答出','needs_review'=>true,'internal_note'=>'循环等待这点他写了一半，拿不准，请复核把关',
]);
check('学生甲Q3初评 7.5/9 并手动标记争议', $r['status'] === 200 && $r['data']['review_flag'] === 'disputed');
// 学生乙 Q2/Q3 普通分，直接定稿
$q2t2 = collect($tasks)->where('student.username','student2')->where('question.id',2)->first();
$q3t2 = collect($tasks)->where('student.username','student2')->where('question.id',3)->first();
$r = api('POST', "/api/grading/answers/{$q2t2['id']}/initial", 'teacher@example.com', [
    'points'=>[['rubric_point_id'=>1,'score'=>2],['rubric_point_id'=>2,'score'=>1],['rubric_point_id'=>3,'score'=>1.5],['rubric_point_id'=>4,'score'=>1]],
    'comment'=>'基本要点具备',
]);
check('学生乙Q2初评 5.5/8 直接定稿', $r['status'] === 200 && $r['data']['review_flag'] === 'none' && $r['data']['answer']['grading_status'] === 'finalized');
$r = api('POST', "/api/grading/answers/{$q3t2['id']}/initial", 'teacher@example.com', [
    'points'=>[['rubric_point_id'=>5,'score'=>2],['rubric_point_id'=>6,'score'=>1],['rubric_point_id'=>7,'score'=>1],['rubric_point_id'=>8,'score'=>1],['rubric_point_id'=>9,'score'=>1]],
    'comment'=>'概念需加强',
]);
check('学生乙Q3初评 6/9 直接定稿', $r['status'] === 200 && $r['data']['answer']['grading_status'] === 'finalized');
// 学生丙 Q2 低分定稿，Q3 留白待初评（测试进度统计）
$q2t3 = collect($tasks)->where('student.username','student3')->where('question.id',2)->first();
$r = api('POST', "/api/grading/answers/{$q2t3['id']}/initial", 'teacher@example.com', [
    'points'=>[['rubric_point_id'=>1,'score'=>1],['rubric_point_id'=>2,'score'=>0.5],['rubric_point_id'=>3,'score'=>0],['rubric_point_id'=>4,'score'=>0]],
    'comment'=>'回答过于简略',
]);
check('学生丙Q2初评 1.5/8', $r['status'] === 200);

echo "\n== 初评校验 ==\n";
$q3t3 = collect($tasks)->where('student.username','student3')->where('question.id',3)->first();
$r = api('POST', "/api/grading/answers/{$q3t3['id']}/initial", 'teacher@example.com', [
    'points'=>[['rubric_point_id'=>5,'score'=>3],['rubric_point_id'=>6,'score'=>1.5],['rubric_point_id'=>7,'score'=>1.5],['rubric_point_id'=>8,'score'=>1.5],['rubric_point_id'=>9,'score'=>99]],
]);
check('评分点超上限被拒(422)', $r['status'] === 422);
$r = api('POST', "/api/grading/answers/{$q2t3['id']}/initial", 'teacher@example.com', [
    'points'=>[['rubric_point_id'=>1,'score'=>1],['rubric_point_id'=>2,'score'=>1],['rubric_point_id'=>3,'score'=>1],['rubric_point_id'=>4,'score'=>1]],
]);
check('重复初评被拒(422)', $r['status'] === 422);

echo "\n== 复核队列（只看争议+高分样卷）==\n";
$r = api('GET', '/api/grading/review-queue?exam_paper_id=1', 'reviewer@example.com');
if ($r['status'] !== 200) { echo "DEBUG queue: " . substr(json_encode($r['data'], JSON_UNESCAPED_UNICODE), 0, 400) . "\n"; }
check('复核队列恰有 2 条', $r['status'] === 200 && $r['data']['queue']['total'] === 2, 'total=' . ($r['data']['queue']['total'] ?? '?'));
$flags = collect($r['data']['queue']['data'])->pluck('review_flag')->sort()->values()->toArray();
check('队列只含争议和高分样卷', $flags === ['disputed','high_score'], json_encode($flags));
$first = $r['data']['queue']['data'][0];
check('队列包含初评信息与内部备注', isset($first['initial_grading']['grader']['name']) && isset($first['initial_grading']['internal_note']));

echo "\n== 复核操作 ==\n";
// 争议题：调整分数，必须填原因
$disputed = collect($r['data']['queue']['data'])->firstWhere('review_flag','disputed');
$r = api('POST', "/api/grading/answers/{$disputed['id']}/review", 'reviewer@example.com', ['action'=>'adjust','points'=>[['rubric_point_id'=>5,'score'=>3],['rubric_point_id'=>6,'score'=>1.5],['rubric_point_id'=>7,'score'=>1.5],['rubric_point_id'=>8,'score'=>1.5],['rubric_point_id'=>9,'score'=>1]],'reason'=>'']);
check('调整分数不填原因被拒(422)', $r['status'] === 422);
$r = api('POST', "/api/grading/answers/{$disputed['id']}/review", 'reviewer@example.com', [
    'action'=>'adjust',
    'points'=>[['rubric_point_id'=>5,'score'=>3],['rubric_point_id'=>6,'score'=>1.5],['rubric_point_id'=>7,'score'=>1.5],['rubric_point_id'=>8,'score'=>1.5],['rubric_point_id'=>9,'score'=>1]],
    'reason'=>'循环等待条件学生有提及关键词，酌情给 1 分','comment'=>'四个必要条件基本掌握',
]);
check('争议题复核调整 7.5→8.5', $r['status'] === 200 && (float)$r['data']['answer']['score'] === 8.5, json_encode($r['data'] ?? []));
check('复核后定稿', $r['data']['answer']['grading_status'] === 'finalized');
// 高分样卷：维持原评
$rq = api('GET', '/api/grading/review-queue?exam_paper_id=1', 'reviewer@example.com');
$highScore = collect($rq['data']['queue']['data'])->firstWhere('review_flag','high_score');
$r = api('POST', "/api/grading/answers/{$highScore['id']}/review", 'reviewer@example.com', ['action'=>'confirm']);
check('高分样卷复核维持原评', $r['status'] === 200 && (float)$r['data']['answer']['score'] === 8.0);
$r = api('GET', '/api/grading/review-queue?exam_paper_id=1', 'reviewer@example.com');
if ($r['status'] !== 200) { echo "DEBUG queue2: " . substr(json_encode($r['data'], JSON_UNESCAPED_UNICODE), 0, 400) . "\n"; }
check('复核队列已清空', $r['status'] === 200 && $r['data']['queue']['total'] === 0);

echo "\n== 批阅历史可追溯 ==\n";
$r = api('GET', "/api/grading/answers/{$disputed['id']}/history", 'teacher@example.com');
check('历史含初评+复核两条', $r['status'] === 200 && count($r['data']['history']) === 2);
$h0 = $r['data']['history'][0]; $h1 = $r['data']['history'][1];
check('初评记录含批阅人与分数', $h0['stage']==='initial' && $h0['grader']['name']==='阅卷老师' && (float)$h0['total_score']===7.5);
check('复核记录含修改原因', $h1['stage']==='review' && str_contains($h1['reason'],'酌情给 1 分'));
check('复核记录含内部备注字段', array_key_exists('internal_note', $h1));
$r = api('GET', "/api/grading/answers/{$disputed['id']}/history", 'student1@example.com');
check('学生不可查批阅历史(403)', $r['status'] === 403);

echo "\n== 答卷状态流转 ==\n";
$rec1 = DB::table('exam_records')->where('user_id', 4)->first();
check('学生甲答卷全部定稿→已评分', $rec1->status === 'graded');
check('学生甲总分=客观2+Q2 8+Q3 8.5=18.5', (float)$rec1->score === 18.5, 'score=' . $rec1->score);
$rec2 = DB::table('exam_records')->where('user_id', 5)->first();
check('学生乙答卷已评分 总分=2+5.5+6=13.5', $rec2->status === 'graded' && (float)$rec2->score === 13.5);
$rec3 = DB::table('exam_records')->where('user_id', 6)->first();
check('学生丙Q3未初评→仍待批阅', $rec3->status === 'submitted');

echo "\n== 学生查看成绩（可见性控制）==\n";
$r = api('GET', "/api/exams/records/{$rec1->id}", 'student1@example.com');
check('学生可查看自己成绩详情', $r['status'] === 200);
$essayAnswers = collect($r['data']['record']['answers'])->where('question.type','essay')->values();
$q2a = $essayAnswers->firstWhere('question_id', 2);
check('可见得分点明细', is_array($q2a['rubric_scores']) && count($q2a['rubric_scores']) === 4);
check('得分点含标题和得分', $q2a['rubric_scores'][0]['title'] === '进程管理' && (float)$q2a['rubric_scores'][0]['score'] === 2.0);
check('可见老师评语', $q2a['student_comment'] === '要点齐全，表述清晰');
$raw = json_encode($r['data'], JSON_UNESCAPED_UNICODE);
check('响应不含内部备注内容', !str_contains($raw, '建议作为样卷') && !str_contains($raw, '拿不准'));
check('响应不含修改原因字段', !str_contains($raw, '酌情给 1 分') && !str_contains($raw, 'internal_note') && !str_contains($raw, 'reason'));
check('响应不含批阅人信息', !str_contains($raw, '阅卷老师') && !str_contains($raw, '复核老师'));
check('响应不泄露标准答案', !str_contains($raw, '参考答案'));
$r = api('GET', "/api/exams/records/{$rec1->id}", 'student2@example.com');
check('学生不可看他人成绩(403)', $r['status'] === 403);

echo "\n== 进度统计 ==\n";
$r = api('GET', '/api/grading/progress/1', 'teacher@example.com');
check('进度接口可用', $r['status'] === 200);
$byClass = collect($r['data']['by_class'])->keyBy('class_id');
$c1 = $byClass[1]; $c2 = $byClass[2];
check('1班: 2学生/2交卷/1完成', $c1['total_students']===2 && $c1['submitted_records']===2 && $c1['fully_graded_records']===2, json_encode($c1));
check('1班: 已定稿4 待初评0 待复核0', $c1['finalized_answers']===4 && $c1['pending_answers']===0 && $c1['reviewing_answers']===0);
check('2班: 1学生/1交卷/0完成/待初评1', $c2['total_students']===1 && $c2['submitted_records']===1 && $c2['fully_graded_records']===0 && $c2['pending_answers']===1, json_encode($c2));
$byQ = collect($r['data']['by_question'])->keyBy('question_id');
check('Q2: 3答卷/3定稿', $byQ[2]['total']===3 && $byQ[2]['finalized']===3);
check('Q3: 3答卷/2定稿/1待初评', $byQ[3]['total']===3 && $byQ[3]['finalized']===2 && $byQ[3]['pending']===1);

echo "\n== 评分点管理 ==\n";
$r = api('PUT', '/api/questions/2/rubric-points', 'teacher@example.com', ['rubric_points'=>[
    ['id'=>1,'title'=>'进程管理(更新)','max_score'=>2],
    ['title'=>'新增评分点','max_score'=>1],
]]);
check('评分点同步(更新+新增)', $r['status']===200);
$pts = collect($r['data']['rubric_points']);
check('更新生效', $pts->firstWhere('id',1)['title'] === '进程管理(更新)');
// 学生初评引用了全部 4 个评分点，故删除时全部被保留
check('被引用评分点删除被跳过', in_array('存储管理', $r['data']['skipped_referenced'] ?? []) && in_array('文件管理', $r['data']['skipped_referenced'] ?? []), json_encode($r['data']['skipped_referenced'] ?? []));
// 未引用评分点应被真正删除：先删掉上一步新增的，验证删除路径
$r = api('PUT', '/api/questions/2/rubric-points', 'teacher@example.com', ['rubric_points'=>[
    ['id'=>1,'title'=>'进程管理(更新)','max_score'=>2],
]]);
$pts2 = collect($r['data']['rubric_points']);
check('未被引用评分点已删除', !$pts2->contains('title','新增评分点'));
check('被引用评分点仍存在', $pts2->contains('title','存储管理'));
// 恢复 Q2 评分点数据，避免影响后续
api('PUT', '/api/questions/2/rubric-points', 'teacher@example.com', ['rubric_points'=>[
    ['id'=>1,'title'=>'进程管理','max_score'=>2],['id'=>2,'title'=>'存储管理','max_score'=>2],
    ['id'=>3,'title'=>'文件管理','max_score'=>2],['id'=>4,'title'=>'设备管理与用户接口','max_score'=>2],
]]);

echo "\n========================================\n";
echo "通过: $passed  失败: $failed\n";
exit($failed > 0 ? 1 : 0);
