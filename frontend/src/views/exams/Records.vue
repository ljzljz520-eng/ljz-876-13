<template>
  <div class="space-y-6">
    <h1 class="text-2xl font-bold text-gray-900">我的成绩</h1>
    <div v-if="loading" class="text-center py-8">
      <div class="animate-spin rounded-full h-8 w-8 border-b-2 border-indigo-600 mx-auto"></div>
    </div>
    <div v-else-if="records.length === 0" class="text-center py-8 text-gray-500">
      暂无考试记录
    </div>
    <div v-else class="bg-white shadow overflow-hidden sm:rounded-lg">
      <table class="min-w-full divide-y divide-gray-200">
        <thead class="bg-gray-50">
          <tr>
            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">试卷</th>
            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">得分</th>
            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">状态</th>
            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">考试时间</th>
            <th class="px-6 py-3"></th>
          </tr>
        </thead>
        <tbody class="bg-white divide-y divide-gray-200">
          <tr v-for="record in records" :key="record.id">
            <td class="px-6 py-4 whitespace-nowrap">{{ record.exam_paper?.title }}</td>
            <td class="px-6 py-4 whitespace-nowrap font-bold" :class="{'text-green-600': record.score >= 60 && record.status === 'graded', 'text-red-600': record.score < 60 && record.status === 'graded', 'text-gray-400': record.status !== 'graded'}">
              {{ record.status === 'graded' ? record.score + ' 分' : '批阅中' }}
            </td>
            <td class="px-6 py-4 whitespace-nowrap">
              <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full" :class="statusClass(record.status)">
                {{ statusText(record.status) }}
              </span>
            </td>
            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">{{ new Date(record.created_at).toLocaleString() }}</td>
            <td class="px-6 py-4 text-right">
              <button class="text-sm text-indigo-600 hover:text-indigo-500 font-medium" @click="openDetail(record)">查看详情</button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- 学生结果详情：只含得分点与简短评语 -->
    <div v-if="detailShow" class="fixed inset-0 z-[80] flex items-center justify-center bg-gray-600/60 p-4" @click.self="detailShow = false">
      <div class="bg-white rounded-2xl shadow-xl w-full max-w-3xl max-h-[85vh] overflow-y-auto">
        <div class="sticky top-0 bg-white px-6 py-4 border-b border-gray-100 flex items-center justify-between">
          <div>
            <h3 class="text-lg font-bold text-gray-900">{{ detail?.exam_paper?.title }}</h3>
            <p class="text-xs text-gray-500 mt-0.5">
              <template v-if="detail?.status === 'graded'">
                总分 <span class="font-bold text-indigo-600">{{ detail?.score }}</span> / {{ detail?.exam_paper?.total_score }} · 已评分
              </template>
              <template v-else>
                <span class="font-bold text-blue-600">主观题批阅中</span>，最终成绩将在批阅完成后公布 · {{ statusText(detail?.status) }}
              </template>
            </p>
          </div>
          <button class="text-gray-400 hover:text-gray-600 text-2xl leading-none" @click="detailShow = false">×</button>
        </div>

        <div class="p-6 space-y-5">
          <div v-for="ans in detail?.answers" :key="ans.id" class="border border-gray-100 rounded-xl p-4">
            <div class="flex items-start justify-between gap-3">
              <h4 class="text-sm font-bold text-gray-900">{{ ans.question.title }}</h4>
              <span class="text-sm font-bold whitespace-nowrap" :class="ans.score >= ans.question.full_score * 0.6 ? 'text-emerald-600' : 'text-red-500'">
                {{ ans.essay_grading && ans.essay_grading.status !== 'finalized' ? '批阅中' : ans.score + ' 分' }}
              </span>
            </div>
            <p class="text-xs text-gray-400 mt-0.5">
              {{ typeText(ans.question.type) }} · 满分 {{ ans.question.full_score }}
            </p>

            <div class="mt-2 text-sm text-gray-700">
              <span class="text-gray-400">我的作答：</span>
              <span class="whitespace-pre-wrap">{{ ans.answer }}</span>
            </div>

            <!-- 主观题：得分点 + 简短评语 -->
            <div v-if="ans.essay_grading" class="mt-3 rounded-lg bg-indigo-50/60 p-3">
              <div v-if="ans.essay_grading.status === 'pending'" class="text-sm text-amber-600">该题尚未完成批阅，请耐心等待。</div>
              <div v-else-if="ans.essay_grading.status === 'review_pending'" class="text-sm text-blue-600">初评已完成，正在复核中，最终成绩稍后公布。</div>
              <template v-else>
                <div class="text-xs font-semibold text-gray-600 mb-2">得分点</div>
                <ul class="space-y-1.5">
                  <li v-for="(rp, i) in ans.essay_grading.rubric_points" :key="i" class="flex items-center justify-between text-sm">
                    <span class="text-gray-700">{{ rp.title }}</span>
                    <span class="text-gray-600 whitespace-nowrap ml-3">
                      <span :class="rp.score >= rp.full_score ? 'text-emerald-600 font-semibold' : 'text-gray-700'">{{ rp.score }}</span>
                      / {{ rp.full_score }} 分
                    </span>
                  </li>
                </ul>
                <div v-if="ans.essay_grading.comment" class="mt-3 pt-3 border-t border-indigo-100">
                  <div class="text-xs font-semibold text-gray-600">老师评语</div>
                  <p class="text-sm text-gray-700 mt-1">{{ ans.essay_grading.comment }}</p>
                </div>
                <div v-if="ans.essay_grading.student_comments?.length" class="mt-2 space-y-1">
                  <div v-for="(c, ci) in ans.essay_grading.student_comments" :key="ci" class="text-xs text-gray-500">
                    {{ c.author }}：{{ c.content }}
                  </div>
                </div>
              </template>
            </div>

            <!-- 客观题：正误与答案 -->
            <div v-else class="mt-3 flex flex-wrap gap-4 text-xs text-gray-500">
              <span :class="ans.is_correct ? 'text-emerald-600 font-semibold' : 'text-red-500 font-semibold'">
                {{ ans.is_correct ? '回答正确' : '回答错误' }}
              </span>
              <span>参考答案：{{ ans.question.answer }}</span>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import api from '../../api'

const records = ref([])
const loading = ref(true)
const detailShow = ref(false)
const detail = ref(null)

const statusText = (s) => ({ graded: '已评分', submitted: '批阅中', in_progress: '进行中' }[s] || s)
const statusClass = (s) => ({
  graded: 'bg-green-100 text-green-800',
  submitted: 'bg-blue-100 text-blue-800',
  in_progress: 'bg-gray-100 text-gray-700',
}[s] || 'bg-gray-100 text-gray-700')
const typeText = (t) => ({
  single_choice: '单选题', multiple_choice: '多选题', true_false: '判断题',
  fill_blank: '填空题', essay: '主观题',
}[t] || t)

const openDetail = async (record) => {
  detailShow.value = true
  detail.value = null
  const { data } = await api.get(`/exams/records/${record.id}`)
  detail.value = data.record
}

onMounted(async () => {
  try {
    const response = await api.get('/exams/records')
    records.value = response.data.records.data
  } catch (e) {
    console.error('Failed to fetch records:', e)
  } finally {
    loading.value = false
  }
})
</script>
