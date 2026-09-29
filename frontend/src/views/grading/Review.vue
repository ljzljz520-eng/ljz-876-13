<template>
  <div class="space-y-6">
    <div>
      <h1 class="text-2xl font-bold text-gray-900">复核工作台</h1>
      <p class="text-sm text-gray-500 mt-1">仅展示需要复核的答卷：争议题 + 高分样卷。维持初评或调整分数，调整必须填写原因。</p>
    </div>

    <div class="grid grid-cols-3 gap-4">
      <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-5 cursor-pointer" :class="tab==='all' && 'ring-2 ring-blue-500'" @click="tab='all'; load()">
        <div class="text-2xl font-bold text-blue-600">{{ counts.all }}</div>
        <div class="text-sm text-gray-500 mt-1">待复核总量</div>
      </div>
      <div class="bg-white rounded-xl shadow-sm border border-red-100 p-5 cursor-pointer" :class="tab==='disputed' && 'ring-2 ring-red-500'" @click="tab='disputed'; load()">
        <div class="text-2xl font-bold text-red-600">{{ counts.disputed }}</div>
        <div class="text-sm text-gray-500 mt-1">争议题</div>
      </div>
      <div class="bg-white rounded-xl shadow-sm border border-yellow-100 p-5 cursor-pointer" :class="tab==='high_score_sample' && 'ring-2 ring-yellow-500'" @click="tab='high_score_sample'; load()">
        <div class="text-2xl font-bold text-yellow-600">{{ counts.high_score_sample }}</div>
        <div class="text-sm text-gray-500 mt-1">高分样卷</div>
      </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-5 gap-6">
      <div class="lg:col-span-2 bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
        <div class="p-4 border-b border-gray-100 flex items-center gap-2">
          <select v-model="paperFilter" @change="load" class="flex-1 rounded-lg border-gray-300 text-sm">
            <option value="">全部试卷</option>
            <option v-for="p in papers" :key="p.id" :value="p.id">{{ p.title }}</option>
          </select>
        </div>
        <div v-if="loading" class="p-8 text-center text-gray-400 text-sm">加载中…</div>
        <div v-else-if="queue.length === 0" class="p-8 text-center text-gray-400 text-sm">暂无待复核项目 🎉</div>
        <ul v-else class="divide-y divide-gray-100 max-h-[600px] overflow-y-auto">
          <li v-for="item in queue" :key="item.id"
            class="p-4 cursor-pointer transition"
            :class="selectedId === item.id ? 'bg-blue-50 border-l-4 border-blue-600' : 'hover:bg-gray-50 border-l-4 border-transparent'"
            @click="selectedId = item.id">
            <div class="flex items-center justify-between">
              <span class="text-sm font-semibold text-gray-800">{{ item.student?.real_name || item.student?.username }}</span>
              <div class="flex gap-1">
                <span v-if="item.is_disputed" class="px-1.5 py-0.5 text-[10px] rounded bg-red-100 text-red-700">争议</span>
                <span v-if="item.is_high_score_sample" class="px-1.5 py-0.5 text-[10px] rounded bg-yellow-100 text-yellow-700">高分样卷</span>
              </div>
            </div>
            <div class="text-xs text-gray-400 mt-1">{{ item.exam_paper?.title }} · {{ item.question_title }}</div>
            <div class="text-xs text-gray-500 mt-2">初评：{{ item.initial_score }} 分（{{ item.initial_grader || '-' }}）</div>
          </li>
        </ul>
      </div>

      <div class="lg:col-span-3">
        <GradingDetail v-if="selectedId" :key="selectedId" :grading-id="selectedId" @graded="afterGraded" />
        <div v-else class="bg-white rounded-xl shadow-sm border border-gray-100 p-12 text-center text-gray-400 text-sm">
          请从左侧选择一份待复核答卷
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import api from '../../api'
import GradingDetail from './GradingDetail.vue'

const queue = ref([])
const counts = ref({ all: 0, disputed: 0, high_score_sample: 0 })
const papers = ref([])
const loading = ref(false)
const selectedId = ref(null)
const tab = ref('all')
const paperFilter = ref('')

const load = async () => {
  loading.value = true
  try {
    const params = { queue_type: tab.value, per_page: 100 }
    if (paperFilter.value) params.exam_paper_id = paperFilter.value
    const { data } = await api.get('/grading/review-queue', { params })
    queue.value = data.queue.data
    counts.value = data.counts
    if (selectedId.value && !queue.value.find(i => i.id === selectedId.value)) selectedId.value = null
    if (!selectedId.value && queue.value.length) selectedId.value = queue.value[0].id
  } finally {
    loading.value = false
  }
}

const afterGraded = () => { selectedId.value = null; load() }

onMounted(async () => {
  const { data } = await api.get('/grading/papers')
  papers.value = data.papers
  await load()
})
</script>
