<template>
  <div class="space-y-6">
    <div class="flex justify-between items-center">
      <h1 class="text-2xl font-bold text-gray-900">批阅工作台</h1>
      <div class="flex space-x-2">
        <button
          v-for="tab in tabs"
          :key="tab.key"
          @click="activeTab = tab.key"
          class="px-4 py-2 text-sm font-medium rounded-lg transition-all"
          :class="activeTab === tab.key ? 'bg-indigo-600 text-white shadow' : 'bg-white text-gray-600 hover:bg-gray-50 border border-gray-200'"
        >
          {{ tab.label }}
        </button>
      </div>
    </div>

    <!-- 试卷选择 -->
    <div class="bg-white rounded-lg shadow p-4">
      <label class="block text-sm font-semibold text-gray-700 mb-2">选择试卷</label>
      <div v-if="papers.length === 0" class="text-sm text-gray-500 py-2">暂无包含主观题的试卷</div>
      <div v-else class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3">
        <button
          v-for="paper in papers"
          :key="paper.id"
          @click="selectPaper(paper)"
          class="text-left p-4 rounded-xl border-2 transition-all"
          :class="selectedPaper?.id === paper.id ? 'border-indigo-500 bg-indigo-50/50' : 'border-gray-100 hover:border-indigo-200 bg-white'"
        >
          <div class="font-semibold text-gray-900 truncate">{{ paper.title }}</div>
          <div class="mt-2 flex items-center justify-between text-xs text-gray-500">
            <span>主观题 {{ paper.essay_questions.length }} 道</span>
            <span>待初评 <b class="text-orange-600">{{ paper.pending }}</b> · 待复核 <b class="text-purple-600">{{ paper.reviewing }}</b> · 已定稿 <b class="text-green-600">{{ paper.finalized }}</b></span>
          </div>
          <div class="mt-2 h-1.5 bg-gray-100 rounded-full overflow-hidden">
            <div
              class="h-full bg-gradient-to-r from-indigo-500 to-green-500 rounded-full transition-all"
              :style="{ width: paper.total_answers > 0 ? (paper.finalized / paper.total_answers * 100) + '%' : '0%' }"
            ></div>
          </div>
        </button>
      </div>
    </div>

    <div v-if="selectedPaper">
      <!-- 待初评 Tab -->
      <div v-if="activeTab === 'tasks'" class="space-y-4">
        <div class="bg-white rounded-lg shadow p-4 flex flex-wrap items-center gap-4">
          <div class="flex items-center space-x-2">
            <label class="text-sm font-medium text-gray-700">题目筛选</label>
            <select v-model="filterQuestionId" @change="fetchTasks" class="border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-indigo-500 focus:border-indigo-500">
              <option value="">全部主观题</option>
              <option v-for="q in selectedPaper.essay_questions" :key="q.id" :value="q.id">{{ truncate(q.title, 20) }}（{{ q.full_score }}分）</option>
            </select>
          </div>
          <div class="flex items-center space-x-2">
            <label class="text-sm font-medium text-gray-700">状态</label>
            <select v-model="filterStatus" @change="fetchTasks" class="border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-indigo-500 focus:border-indigo-500">
              <option value="pending">待初评</option>
              <option value="initial_graded">待复核</option>
              <option value="finalized">已定稿</option>
            </select>
          </div>
          <button @click="fetchTasks" class="ml-auto text-indigo-600 hover:text-indigo-800 text-sm font-medium">刷新</button>
        </div>

        <div v-if="tasksLoading" class="text-center py-8">
          <div class="animate-spin rounded-full h-8 w-8 border-b-2 border-indigo-600 mx-auto"></div>
        </div>
        <div v-else-if="tasks.length === 0" class="bg-white rounded-lg shadow text-center py-12 text-gray-500">
          当前筛选条件下暂无答卷
        </div>
        <div v-else class="bg-white shadow overflow-hidden sm:rounded-lg">
          <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
              <tr>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">学生</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">班级</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">题目</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">状态</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">得分</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">操作</th>
              </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
              <tr v-for="task in tasks" :key="task.id" class="hover:bg-gray-50">
                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">{{ task.student?.real_name || task.student?.username }}</td>
                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">{{ task.student?.class_name }}</td>
                <td class="px-6 py-4 text-sm text-gray-700 max-w-xs truncate">{{ task.question?.title }}</td>
                <td class="px-6 py-4 whitespace-nowrap">
                  <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full" :class="statusBadgeClass(task)">
                    {{ statusLabel(task) }}
                  </span>
                </td>
                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-700">
                  <span v-if="task.grading_status !== 'pending'">{{ task.score }} / {{ task.question?.full_score }}</span>
                  <span v-else class="text-gray-400">—</span>
                </td>
                <td class="px-6 py-4 whitespace-nowrap text-sm">
                  <button v-if="task.grading_status === 'pending'" @click="openGradePanel(task)" class="text-indigo-600 hover:text-indigo-900 font-medium">批阅</button>
                  <button v-else @click="showHistory(task)" class="text-gray-600 hover:text-gray-900">批阅记录</button>
                </td>
              </tr>
            </tbody>
          </table>
          <div v-if="tasksPagination.last_page > 1" class="px-6 py-3 bg-gray-50 flex justify-between items-center">
            <button @click="changePage(tasksPagination.current_page - 1)" :disabled="tasksPagination.current_page <= 1" class="text-sm text-indigo-600 disabled:text-gray-400">上一页</button>
            <span class="text-sm text-gray-500">{{ tasksPagination.current_page }} / {{ tasksPagination.last_page }}</span>
            <button @click="changePage(tasksPagination.current_page + 1)" :disabled="tasksPagination.current_page >= tasksPagination.last_page" class="text-sm text-indigo-600 disabled:text-gray-400">下一页</button>
          </div>
        </div>
      </div>

      <!-- 进度统计 Tab -->
      <div v-else class="space-y-6">
        <div v-if="progressLoading" class="text-center py-8">
          <div class="animate-spin rounded-full h-8 w-8 border-b-2 border-indigo-600 mx-auto"></div>
        </div>
        <template v-else-if="progress">
          <!-- 按班级统计 -->
          <div class="bg-white shadow sm:rounded-lg overflow-hidden">
            <div class="px-6 py-4 border-b border-gray-100">
              <h2 class="text-lg font-bold text-gray-900">按班级统计</h2>
            </div>
            <table class="min-w-full divide-y divide-gray-200">
              <thead class="bg-gray-50">
                <tr>
                  <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">班级</th>
                  <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">学生数</th>
                  <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">已交卷</th>
                  <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">待初评</th>
                  <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">待复核</th>
                  <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">已定稿</th>
                  <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">批阅完成答卷</th>
                  <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase w-48">完成度</th>
                </tr>
              </thead>
              <tbody class="bg-white divide-y divide-gray-200">
                <tr v-for="row in progress.by_class" :key="row.class_id ?? 'none'">
                  <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">{{ row.class_name }}</td>
                  <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-700">{{ row.total_students }}</td>
                  <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-700">{{ row.submitted_records }}</td>
                  <td class="px-6 py-4 whitespace-nowrap text-sm text-orange-600 font-medium">{{ row.pending_answers }}</td>
                  <td class="px-6 py-4 whitespace-nowrap text-sm text-purple-600 font-medium">{{ row.reviewing_answers }}</td>
                  <td class="px-6 py-4 whitespace-nowrap text-sm text-green-600 font-medium">{{ row.finalized_answers }}</td>
                  <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-700">{{ row.fully_graded_records }} / {{ row.submitted_records }}</td>
                  <td class="px-6 py-4 whitespace-nowrap">
                    <div class="flex items-center space-x-2">
                      <div class="flex-1 h-2 bg-gray-100 rounded-full overflow-hidden">
                        <div class="h-full bg-green-500 rounded-full" :style="{ width: answerProgressPct(row) + '%' }"></div>
                      </div>
                      <span class="text-xs text-gray-500 w-10 text-right">{{ answerProgressPct(row) }}%</span>
                    </div>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>

          <!-- 按题目统计 -->
          <div class="bg-white shadow sm:rounded-lg overflow-hidden">
            <div class="px-6 py-4 border-b border-gray-100">
              <h2 class="text-lg font-bold text-gray-900">按题目统计</h2>
            </div>
            <table class="min-w-full divide-y divide-gray-200">
              <thead class="bg-gray-50">
                <tr>
                  <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">题目</th>
                  <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">满分</th>
                  <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">答卷数</th>
                  <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">待初评</th>
                  <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">待复核</th>
                  <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">已定稿</th>
                  <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase w-48">完成度</th>
                </tr>
              </thead>
              <tbody class="bg-white divide-y divide-gray-200">
                <tr v-for="row in progress.by_question" :key="row.question_id">
                  <td class="px-6 py-4 text-sm text-gray-900 max-w-md truncate">{{ row.title }}</td>
                  <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-700">{{ row.full_score }}</td>
                  <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-700">{{ row.total }}</td>
                  <td class="px-6 py-4 whitespace-nowrap text-sm text-orange-600 font-medium">{{ row.pending }}</td>
                  <td class="px-6 py-4 whitespace-nowrap text-sm text-purple-600 font-medium">{{ row.reviewing }}</td>
                  <td class="px-6 py-4 whitespace-nowrap text-sm text-green-600 font-medium">{{ row.finalized }}</td>
                  <td class="px-6 py-4 whitespace-nowrap">
                    <div class="flex items-center space-x-2">
                      <div class="flex-1 h-2 bg-gray-100 rounded-full overflow-hidden">
                        <div class="h-full bg-green-500 rounded-full" :style="{ width: row.total > 0 ? Math.round(row.finalized / row.total * 100) : 0 }"></div>
                      </div>
                      <span class="text-xs text-gray-500 w-10 text-right">{{ row.total > 0 ? Math.round(row.finalized / row.total * 100) : 0 }}%</span>
                    </div>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </template>
      </div>
    </div>

    <!-- 初评面板（模态框） -->
    <Teleport to="body">
      <Transition enter-active-class="ease-out duration-200" enter-from-class="opacity-0" enter-to-class="opacity-100" leave-active-class="ease-in duration-150" leave-from-class="opacity-100" leave-to-class="opacity-0">
        <div v-if="gradingTask" class="fixed inset-0 z-50 overflow-y-auto">
          <div class="fixed inset-0 bg-gray-600/75 backdrop-blur-sm" @click="closeGradePanel"></div>
          <div class="flex min-h-full items-center justify-center p-4">
            <div class="relative w-full max-w-3xl transform overflow-hidden rounded-2xl bg-white shadow-2xl border border-gray-100">
              <div class="px-6 py-4 border-b border-gray-100 bg-gray-50/50 flex justify-between items-center">
                <h3 class="text-lg font-bold text-gray-900">初评批阅</h3>
                <button @click="closeGradePanel" class="text-gray-400 hover:text-gray-500 bg-white rounded-full p-1 hover:bg-gray-100">
                  <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
                </button>
              </div>
              <div class="px-6 py-5 max-h-[calc(100vh-14rem)] overflow-y-auto space-y-5">
                <div class="flex items-center space-x-4 text-sm text-gray-600">
                  <span>学生：<b class="text-gray-900">{{ gradingTask.student?.real_name || gradingTask.student?.username }}</b></span>
                  <span>班级：{{ gradingTask.student?.class_name }}</span>
                  <span>满分：<b class="text-indigo-600">{{ gradingTask.question?.full_score }}</b> 分</span>
                </div>
                <div class="bg-gray-50 rounded-xl p-4 border border-gray-100">
                  <div class="text-xs font-semibold text-gray-500 mb-1">题目</div>
                  <div class="text-sm text-gray-900">{{ gradingTask.question?.title }}</div>
                </div>
                <div class="bg-indigo-50/50 rounded-xl p-4 border border-indigo-100">
                  <div class="text-xs font-semibold text-indigo-500 mb-1">学生答案</div>
                  <div class="text-sm text-gray-900 whitespace-pre-wrap">{{ gradingTask.student_answer || '（未作答）' }}</div>
                </div>
                <details class="bg-gray-50 rounded-xl border border-gray-100">
                  <summary class="px-4 py-2 text-xs font-semibold text-gray-500 cursor-pointer select-none">参考答案（点击展开）</summary>
                  <div class="px-4 pb-3 text-sm text-gray-700 whitespace-pre-wrap">{{ gradingTask.question?.reference_answer }}</div>
                </details>

                <!-- 评分点打分 -->
                <div v-if="gradingTask.question?.rubric_points?.length" class="space-y-3">
                  <div class="text-sm font-semibold text-gray-700">按评分点打分</div>
                  <div v-for="point in gradingTask.question.rubric_points" :key="point.id" class="flex items-center justify-between bg-white border border-gray-200 rounded-xl px-4 py-3">
                    <div class="text-sm text-gray-900">{{ point.title }}<span class="ml-2 text-xs text-gray-400">满分 {{ point.max_score }}</span></div>
                    <div class="flex items-center space-x-2">
                      <input
                        type="number" min="0" :max="point.max_score" step="0.5"
                        v-model.number="gradeForm.points[point.id]"
                        class="w-24 border border-gray-300 rounded-lg px-3 py-1.5 text-sm text-right focus:ring-indigo-500 focus:border-indigo-500"
                      />
                      <span class="text-xs text-gray-400">/ {{ point.max_score }}</span>
                    </div>
                  </div>
                  <div class="text-right text-sm font-bold text-indigo-600">合计：{{ gradeTotal }} / {{ gradingTask.question?.full_score }} 分</div>
                </div>
                <div v-else>
                  <label class="block text-sm font-semibold text-gray-700 mb-2">总分（满分 {{ gradingTask.question?.full_score }} 分）</label>
                  <input type="number" min="0" :max="gradingTask.question?.full_score" step="0.5" v-model.number="gradeForm.total_score" class="w-32 border border-gray-300 rounded-lg px-3 py-2 text-sm text-right focus:ring-indigo-500 focus:border-indigo-500" />
                </div>

                <div>
                  <label class="block text-sm font-semibold text-gray-700 mb-2">评语（学生可见）</label>
                  <textarea v-model="gradeForm.comment" rows="2" class="w-full border border-gray-300 rounded-lg p-3 text-sm focus:ring-indigo-500 focus:border-indigo-500" placeholder="给学生的一句简短评语，如：要点齐全，表述清晰"></textarea>
                </div>
                <div>
                  <label class="block text-sm font-semibold text-gray-700 mb-2">内部备注（仅教师可见）</label>
                  <textarea v-model="gradeForm.internal_note" rows="2" class="w-full border border-gray-300 rounded-lg p-3 text-sm focus:ring-indigo-500 focus:border-indigo-500" placeholder="与其他老师沟通的内部讨论，学生不可见"></textarea>
                </div>
                <label class="flex items-center space-x-2 text-sm text-gray-700">
                  <input type="checkbox" v-model="gradeForm.needs_review" class="h-4 w-4 text-indigo-600 border-gray-300 rounded focus:ring-indigo-500" />
                  <span>标记为争议题，提交复核老师复核</span>
                </label>
                <p class="text-xs text-gray-400">提示：得分率达到 85% 的答卷将自动作为高分样卷进入复核队列。</p>
              </div>
              <div class="px-6 py-4 border-t border-gray-100 bg-gray-50 flex flex-row-reverse gap-3">
                <button @click="submitGrade" :disabled="submittingGrade" class="bg-indigo-600 text-white px-5 py-2 rounded-lg text-sm font-semibold hover:bg-indigo-700 disabled:opacity-50">
                  {{ submittingGrade ? '提交中...' : '提交初评' }}
                </button>
                <button @click="closeGradePanel" class="bg-white text-gray-700 px-5 py-2 rounded-lg text-sm font-semibold border border-gray-300 hover:bg-gray-50">取消</button>
              </div>
            </div>
          </div>
        </div>
      </Transition>
    </Teleport>

    <!-- 批阅历史（模态框） -->
    <Teleport to="body">
      <Transition enter-active-class="ease-out duration-200" enter-from-class="opacity-0" enter-to-class="opacity-100" leave-active-class="ease-in duration-150" leave-from-class="opacity-100" leave-to-class="opacity-0">
        <div v-if="historyData" class="fixed inset-0 z-50 overflow-y-auto">
          <div class="fixed inset-0 bg-gray-600/75 backdrop-blur-sm" @click="historyData = null"></div>
          <div class="flex min-h-full items-center justify-center p-4">
            <div class="relative w-full max-w-2xl transform overflow-hidden rounded-2xl bg-white shadow-2xl border border-gray-100">
              <div class="px-6 py-4 border-b border-gray-100 bg-gray-50/50 flex justify-between items-center">
                <h3 class="text-lg font-bold text-gray-900">批阅记录（可追溯每次修改）</h3>
                <button @click="historyData = null" class="text-gray-400 hover:text-gray-500 bg-white rounded-full p-1 hover:bg-gray-100">
                  <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
                </button>
              </div>
              <div class="px-6 py-5 max-h-[calc(100vh-12rem)] overflow-y-auto">
                <div v-if="historyData.history.length === 0" class="text-center text-gray-500 py-6">暂无批阅记录</div>
                <div v-else class="space-y-4">
                  <div v-for="item in historyData.history" :key="item.id" class="border border-gray-200 rounded-xl p-4">
                    <div class="flex items-center justify-between">
                      <div class="flex items-center space-x-2">
                        <span class="px-2 py-0.5 text-xs font-semibold rounded-full" :class="item.stage === 'initial' ? 'bg-blue-100 text-blue-700' : 'bg-purple-100 text-purple-700'">{{ item.stage_label }}</span>
                        <span class="text-sm font-medium text-gray-900">{{ item.grader?.name }}</span>
                      </div>
                      <span class="text-xs text-gray-400">{{ item.created_at }}</span>
                    </div>
                    <div class="mt-2 text-sm text-gray-700">评定分数：<b class="text-indigo-600">{{ item.total_score }}</b> 分</div>
                    <div v-if="item.points?.length" class="mt-2 grid grid-cols-2 gap-1">
                      <div v-for="p in item.points" :key="p.rubric_point_id" class="text-xs text-gray-500">{{ p.title }}：{{ p.score }}</div>
                    </div>
                    <div v-if="item.reason" class="mt-2 text-xs text-gray-600 bg-gray-50 rounded-lg px-3 py-2">修改原因：{{ item.reason }}</div>
                    <div v-if="item.comment" class="mt-1 text-xs text-gray-600">学生评语：{{ item.comment }}</div>
                    <div v-if="item.internal_note" class="mt-1 text-xs text-amber-700 bg-amber-50 rounded-lg px-3 py-2">内部备注：{{ item.internal_note }}</div>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </Transition>
    </Teleport>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import api from '../../api'
import { useModal } from '../../composables/useModal'
import { useToast } from '../../composables/useToast'

const { alert } = useModal()
const toast = useToast()

const tabs = [
  { key: 'tasks', label: '批阅任务' },
  { key: 'progress', label: '进度统计' }
]
const activeTab = ref('tasks')

const papers = ref([])
const selectedPaper = ref(null)
const filterQuestionId = ref('')
const filterStatus = ref('pending')

const tasks = ref([])
const tasksLoading = ref(false)
const tasksPagination = ref({ current_page: 1, last_page: 1 })

const progress = ref(null)
const progressLoading = ref(false)

const gradingTask = ref(null)
const gradeForm = ref({ points: {}, total_score: 0, comment: '', internal_note: '', needs_review: false })
const submittingGrade = ref(false)

const historyData = ref(null)

const truncate = (text, len) => (text && text.length > len ? text.slice(0, len) + '…' : text)

const gradeTotal = computed(() => {
  const points = gradeForm.value.points || {}
  const sum = Object.values(points).reduce((acc, v) => acc + (Number(v) || 0), 0)
  return Math.round(sum * 100) / 100
})

const fetchPapers = async () => {
  try {
    const response = await api.get('/grading/papers')
    papers.value = response.data.papers
    if (!selectedPaper.value && papers.value.length > 0) {
      selectPaper(papers.value[0])
    } else if (selectedPaper.value) {
      const refreshed = papers.value.find(p => p.id === selectedPaper.value.id)
      if (refreshed) selectedPaper.value = refreshed
    }
  } catch (e) {
    console.error('Failed to fetch grading papers:', e)
  }
}

const selectPaper = (paper) => {
  selectedPaper.value = paper
  filterQuestionId.value = ''
  fetchTasks()
  fetchProgress()
}

const fetchTasks = async (page = 1) => {
  if (!selectedPaper.value) return
  tasksLoading.value = true
  try {
    const params = { exam_paper_id: selectedPaper.value.id, status: filterStatus.value, page }
    if (filterQuestionId.value) params.question_id = filterQuestionId.value
    const response = await api.get('/grading/tasks', { params })
    tasks.value = response.data.tasks.data
    tasksPagination.value = {
      current_page: response.data.tasks.current_page,
      last_page: response.data.tasks.last_page
    }
  } catch (e) {
    console.error('Failed to fetch tasks:', e)
  } finally {
    tasksLoading.value = false
  }
}

const changePage = (page) => {
  if (page < 1 || page > tasksPagination.value.last_page) return
  fetchTasks(page)
}

const fetchProgress = async () => {
  if (!selectedPaper.value) return
  progressLoading.value = true
  try {
    const response = await api.get(`/grading/progress/${selectedPaper.value.id}`)
    progress.value = response.data
  } catch (e) {
    console.error('Failed to fetch progress:', e)
  } finally {
    progressLoading.value = false
  }
}

const statusLabel = (task) => {
  if (task.grading_status === 'pending') return '待初评'
  if (task.grading_status === 'initial_graded') return task.review_flag === 'disputed' ? '争议待复核' : '高分样卷待复核'
  return '已定稿'
}

const statusBadgeClass = (task) => {
  if (task.grading_status === 'pending') return 'bg-orange-100 text-orange-700'
  if (task.grading_status === 'initial_graded') return 'bg-purple-100 text-purple-700'
  return 'bg-green-100 text-green-700'
}

const answerProgressPct = (row) => {
  const total = row.pending_answers + row.reviewing_answers + row.finalized_answers
  return total > 0 ? Math.round(row.finalized_answers / total * 100) : 0
}

const openGradePanel = (task) => {
  gradingTask.value = task
  const points = {}
  ;(task.question?.rubric_points || []).forEach(p => { points[p.id] = 0 })
  gradeForm.value = { points, total_score: 0, comment: '', internal_note: '', needs_review: false }
}

const closeGradePanel = () => {
  gradingTask.value = null
}

const submitGrade = async () => {
  const task = gradingTask.value
  const rubricPoints = task.question?.rubric_points || []

  if (rubricPoints.length > 0) {
    for (const p of rubricPoints) {
      const v = Number(gradeForm.value.points[p.id])
      if (isNaN(v) || v < 0 || v > p.max_score) {
        alert(`评分点「${p.title}」得分必须在 0 ~ ${p.max_score} 之间`, '提示', 'warning')
        return
      }
    }
  } else {
    const v = Number(gradeForm.value.total_score)
    if (isNaN(v) || v < 0 || v > task.question.full_score) {
      alert(`总分必须在 0 ~ ${task.question.full_score} 之间`, '提示', 'warning')
      return
    }
  }

  submittingGrade.value = true
  try {
    const payload = {
      comment: gradeForm.value.comment,
      internal_note: gradeForm.value.internal_note,
      needs_review: gradeForm.value.needs_review
    }
    if (rubricPoints.length > 0) {
      payload.points = rubricPoints.map(p => ({ rubric_point_id: p.id, score: Number(gradeForm.value.points[p.id]) || 0 }))
    } else {
      payload.total_score = Number(gradeForm.value.total_score) || 0
    }
    const response = await api.post(`/grading/answers/${task.id}/initial`, payload)
    toast.success(response.data.message || '初评完成')
    closeGradePanel()
    await Promise.all([fetchTasks(tasksPagination.value.current_page), fetchPapers(), fetchProgress()])
  } catch (e) {
    console.error('Failed to submit grade:', e)
  } finally {
    submittingGrade.value = false
  }
}

const showHistory = async (task) => {
  try {
    const response = await api.get(`/grading/answers/${task.id}/history`)
    historyData.value = response.data
  } catch (e) {
    console.error('Failed to fetch history:', e)
  }
}

onMounted(fetchPapers)
</script>
