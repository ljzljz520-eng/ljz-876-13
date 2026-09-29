<template>
  <div class="space-y-6">
    <div class="flex items-center justify-between">
      <h1 class="text-2xl font-bold text-gray-900">成绩详情</h1>
      <router-link to="/records" class="text-indigo-600 hover:text-indigo-800 text-sm font-medium">← 返回我的成绩</router-link>
    </div>

    <div v-if="loading" class="text-center py-8">
      <div class="animate-spin rounded-full h-8 w-8 border-b-2 border-indigo-600 mx-auto"></div>
    </div>

    <template v-else-if="record">
      <!-- 成绩总览 -->
      <div class="bg-white rounded-lg shadow p-6">
        <div class="flex flex-wrap items-center justify-between gap-4">
          <div>
            <h2 class="text-lg font-bold text-gray-900">{{ record.exam_paper?.title }}</h2>
            <p class="text-sm text-gray-500 mt-1">提交时间：{{ record.end_time ? new Date(record.end_time).toLocaleString() : '—' }}</p>
          </div>
          <div class="text-right">
            <div class="text-3xl font-bold" :class="record.status === 'graded' ? 'text-indigo-600' : 'text-gray-400'">
              {{ record.status === 'graded' ? record.score : '—' }}
              <span class="text-base font-normal text-gray-400">/ {{ record.exam_paper?.total_score }}</span>
            </div>
            <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full mt-1" :class="record.status === 'graded' ? 'bg-green-100 text-green-800' : 'bg-orange-100 text-orange-700'">
              {{ record.status === 'graded' ? '已评分' : '主观题批阅中' }}
            </span>
          </div>
        </div>
        <p v-if="record.status !== 'graded'" class="mt-3 text-sm text-orange-600 bg-orange-50 rounded-lg px-4 py-2">
          主观题正在批阅中，当前仅显示客观题得分，最终成绩以批阅完成后为准。
        </p>
      </div>

      <!-- 逐题明细 -->
      <div v-for="(answer, index) in record.answers" :key="answer.id" class="bg-white rounded-lg shadow p-6">
        <div class="flex items-start justify-between">
          <div class="flex items-start space-x-3 flex-1 min-w-0">
            <span class="bg-indigo-100 text-indigo-800 text-sm font-medium px-2.5 py-0.5 rounded flex-shrink-0">{{ index + 1 }}</span>
            <div class="flex-1 min-w-0">
              <h3 class="text-base font-medium text-gray-900">{{ answer.question?.title }}</h3>
              <p class="text-xs text-gray-400 mt-1">{{ typeLabel(answer.question?.type) }}</p>
            </div>
          </div>
          <div class="text-right flex-shrink-0 ml-4">
            <span class="text-lg font-bold" :class="scoreClass(answer)">{{ displayScore(answer) }}</span>
          </div>
        </div>

        <div class="mt-4 bg-gray-50 rounded-lg p-4">
          <div class="text-xs font-semibold text-gray-500 mb-1">我的答案</div>
          <div class="text-sm text-gray-800 whitespace-pre-wrap">{{ formatAnswer(answer) }}</div>
        </div>

        <!-- 主观题：得分点 + 评语（批阅完成后可见） -->
        <template v-if="answer.question?.type === 'essay'">
          <div v-if="answer.grading_status === 'pending' || answer.grading_status === 'initial_graded'" class="mt-3 text-sm text-gray-500 bg-gray-50 rounded-lg px-4 py-3">
            本题等待老师批阅，批阅完成后将显示得分点与评语。
          </div>
          <template v-else>
            <div v-if="answer.rubric_scores?.length" class="mt-4">
              <div class="text-xs font-semibold text-gray-500 mb-2">得分点</div>
              <div class="space-y-2">
                <div v-for="(point, i) in answer.rubric_scores" :key="i" class="flex items-center justify-between bg-indigo-50/60 rounded-lg px-4 py-2.5">
                  <span class="text-sm text-gray-800">{{ point.title }}</span>
                  <span class="text-sm font-semibold text-indigo-700">{{ point.score }} <span class="text-xs font-normal text-gray-400">/ {{ point.max_score }}</span></span>
                </div>
              </div>
            </div>
            <div v-if="answer.student_comment" class="mt-3 bg-green-50 rounded-lg px-4 py-3">
              <div class="text-xs font-semibold text-green-600 mb-1">老师评语</div>
              <div class="text-sm text-gray-800">{{ answer.student_comment }}</div>
            </div>
          </template>
        </template>

        <!-- 客观题解析 -->
        <div v-else-if="answer.question?.analysis" class="mt-3 bg-blue-50/60 rounded-lg px-4 py-3">
          <div class="text-xs font-semibold text-blue-600 mb-1">解析</div>
          <div class="text-sm text-gray-700">{{ answer.question.analysis }}</div>
        </div>
      </div>
    </template>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { useRoute } from 'vue-router'
import api from '../../api'

const route = useRoute()
const record = ref(null)
const loading = ref(true)

const typeLabel = (type) => {
  const labels = {
    single_choice: '单选题',
    multiple_choice: '多选题',
    true_false: '判断题',
    fill_blank: '填空题',
    essay: '问答题'
  }
  return labels[type] || type
}

const displayScore = (answer) => {
  if (answer.question?.type === 'essay' && (answer.grading_status === 'pending' || answer.grading_status === 'initial_graded')) {
    return '待批阅'
  }
  return `${answer.score} 分`
}

const scoreClass = (answer) => {
  if (answer.question?.type === 'essay' && (answer.grading_status === 'pending' || answer.grading_status === 'initial_graded')) {
    return 'text-gray-400 text-sm'
  }
  return answer.score > 0 ? 'text-green-600' : 'text-red-500'
}

const formatAnswer = (answer) => {
  if (answer.answer === '' || answer.answer === null) return '（未作答）'
  if (answer.question?.type === 'true_false') {
    return answer.answer === 'true' ? '正确' : '错误'
  }
  return answer.answer
}

onMounted(async () => {
  try {
    const response = await api.get(`/exams/records/${route.params.id}`)
    record.value = response.data.record
  } catch (e) {
    console.error('Failed to fetch record detail:', e)
  } finally {
    loading.value = false
  }
})
</script>
