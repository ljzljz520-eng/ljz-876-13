<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 主观题分层批阅：班级 + 评分点 + 批阅主表 + 评分点得分 + 审计日志 + 评论。
 * 本迁移全部使用 IF NOT EXISTS / 运行时判断，保证对已有库幂等。
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1) users.role 增加 reviewer（复核老师）枚举值
        $this->ensureReviewerRole();

        // 2) 班级
        DB::statement("CREATE TABLE IF NOT EXISTS classes (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL COMMENT '班级名称',
            description VARCHAR(255) COMMENT '班级描述',
            created_by BIGINT UNSIGNED COMMENT '创建人ID',
            status TINYINT(1) DEFAULT 1 COMMENT '状态: 1-启用 0-禁用',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uk_classes_name (name),
            INDEX idx_classes_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='班级表'");

        DB::statement("CREATE TABLE IF NOT EXISTS class_student (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            class_id BIGINT UNSIGNED NOT NULL COMMENT '班级ID',
            user_id BIGINT UNSIGNED NOT NULL COMMENT '学生用户ID',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uk_class_student (class_id, user_id),
            INDEX idx_cs_user_id (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='班级-学生关联表'");

        // 3) 评分点
        DB::statement("CREATE TABLE IF NOT EXISTS grading_rubric_points (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            question_id BIGINT UNSIGNED NOT NULL COMMENT '主观题ID',
            title VARCHAR(200) NOT NULL COMMENT '评分点名称',
            description VARCHAR(500) COMMENT '评分点说明',
            score DECIMAL(5,2) NOT NULL DEFAULT 0.00 COMMENT '该评分点满分',
            sort_order INT DEFAULT 0 COMMENT '排序',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_grp_question_id (question_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='主观题评分点表'");

        // 4) 批阅主表
        DB::statement("CREATE TABLE IF NOT EXISTS essay_gradings (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            exam_record_answer_id BIGINT UNSIGNED NOT NULL COMMENT '考生答案ID',
            exam_record_id BIGINT UNSIGNED NOT NULL COMMENT '考试记录ID',
            question_id BIGINT UNSIGNED NOT NULL COMMENT '题目ID',
            status ENUM('pending','review_pending','finalized') NOT NULL DEFAULT 'pending' COMMENT '批阅状态',
            initial_grader_id BIGINT UNSIGNED COMMENT '初评老师ID',
            initial_score DECIMAL(5,2) DEFAULT NULL COMMENT '初评总分',
            initial_comment VARCHAR(1000) COMMENT '初评评语(学生可见)',
            initial_gradeds_at TIMESTAMP NULL COMMENT '初评时间',
            is_high_score_sample TINYINT(1) NOT NULL DEFAULT 0 COMMENT '是否高分样卷',
            is_disputed TINYINT(1) NOT NULL DEFAULT 0 COMMENT '是否争议题',
            reviewer_id BIGINT UNSIGNED COMMENT '复核老师ID',
            final_score DECIMAL(5,2) DEFAULT NULL COMMENT '最终得分',
            final_comment VARCHAR(1000) COMMENT '复核评语(学生可见)',
            reviewed_at TIMESTAMP NULL COMMENT '复核时间',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uk_eg_answer (exam_record_answer_id),
            INDEX idx_eg_record (exam_record_id),
            INDEX idx_eg_question (question_id),
            INDEX idx_eg_status (status),
            INDEX idx_eg_grader (initial_grader_id),
            INDEX idx_eg_reviewer (reviewer_id),
            INDEX idx_eg_disputed (is_disputed),
            INDEX idx_eg_sample (is_high_score_sample)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='主观题批阅表'");

        // 5) 各评分点得分
        DB::statement("CREATE TABLE IF NOT EXISTS essay_grading_points (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            essay_grading_id BIGINT UNSIGNED NOT NULL COMMENT '批阅记录ID',
            rubric_point_id BIGINT UNSIGNED NOT NULL COMMENT '评分点ID',
            initial_score DECIMAL(5,2) DEFAULT NULL COMMENT '初评得分',
            final_score DECIMAL(5,2) DEFAULT NULL COMMENT '复核后得分',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uk_egp_grading_rubric (essay_grading_id, rubric_point_id),
            INDEX idx_egp_rubric (rubric_point_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='主观题评分点得分表'");

        // 6) 审计日志
        DB::statement("CREATE TABLE IF NOT EXISTS grading_audit_logs (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            essay_grading_id BIGINT UNSIGNED NOT NULL COMMENT '批阅记录ID',
            action VARCHAR(50) NOT NULL COMMENT '操作类型',
            operator_id BIGINT UNSIGNED COMMENT '操作人ID',
            score_before DECIMAL(5,2) DEFAULT NULL COMMENT '变更前总分',
            score_after DECIMAL(5,2) DEFAULT NULL COMMENT '变更后总分',
            reason VARCHAR(1000) NOT NULL COMMENT '修改/操作原因',
            detail JSON COMMENT '操作明细',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_gal_grading (essay_grading_id),
            INDEX idx_gal_operator (operator_id),
            INDEX idx_gal_action (action)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='批阅修改审计日志表'");

        // 7) 评论
        DB::statement("CREATE TABLE IF NOT EXISTS grading_comments (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            essay_grading_id BIGINT UNSIGNED NOT NULL COMMENT '批阅记录ID',
            author_id BIGINT UNSIGNED NOT NULL COMMENT '评论人ID',
            content VARCHAR(1000) NOT NULL COMMENT '评论内容',
            visibility ENUM('internal','student') NOT NULL DEFAULT 'internal' COMMENT '可见范围',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_gc_grading (essay_grading_id),
            INDEX idx_gc_visibility (visibility)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='批阅评论表'");
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS grading_comments');
        DB::statement('DROP TABLE IF EXISTS grading_audit_logs');
        DB::statement('DROP TABLE IF EXISTS essay_grading_points');
        DB::statement('DROP TABLE IF EXISTS essay_gradings');
        DB::statement('DROP TABLE IF EXISTS grading_rubric_points');
        DB::statement('DROP TABLE IF EXISTS class_student');
        DB::statement('DROP TABLE IF EXISTS classes');
    }

    private function ensureReviewerRole(): void
    {
        $row = DB::selectOne(
            "SELECT COLUMN_TYPE AS t FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'role'"
        );

        if ($row && str_contains($row->t, "'reviewer'")) {
            return;
        }

        DB::statement("ALTER TABLE users MODIFY COLUMN role
            ENUM('admin','teacher','reviewer','student') DEFAULT 'student'
            COMMENT '角色: admin-管理员 teacher-阅卷老师 reviewer-复核老师 student-学生'");
    }
};
