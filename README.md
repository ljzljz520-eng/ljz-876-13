# 在线考试题库系统（876）

## 项目类型
- 全栈 Web 项目（`frontend` + `backend`）

## 项目简介
本项目是一个基于 Vue 3 + Laravel 12 的在线考试与题库管理系统，支持多角色登录、题库管理、试卷管理、在线考试与成绩统计。

## 技术栈
### 前端
- Vue 3
- Vite
- Pinia
- Vue Router
- Axios
- TailwindCSS

### 后端
- Laravel 12（PHP 8.2）
- Laravel Sanctum（Token 鉴权）
- MySQL 8.0

### 运行方式
- Docker Compose（推荐，当前项目默认方式）

## 目录结构
```text
876/
├── docker-compose.yml
├── README.md
├── frontend/
│   ├── Dockerfile
│   ├── nginx.conf
│   ├── package.json
│   └── src/
├── backend/
│   ├── Dockerfile
│   ├── composer.json
│   ├── app/
│   └── routes/
├── docs/
│   ├── ARCHITECTURE.md
│   └── Database.sql
├── scripts/
└── evidence/
```

说明：`node_modules/`、`vendor/` 等依赖目录由 Docker 构建时自动安装，不需要打包提交。

## 启动与重建
在仓库根目录执行：

```bash
docker compose down
docker compose up -d --build
docker compose ps
```

## 服务地址
| 服务 | 地址 | 说明 |
|---|---|---|
| 前端 | http://localhost:8080 | 用户界面 |
| 后端 API | http://localhost:9000/api | Laravel API |
| MySQL | localhost:3307 | 数据库端口映射 |

## 测试账号
| 角色 | 邮箱 | 密码 |
|------|-------|----------|
| Admin | admin@example.com | password |
| Teacher（阅卷老师·初评） | teacher@example.com | password |
| Reviewer（复核老师） | reviewer@example.com | password |
| Student | student1@example.com | password |
| Student | student2@example.com | password |

> 登录页已移除快捷测试账号模块，请手动输入账号密码。

## README 与测试账号清单同步（必跑）
在截图前、提交前执行以下命令：

```bash
node scripts/sync-readme-test-credentials.mjs --manifest qa/.runtime/test-credentials.current.json --readme README.md
node scripts/verify-readme-test-credentials.mjs --manifest qa/.runtime/test-credentials.current.json --readme README.md
```

阻断规则：任一命令失败都应视为 `README_TEST_CREDENTIALS_MISMATCH`，不得继续提交流程。

## 核心功能
1. 用户认证：注册、登录、退出。
2. 题库管理：题目增删改查、分类管理、主观题评分点维护。
3. 试卷管理：试卷创建、编辑、题目关联。
4. 在线考试：开始考试、提交答卷、客观题自动评分。
5. 主观题分层批阅：
   - 阅卷老师按**评分点**逐点给分、写简短评语（初评）；
   - 可标记**争议题**；初评得分 ≥90% 自动标为**高分样卷**（也可手动标记）；
   - 争议题与高分样卷进入**复核工作台**，复核老师维持初评或改分，**改分必须填写原因**；
   - 所有分数变更、标记、内部讨论都写入 `grading_audit_logs`，**每次修改原因全程可追溯**；
   - 学生只看到自己的**得分点与简短评语**，看不到老师内部讨论；
   - 批阅进度支持**按班级、按题目**双维度统计。
6. 班级管理：班级增删改、学生归属分配。
7. 成绩统计：个人成绩与管理端统计数据。

## 角色权限
| 角色 | 可访问模块 |
|---|---|
| Student | 在线考试、我的成绩（仅得分点与对学生可见评语） |
| Reviewer（复核老师） | 复核工作台（仅争议题 + 高分样卷）、批阅进度 |
| Teacher | 在线考试、我的成绩、题库管理、试卷管理、主观题初评、批阅进度 |
| Admin | 全部功能（含初评、复核、班级管理、数据统计） |

## 人工验证步骤（建议）
1. 打开登录页：`http://localhost:8080/login`。
2. 使用测试账号手动登录，确认菜单与角色权限一致。
3. 进入题库管理，验证新增/编辑/删除流程。
4. 进入试卷管理，验证题目关联与试卷删除流程。
5. 学生账号完成一次在线考试并查看成绩。
6. Admin 查看统计页数据。
7. API 冒烟：

```bash
docker compose exec backend sh -lc "curl -s -o /tmp/unauth.txt -w '%{http_code}\n' http://localhost:8080/api/exams"
docker compose exec backend sh -lc "curl -s -X POST http://localhost:8080/api/auth/login -H 'Content-Type: application/json' -d '{\"email\":\"admin@example.com\",\"password\":\"password\"}'"
```

预期：未登录访问受保护接口返回 `401`；登录接口返回包含 `token` 的 JSON。

## 安全与质量说明
- 密码为哈希存储（bcrypt）。
- API 使用 Sanctum Token 鉴权。
- 接口包含输入校验与错误处理。
- CORS 与基础限流已配置。

## 数据库说明
当前初始化后包含 17 张核心表（含用户、班级、题目、评分点、试卷、考试记录、答案、批阅主表、评分点得分、批阅审计日志、批阅评论等）。

分层批阅相关表：

| 表 | 说明 |
|---|---|
| `classes` / `class_student` | 班级及班级-学生关系（按班级统计进度） |
| `grading_rubric_points` | 主观题评分点（名称、说明、满分） |
| `essay_gradings` | 批阅主表：初评/复核分数、争议/高分样卷标记、状态流转 |
| `essay_grading_points` | 每位考生每个评分点的初评/复核得分 |
| `grading_audit_logs` | 修改轨迹：动作、操作人、变更前后分数、**原因** |
| `grading_comments` | 评论：`internal`（仅老师内部）/ `student`（对学生可见） |

> 已有数据库升级：backend 容器启动时会自动执行 `php artisan migrate`（迁移文件使用 `IF NOT EXISTS` 等幂等写法）并运行 `php artisan grading:seed-demo` 补齐复核老师账号、演示班级与主观题。

详见：
- `docs/Database.sql`
- `docker-compose.yml` 中 `db-init` 初始化段

## 证据目录
测试与质检证据统一放在 `evidence/`（含 `evidence/run-slot*/`）目录。

---
如需进行质检修复闭环，请配合 `qa/qc-feedback-inbox.md`、`qa/qc-fix-send-template.md`、`qa/qc-fix-loop-template.md` 使用。

