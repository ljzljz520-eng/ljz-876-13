<template>
  <div class="space-y-6">
    <div class="flex items-center justify-between flex-wrap gap-3">
      <div>
        <h1 class="text-2xl font-bold text-gray-900">主观题批阅 · 初评工作台</h1>
        <p class="text-sm text-gray-500 mt-1">按评分点逐题初评；争议题与高分样卷（初评 ≥90%）自动进入复核。</p>
      </div>
      <router-link to="/grading/progress" class="text-sm text-indigo-600 hover:text-indigo-500 font-medium">查看批阅进度 →</router-link>
    </div>

    <!-- 试卷选择 + 概况 -->
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-5">
      <div class="flex items-center gap-4 flex-wrap">
        <label class="text-sm font-medium text-gray-700">选择试卷</label>
        <select v-model="paperId" @change="loadQueue" class="rounded-lg border-gray-300 text-sm focus:ring-indigo-500 focus:border-indigo-500 min-w-[240px]">
          <option value="" disabled>请选择含主观题的试卷</option>
          <option v-for="p in papers" :key="p.id" :value="p.id">{{ p.title }}</option>
        </select>
      </div>

      <div v-if="summary" class="grid grid-cols-2 md:grid-cols-5 gap-3 mt-5">
        <div class="rounded-lg bg-gray-50 p-3 text-center">
          <div class="text-xl font-bold text-gray-800">{{ summary.total }}</div>
          <div class="text-xs text-gray-500 mt-0.5">待批总量</div>
        </div>
        <div class="rounded-lg bg-amber-50 p-3 text-center">
          <div class="text-xl font-bold text-amber-600">{{ summary.pending }}</div>
          <div class="text-xs text-gray-500 mt-0.5">待初评</div>
        </div>
        <div class="rounded-lg bg-blue-50 p-3 text-center">
          <div class="text-xl font-bold text-blue-600">{{ summary.review_pending }}</div>
          <div class="text-xs text-gray-500 mt-0.5">待复核</div>
        </div>
        <div class="rounded-lg bg-red-50 p-3 text-center">
          <div class="text-xl font-bold text-red-600">{{ summary.disputed }}</div>
          <div class="text-xs text-gray-500 mt-0.5">争议题</div>
        </div>
        <div class="rounded-lg bg-emerald-50 p-3 text-center">
          <div class="text-xl font-bold text-emerald-600">{{ summary.finalized }}</div>
          <div class="text-xs text-gray-500 mt-0.5">已定稿</div>
        </div>
      </div>
    </div>

    <div v-if="paperId" class="grid grid-cols-1 lg:grid-cols-5 gap-6">
      <!-- 队列 -->
      <div class="lg:col-span-2 bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
        <div class="p-4 border-b border-gray-100 space-y-3">
          <div class="grid grid-cols-2 gap-2">
            <select v-model="filters.question_id" @change="loadQueue" class="rounded-lg border-gray-300 text-sm">
              <option value="">全部题目</option>
              <option v-for="q in essayQuestions" :key="q.id" :value="q.id">第{{ q.sort_order }}题</option>
            </select>
            <select v-model="filters.class_id" @change="loadQueue" class="rounded-lg border-gray-300 text-sm">
              <option value="">全部班级</option>
              <option v-for="c in classes" :key="c.id" :value="c.id">{{ c.name }}</option>
            </select>
          </div>
          <div class="grid grid-cols-3 gap-2 text-xs">
            <button v-for="t in statusTabs" :key="t.value"
              class="rounded-lg px-2 py-1.5 font-medium border transition"
              :class="filters.status === t.value ? 'bg-indigo-600 text-white border-indigo-600' : 'bg-white text-gray-600 border-gray-200 hover:bg-gray-50'"
              @click="setStatus(t.value)">{{ t.label }}</button>
          </div>
        </div>

        <div v-if="queueLoading" class="p-8 text-center text-gray-400 text-sm">加载中…</div>
        <div v-else-if="queue.length === 0" class="p-8 text-center text-gray-400 text-sm">没有符合条件的答卷</div>
        <ul v-else class="divide-y divide-gray-100 max-h-[600px] overflow-y-auto">
          <li v-for="item in queue" :key="item.id"
            class="p-4 cursor-pointer transition"
            :class="selectedId === item.id ? 'bg-indigo-50 border-l-4 border-indigo-600' : 'hover:bg-gray-50 border-l-4 border-transparent'"
            @click="selectItem(item.id)">
            <div class="flex items-center justify-between">
              <span class="text-sm font-semibold text-gray-800">{{ item.student?.real_name || item.student?.username || '未知学生' }}</span>
              <div class="flex gap-1">
                <span v-if="item.is_disputed" class="px-1.5 py-0.5 text-[10px] rounded bg-red-100 text-red-700">争议</span>
                <span v-if="item.is_high_score_sample" class="px-1.5 py-0.5 text-[10px] rounded bg-yellow-100 text-yellow-700">高分样卷</span>
              </div>
            </div>
            <div class="text-xs text-gray-400 mt-1">{{ item.question_title }}</div>
            <div class="flex items-center justify-between mt-2">
              <span class="text-xs px-2 py-0.5 rounded-full" :class="statusClass(item.status)">{{ item.status_text }}</span>
              <span class="text-xs text-gray-500">
                <template v-if="item.final_score !== null">最终 {{ item.final_score }} 分</template>
                <template v-else-if="item.initial_score !== null">初评 {{ item.initial_score }} 分</template>
                <template v-else>未评分</template>
              </span>
            </div>
          </li>
        </ul>
      </div>

      <!-- 批阅面板 -->
      <div class="lg:col-span-3">
        <GradingDetail v-if="selectedId" :key="selectedId" :grading-id="selectedId" @graded="loadQueue" />
        <div v-else class="bg-white rounded-xl shadow-sm border border-gray-100 p-12 text-center text-gray-400 text-sm">
          请从左侧选择一份答卷开始批阅
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted, computed } from 'vue'
import api from '../../api'
import GradingDetail from './GradingDetail.vue'

const papers = ref([])
const paperId = ref('')
const queue = ref([])
const summary = ref(null)
const classes = ref([])
const essayQuestions = ref([])
const queueLoading = ref(false)
const selectedId = ref(null)

const filters = ref({ question_id: '', class_id: '', status: '' })
const statusTabs = [
  { label: '未完成', value: '' },
  { label: '待初评', value: 'pending' },
  { label: '待复核', value: 'review_pending' },
  { label: '已定稿', value: 'finalized' },
]

const setStatus = (v) => { filters.value.status = v; loadQueue() }

const statusClass = (s) => ({
  pending: 'bg-amber-100 text-amber-700',
  review_pending: 'bg-blue-100 text-blue-700',
  finalized: 'bg-emerald-100 text-emerald-700',
}[s] || 'bg-gray-100 text-gray-600')

const loadPapers = async () => {
  const { data } = await api.get('/grading/papers')
  papers.value = data.papers
  if (papers.value.length) paperId.value = papers.value[0].id
}

const loadQueue = async () => {
  if (!paperId.value) return
  queueLoading.value = true
  try {
    const params = { per_page: 100 }
    Object.entries(filters.value).forEach(([k, v]) => { if (v !== '') params[k] = v })
    const { data } = await api.get(`/grading/papers/${paperId.value}/queue`, { params })
    queue.value = data.queue.data
    summary.value = data.summary
    await loadPaperMeta()
    if (queue.value.length && !queue.value.find(i => i.id === selectedId.value)) {
      selectedId.value = queue.value[0].id
    } else if (!queue.value.length) {
      selectedId.value = null
    }
  } finally {
    queueLoading.value = false
  }
}

const loadPaperMeta = async () => {
  const { data } = await api.get(`/exam-papers/${paperId.value}`)
  essayQuestions.value = (data.exam_paper?.questions || []).filter(q => q.type === 'essay')
}

const selectItem = (id) => { selectedId.value = id }

onMounted(async () => {
  const [, classesRes] = await Promise.all([loadPapers(), api.get('/classes')])
  classes.value = classesRes.data.classes
  await loadQueue()
})
</script>
