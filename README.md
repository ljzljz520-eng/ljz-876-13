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
| Teacher（阅卷老师） | teacher@example.com | password |
| Reviewer（复核老师） | reviewer@example.com | password |
| Student | student1@example.com | password |
| Student | student2@example.com | password |
| Student | student3@example.com | password |

> 登录页已移除快捷测试账号模块，请手动输入账号密码。

## README 与测试账号清单同步（必跑）
在截图前、提交前执行以下命令：

```bash
node scripts/sync-readme-test-credentials.mjs --manifest qa/.runtime/test-credentials.current.json --readme README.md
node scripts/verify-readme-test-credentials.mjs --manifest qa/.runtime/test-credentials.current.json --readme README.md
```

阻断规则：任一命令失败都应视为 `README_TEST_CREDENTIALS_MISMATCH`，不得继续提交流程。

## 核心功能
1. 用户认证：注册、登录、退出（学生注册可选择班级）。
2. 题库管理：题目增删改查、分类管理；问答题可维护评分点（rubric）。
3. 试卷管理：试卷创建、编辑、题目关联。
4. 在线考试：开始考试、提交答卷、客观题自动评分。
5. 主观题分层批阅：
   - 初评：阅卷老师按评分点逐项打分，可填写学生评语与内部备注，拿不准的题可标记争议；
   - 复核：复核老师只看争议题与高分样卷（初评得分率 ≥85% 自动进入），可维持原评或调整分数（调整必须填写修改原因）；
   - 可追溯：每次初评/复核均留存批阅流水（批阅人、分数、评分点明细、修改原因、内部备注）；
   - 学生视图：只显示得分点与简短评语，不暴露教师内部讨论与批阅人信息；
   - 进度统计：按班级、按题目两个维度统计待初评/待复核/已定稿数量。
6. 成绩统计：个人成绩与管理端统计数据。

## 角色权限
| 角色 | 可访问模块 |
|---|---|
| Student | 在线考试、我的成绩（含得分点与评语） |
| Teacher | 在线考试、我的成绩、题库管理、试卷管理、批阅管理（初评+进度） |
| Reviewer（`can_review` 的教师） | 以上全部 + 复核中心 |
| Admin | 全部功能（含数据统计、复核中心） |

## 人工验证步骤（建议）
1. 打开登录页：`http://localhost:8080/login`。
2. 使用测试账号手动登录，确认菜单与角色权限一致。
3. 进入题库管理，验证新增/编辑/删除流程；编辑问答题时可维护评分点。
4. 进入试卷管理，验证题目关联与试卷删除流程。
5. 学生账号完成一次在线考试并查看成绩（含主观题的试卷提交后显示"批阅中"）。
6. 分层批阅流程：
   - 阅卷老师（teacher）进入"批阅管理"，对"操作系统综合测验"按评分点初评，可标记争议；
   - 复核老师（reviewer）进入"复核中心"，只看到争议题与高分样卷，调整分数需填修改原因；
   - 批阅管理"进度统计"页签查看按班级、按题目的批阅进度；
   - 学生查看成绩详情，确认只显示得分点与评语。
7. Admin 查看统计页数据。
8. API 冒烟：

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
当前初始化后包含 14 张核心表（含用户、班级、题目、评分点、试卷、考试记录、答案记录、批阅流水等）。

详见：
- `docs/Database.sql`
- `docker-compose.yml` 中 `db-init` 初始化段（含面向老库的幂等升级段）

## 端到端验证脚本
后端提供不依赖 Docker/MySQL 的分层批阅全流程验证脚本（SQLite 环境，覆盖初评、复核、追溯、学生可见性、进度统计共 62 项断言）：

```bash
cd backend
composer install
php tests/e2e-grading.php
```

## 证据目录
测试与质检证据统一放在 `evidence/`（含 `evidence/run-slot*/`）目录。

---
如需进行质检修复闭环，请配合 `qa/qc-feedback-inbox.md`、`qa/qc-fix-send-template.md`、`qa/qc-fix-loop-template.md` 使用。

