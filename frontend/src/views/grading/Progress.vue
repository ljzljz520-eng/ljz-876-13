<template>
  <div class="space-y-6">
    <div>
      <h1 class="text-2xl font-bold text-gray-900">批阅进度统计</h1>
      <p class="text-sm text-gray-500 mt-1">按班级、按题目分别统计主观题的初评、复核与定稿进度。</p>
    </div>

    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-5">
      <div class="flex items-center gap-3 flex-wrap">
        <label class="text-sm font-medium text-gray-700">试卷范围</label>
        <select v-model="paperId" @change="load" class="rounded-lg border-gray-300 text-sm min-w-[240px]">
          <option value="">全部含主观题试卷</option>
          <option v-for="p in papers" :key="p.id" :value="p.id">{{ p.title }}</option>
        </select>
      </div>
    </div>

    <div v-if="loading" class="text-center py-8 text-gray-400 text-sm">加载中…</div>
    <template v-else-if="data">
      <!-- 总览 -->
      <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-5">
          <div class="text-2xl font-bold text-gray-800">{{ data.overview.total }}</div>
          <div class="text-xs text-gray-500 mt-1">主观题答卷总数</div>
        </div>
        <div class="bg-white rounded-xl shadow-sm border border-amber-100 p-5">
          <div class="text-2xl font-bold text-amber-600">{{ data.overview.pending }}</div>
          <div class="text-xs text-gray-500 mt-1">待初评</div>
        </div>
        <div class="bg-white rounded-xl shadow-sm border border-blue-100 p-5">
          <div class="text-2xl font-bold text-blue-600">{{ data.overview.review_pending }}</div>
          <div class="text-xs text-gray-500 mt-1">待复核（争议/高分样卷）</div>
        </div>
        <div class="bg-white rounded-xl shadow-sm border border-emerald-100 p-5">
          <div class="text-2xl font-bold text-emerald-600">{{ data.overview.graded_rate }}%</div>
          <div class="text-xs text-gray-500 mt-1">定稿率（{{ data.overview.finalized }}/{{ data.overview.total }}）</div>
        </div>
      </div>

      <!-- 按班级 -->
      <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
        <div class="px-5 py-4 border-b border-gray-100">
          <h3 class="font-bold text-gray-900">按班级统计</h3>
        </div>
        <table class="min-w-full divide-y divide-gray-200">
          <thead class="bg-gray-50">
            <tr class="text-left text-xs text-gray-500 uppercase">
              <th class="px-5 py-3">班级</th>
              <th class="px-3 py-3">总量</th>
              <th class="px-3 py-3">待初评</th>
              <th class="px-3 py-3">待复核</th>
              <th class="px-3 py-3">已定稿</th>
              <th class="px-3 py-3">争议</th>
              <th class="px-3 py-3">高分样卷</th>
              <th class="px-5 py-3 w-48">定稿进度</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100 text-sm">
            <tr v-for="row in data.by_class" :key="(row.id ?? 'none') + row.name">
              <td class="px-5 py-3 font-medium text-gray-800">{{ row.name }}</td>
              <td class="px-3 py-3">{{ row.total }}</td>
              <td class="px-3 py-3 text-amber-600">{{ row.pending }}</td>
              <td class="px-3 py-3 text-blue-600">{{ row.review_pending }}</td>
              <td class="px-3 py-3 text-emerald-600">{{ row.finalized }}</td>
              <td class="px-3 py-3 text-red-600">{{ row.disputed }}</td>
              <td class="px-3 py-3 text-yellow-600">{{ row.high_score_sample }}</td>
              <td class="px-5 py-3">
                <div class="flex items-center gap-2">
                  <div class="flex-1 h-2 bg-gray-100 rounded-full overflow-hidden">
                    <div class="h-full bg-emerald-500" :style="{ width: row.graded_rate + '%' }"></div>
                  </div>
                  <span class="text-xs text-gray-500 w-12 text-right">{{ row.graded_rate }}%</span>
                </div>
              </td>
            </tr>
            <tr v-if="data.by_class.length === 0"><td colspan="8" class="px-5 py-8 text-center text-gray-400">暂无数据</td></tr>
          </tbody>
        </table>
      </div>

      <!-- 按题目 -->
      <div class="space-y-4">
        <h3 class="font-bold text-gray-900">按题目统计</h3>
        <div v-for="paper in data.by_question" :key="paper.exam_paper_id" class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
          <div class="px-5 py-3 bg-gray-50 text-sm font-semibold text-gray-700">{{ paper.exam_paper_title }}</div>
          <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-white">
              <tr class="text-left text-xs text-gray-500 uppercase">
                <th class="px-5 py-3">题目</th>
                <th class="px-3 py-3">满分</th>
                <th class="px-3 py-3">总量</th>
                <th class="px-3 py-3">待初评</th>
                <th class="px-3 py-3">待复核</th>
                <th class="px-3 py-3">已定稿</th>
                <th class="px-3 py-3">争议</th>
                <th class="px-5 py-3">高分样卷</th>
                <th class="px-5 py-3 w-40">定稿率</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 text-sm">
              <tr v-for="q in paper.questions" :key="q.id">
                <td class="px-5 py-3 text-gray-800 max-w-xs truncate" :title="q.name">{{ q.name }}</td>
                <td class="px-3 py-3">{{ q.full_score }}</td>
                <td class="px-3 py-3">{{ q.total }}</td>
                <td class="px-3 py-3 text-amber-600">{{ q.pending }}</td>
                <td class="px-3 py-3 text-blue-600">{{ q.review_pending }}</td>
                <td class="px-3 py-3 text-emerald-600">{{ q.finalized }}</td>
                <td class="px-3 py-3 text-red-600">{{ q.disputed }}</td>
                <td class="px-3 py-3 text-yellow-600">{{ q.high_score_sample }}</td>
                <td class="px-5 py-3">
                  <div class="flex items-center gap-2">
                    <div class="flex-1 h-2 bg-gray-100 rounded-full overflow-hidden">
                      <div class="h-full bg-indigo-500" :style="{ width: q.graded_rate + '%' }"></div>
                    </div>
                    <span class="text-xs text-gray-500 w-12 text-right">{{ q.graded_rate }}%</span>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </template>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import api from '../../api'

const papers = ref([])
const paperId = ref('')
const data = ref(null)
const loading = ref(true)

const load = async () => {
  loading.value = true
  try {
    const params = {}
    if (paperId.value) params.exam_paper_id = paperId.value
    const res = await api.get('/grading/progress', { params })
    data.value = res.data
  } finally {
    loading.value = false
  }
}

onMounted(async () => {
  const { data: p } = await api.get('/grading/papers')
  papers.value = p.papers
  await load()
})
</script>
