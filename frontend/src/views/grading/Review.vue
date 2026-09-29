<template>
  <div class="space-y-6">
    <div class="flex justify-between items-center">
      <h1 class="text-2xl font-bold text-gray-900">复核中心</h1>
      <p class="text-sm text-gray-500">仅显示争议题与高分样卷</p>
    </div>

    <!-- 试卷选择 + 筛选 -->
    <div class="bg-white rounded-lg shadow p-4 flex flex-wrap items-center gap-4">
      <div class="flex items-center space-x-2">
        <label class="text-sm font-medium text-gray-700">试卷</label>
        <select v-model="selectedPaperId" @change="fetchQueue" class="border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-indigo-500 focus:border-indigo-500">
          <option v-for="paper in papers" :key="paper.id" :value="paper.id">{{ paper.title }}</option>
        </select>
      </div>
      <div class="flex items-center space-x-2">
        <label class="text-sm font-medium text-gray-700">类型</label>
        <select v-model="filterFlag" @change="fetchQueue" class="border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-indigo-500 focus:border-indigo-500">
          <option value="">全部</option>
          <option value="disputed">争议题</option>
          <option value="high_score">高分样卷</option>
        </select>
      </div>
      <button @click="fetchQueue" class="ml-auto text-indigo-600 hover:text-indigo-800 text-sm font-medium">刷新</button>
    </div>

    <div v-if="loading" class="text-center py-8">
      <div class="animate-spin rounded-full h-8 w-8 border-b-2 border-indigo-600 mx-auto"></div>
    </div>
    <div v-else-if="queue.length === 0" class="bg-white rounded-lg shadow text-center py-12 text-gray-500">
      复核队列为空，暂无争议题或高分样卷
    </div>
    <div v-else class="space-y-4">
      <div v-for="item in queue" :key="item.id" class="bg-white rounded-lg shadow p-5">
        <div class="flex items-start justify-between">
          <div class="flex items-center space-x-3">
            <span class="px-2.5 py-1 text-xs font-semibold rounded-full" :class="item.review_flag === 'disputed' ? 'bg-red-100 text-red-700' : 'bg-amber-100 text-amber-700'">
              {{ item.review_flag === 'disputed' ? '争议题' : '高分样卷' }}
            </span>
            <span class="text-sm text-gray-600">{{ item.student?.real_name || item.student?.username }} · {{ item.student?.class_name }}</span>
          </div>
          <div class="text-sm text-gray-500">初评得分：<b class="text-indigo-600">{{ item.score }}</b> / {{ item.question?.full_score }}</div>
        </div>
        <div class="mt-3 text-sm font-medium text-gray-900">{{ item.question?.title }}</div>
        <div class="mt-2 bg-gray-50 rounded-lg p-3 text-sm text-gray-700 whitespace-pre-wrap max-h-32 overflow-y-auto">{{ item.student_answer || '（未作答）' }}</div>
        <div v-if="item.initial_grading" class="mt-3 flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-gray-500">
          <span>初评人：{{ item.initial_grading.grader?.name }}</span>
          <span v-if="item.initial_grading.comment">评语：{{ item.initial_grading.comment }}</span>
          <span v-if="item.initial_grading.internal_note" class="text-amber-700">内部备注：{{ item.initial_grading.internal_note }}</span>
        </div>
        <div v-if="item.initial_grading?.points?.length" class="mt-2 flex flex-wrap gap-2">
          <span v-for="p in item.initial_grading.points" :key="p.rubric_point_id" class="text-xs bg-indigo-50 text-indigo-700 rounded-full px-2.5 py-1">{{ p.title }}：{{ p.score }}</span>
        </div>
        <div class="mt-4 flex justify-end space-x-3">
          <button @click="showHistory(item)" class="text-gray-600 hover:text-gray-900 text-sm">批阅记录</button>
          <button @click="openReviewPanel(item)" class="bg-indigo-600 text-white px-4 py-2 rounded-lg text-sm font-semibold hover:bg-indigo-700">复核</button>
        </div>
      </div>
      <div v-if="pagination.last_page > 1" class="flex justify-center items-center space-x-4">
        <button @click="changePage(pagination.current_page - 1)" :disabled="pagination.current_page <= 1" class="text-sm text-indigo-600 disabled:text-gray-400">上一页</button>
        <span class="text-sm text-gray-500">{{ pagination.current_page }} / {{ pagination.last_page }}</span>
        <button @click="changePage(pagination.current_page + 1)" :disabled="pagination.current_page >= pagination.last_page" class="text-sm text-indigo-600 disabled:text-gray-400">下一页</button>
      </div>
    </div>

    <!-- 复核面板（模态框） -->
    <Teleport to="body">
      <Transition enter-active-class="ease-out duration-200" enter-from-class="opacity-0" enter-to-class="opacity-100" leave-active-class="ease-in duration-150" leave-from-class="opacity-100" leave-to-class="opacity-0">
        <div v-if="reviewItem" class="fixed inset-0 z-50 overflow-y-auto">
          <div class="fixed inset-0 bg-gray-600/75 backdrop-blur-sm" @click="closeReviewPanel"></div>
          <div class="flex min-h-full items-center justify-center p-4">
            <div class="relative w-full max-w-3xl transform overflow-hidden rounded-2xl bg-white shadow-2xl border border-gray-100">
              <div class="px-6 py-4 border-b border-gray-100 bg-gray-50/50 flex justify-between items-center">
                <h3 class="text-lg font-bold text-gray-900">复核批阅</h3>
                <button @click="closeReviewPanel" class="text-gray-400 hover:text-gray-500 bg-white rounded-full p-1 hover:bg-gray-100">
                  <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
                </button>
              </div>
              <div class="px-6 py-5 max-h-[calc(100vh-14rem)] overflow-y-auto space-y-5">
                <div class="flex items-center space-x-4 text-sm text-gray-600">
                  <span>学生：<b class="text-gray-900">{{ reviewItem.student?.real_name || reviewItem.student?.username }}</b></span>
                  <span>班级：{{ reviewItem.student?.class_name }}</span>
                  <span>初评：<b class="text-indigo-600">{{ reviewItem.score }}</b> / {{ reviewItem.question?.full_score }} 分</span>
                </div>
                <div class="bg-gray-50 rounded-xl p-4 border border-gray-100">
                  <div class="text-xs font-semibold text-gray-500 mb-1">题目</div>
                  <div class="text-sm text-gray-900">{{ reviewItem.question?.title }}</div>
                </div>
                <div class="bg-indigo-50/50 rounded-xl p-4 border border-indigo-100">
                  <div class="text-xs font-semibold text-indigo-500 mb-1">学生答案</div>
                  <div class="text-sm text-gray-900 whitespace-pre-wrap">{{ reviewItem.student_answer || '（未作答）' }}</div>
                </div>

                <!-- 复核方式 -->
                <div class="flex space-x-4">
                  <label class="flex items-center space-x-2 text-sm text-gray-700">
                    <input type="radio" value="confirm" v-model="reviewForm.action" class="h-4 w-4 text-indigo-600 border-gray-300 focus:ring-indigo-500" />
                    <span>维持原评</span>
                  </label>
                  <label class="flex items-center space-x-2 text-sm text-gray-700">
                    <input type="radio" value="adjust" v-model="reviewForm.action" class="h-4 w-4 text-indigo-600 border-gray-300 focus:ring-indigo-500" />
                    <span>调整分数</span>
                  </label>
                </div>

                <!-- 调整分数 -->
                <template v-if="reviewForm.action === 'adjust'">
                  <div v-if="reviewItem.question?.rubric_points?.length" class="space-y-3">
                    <div class="text-sm font-semibold text-gray-700">按评分点调整</div>
                    <div v-for="point in reviewItem.question.rubric_points" :key="point.id" class="flex items-center justify-between bg-white border border-gray-200 rounded-xl px-4 py-3">
                      <div class="text-sm text-gray-900">
                        {{ point.title }}
                        <span class="ml-2 text-xs text-gray-400">满分 {{ point.max_score }} · 初评 {{ initialPointScore(point.id) }}</span>
                      </div>
                      <div class="flex items-center space-x-2">
                        <input type="number" min="0" :max="point.max_score" step="0.5" v-model.number="reviewForm.points[point.id]" class="w-24 border border-gray-300 rounded-lg px-3 py-1.5 text-sm text-right focus:ring-indigo-500 focus:border-indigo-500" />
                        <span class="text-xs text-gray-400">/ {{ point.max_score }}</span>
                      </div>
                    </div>
                    <div class="text-right text-sm font-bold text-indigo-600">调整后合计：{{ reviewTotal }} / {{ reviewItem.question?.full_score }} 分</div>
                  </div>
                  <div v-else>
                    <label class="block text-sm font-semibold text-gray-700 mb-2">调整后总分（满分 {{ reviewItem.question?.full_score }} 分）</label>
                    <input type="number" min="0" :max="reviewItem.question?.full_score" step="0.5" v-model.number="reviewForm.total_score" class="w-32 border border-gray-300 rounded-lg px-3 py-2 text-sm text-right focus:ring-indigo-500 focus:border-indigo-500" />
                  </div>
                  <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-2">修改原因 <span class="text-red-500">*</span></label>
                    <textarea v-model="reviewForm.reason" rows="2" class="w-full border border-gray-300 rounded-lg p-3 text-sm focus:ring-indigo-500 focus:border-indigo-500" placeholder="必填：说明本次调整分数的原因，将计入批阅档案"></textarea>
                  </div>
                </template>
                <div v-else>
                  <label class="block text-sm font-semibold text-gray-700 mb-2">复核说明（选填）</label>
                  <textarea v-model="reviewForm.reason" rows="2" class="w-full border border-gray-300 rounded-lg p-3 text-sm focus:ring-indigo-500 focus:border-indigo-500" placeholder="默认：复核确认维持原评"></textarea>
                </div>

                <div>
                  <label class="block text-sm font-semibold text-gray-700 mb-2">评语（学生可见，不填则沿用初评）</label>
                  <textarea v-model="reviewForm.comment" rows="2" class="w-full border border-gray-300 rounded-lg p-3 text-sm focus:ring-indigo-500 focus:border-indigo-500" :placeholder="reviewItem.student_comment || '给学生的一句简短评语'"></textarea>
                </div>
                <div>
                  <label class="block text-sm font-semibold text-gray-700 mb-2">内部备注（仅教师可见）</label>
                  <textarea v-model="reviewForm.internal_note" rows="2" class="w-full border border-gray-300 rounded-lg p-3 text-sm focus:ring-indigo-500 focus:border-indigo-500" placeholder="复核内部讨论，学生不可见"></textarea>
                </div>
              </div>
              <div class="px-6 py-4 border-t border-gray-100 bg-gray-50 flex flex-row-reverse gap-3">
                <button @click="submitReview" :disabled="submitting" class="bg-indigo-600 text-white px-5 py-2 rounded-lg text-sm font-semibold hover:bg-indigo-700 disabled:opacity-50">
                  {{ submitting ? '提交中...' : '提交复核结论' }}
                </button>
                <button @click="closeReviewPanel" class="bg-white text-gray-700 px-5 py-2 rounded-lg text-sm font-semibold border border-gray-300 hover:bg-gray-50">取消</button>
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

const papers = ref([])
const selectedPaperId = ref(null)
const filterFlag = ref('')
const queue = ref([])
const loading = ref(false)
const pagination = ref({ current_page: 1, last_page: 1 })

const reviewItem = ref(null)
const reviewForm = ref({ action: 'confirm', points: {}, total_score: 0, reason: '', comment: '', internal_note: '' })
const submitting = ref(false)

const historyData = ref(null)

const reviewTotal = computed(() => {
  const points = reviewForm.value.points || {}
  const sum = Object.values(points).reduce((acc, v) => acc + (Number(v) || 0), 0)
  return Math.round(sum * 100) / 100
})

const fetchPapers = async () => {
  try {
    const response = await api.get('/grading/papers')
    papers.value = response.data.papers
    if (papers.value.length > 0 && !selectedPaperId.value) {
      selectedPaperId.value = papers.value[0].id
      fetchQueue()
    }
  } catch (e) {
    console.error('Failed to fetch papers:', e)
  }
}

const fetchQueue = async (page = 1) => {
  if (!selectedPaperId.value) return
  loading.value = true
  try {
    const params = { exam_paper_id: selectedPaperId.value, page }
    if (filterFlag.value) params.flag = filterFlag.value
    const response = await api.get('/grading/review-queue', { params })
    queue.value = response.data.queue.data
    pagination.value = {
      current_page: response.data.queue.current_page,
      last_page: response.data.queue.last_page
    }
  } catch (e) {
    console.error('Failed to fetch review queue:', e)
  } finally {
    loading.value = false
  }
}

const changePage = (page) => {
  if (page < 1 || page > pagination.value.last_page) return
  fetchQueue(page)
}

const initialPointScore = (rubricPointId) => {
  const point = reviewItem.value?.initial_grading?.points?.find(p => p.rubric_point_id === rubricPointId)
  return point ? point.score : '—'
}

const openReviewPanel = (item) => {
  reviewItem.value = item
  const points = {}
  ;(item.question?.rubric_points || []).forEach(p => {
    const initial = item.initial_grading?.points?.find(ip => ip.rubric_point_id === p.id)
    points[p.id] = initial ? initial.score : 0
  })
  reviewForm.value = {
    action: 'confirm',
    points,
    total_score: item.score,
    reason: '',
    comment: '',
    internal_note: ''
  }
}

const closeReviewPanel = () => {
  reviewItem.value = null
}

const submitReview = async () => {
  const item = reviewItem.value
  const rubricPoints = item.question?.rubric_points || []

  if (reviewForm.value.action === 'adjust') {
    if (!reviewForm.value.reason.trim()) {
      alert('调整分数时必须填写修改原因', '提示', 'warning')
      return
    }
    if (rubricPoints.length > 0) {
      for (const p of rubricPoints) {
        const v = Number(reviewForm.value.points[p.id])
        if (isNaN(v) || v < 0 || v > p.max_score) {
          alert(`评分点「${p.title}」得分必须在 0 ~ ${p.max_score} 之间`, '提示', 'warning')
          return
        }
      }
    } else {
      const v = Number(reviewForm.value.total_score)
      if (isNaN(v) || v < 0 || v > item.question.full_score) {
        alert(`总分必须在 0 ~ ${item.question.full_score} 之间`, '提示', 'warning')
        return
      }
    }
  }

  submitting.value = true
  try {
    const payload = {
      action: reviewForm.value.action,
      reason: reviewForm.value.reason,
      comment: reviewForm.value.comment || undefined,
      internal_note: reviewForm.value.internal_note
    }
    if (reviewForm.value.action === 'adjust') {
      if (rubricPoints.length > 0) {
        payload.points = rubricPoints.map(p => ({ rubric_point_id: p.id, score: Number(reviewForm.value.points[p.id]) || 0 }))
      } else {
        payload.total_score = Number(reviewForm.value.total_score) || 0
      }
    }
    const response = await api.post(`/grading/answers/${item.id}/review`, payload)
    toast.success(response.data.message || '复核完成')
    closeReviewPanel()
    fetchQueue(pagination.value.current_page)
  } catch (e) {
    console.error('Failed to submit review:', e)
  } finally {
    submitting.value = false
  }
}

const showHistory = async (item) => {
  try {
    const response = await api.get(`/grading/answers/${item.id}/history`)
    historyData.value = response.data
  } catch (e) {
    console.error('Failed to fetch history:', e)
  }
}

onMounted(fetchPapers)
</script>
