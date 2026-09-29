<template>
  <div v-if="loading" class="bg-white rounded-xl shadow-sm border border-gray-100 p-12 text-center text-gray-400 text-sm">加载中…</div>
  <div v-else-if="!grading" class="bg-white rounded-xl rounded-xl shadow-sm border border-gray-100 p-12 text-center text-red-400 text-sm">批阅数据加载失败</div>
  <div v-else class="space-y-4">
    <!-- 题目与考生答案 -->
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-5">
      <div class="flex items-start justify-between gap-3 flex-wrap">
        <div>
          <div class="flex items-center gap-2 flex-wrap">
            <span class="text-xs px-2 py-0.5 rounded-full" :class="statusClass(grading.status)">{{ grading.status_text }}</span>
            <span v-if="grading.is_disputed" class="text-xs px-2 py-0.5 rounded-full bg-red-100 text-red-700">争议题</span>
            <span v-if="grading.is_high_score_sample" class="text-xs px-2 py-0.5 rounded-full bg-yellow-100 text-yellow-700">高分样卷</span>
          </div>
          <h3 class="text-base font-bold text-gray-900 mt-2">{{ grading.question.title }}</h3>
          <p class="text-xs text-gray-400 mt-1">本题满分 {{ grading.full_score }} 分</p>
        </div>
        <div class="text-right text-xs text-gray-500">
          <div class="font-semibold text-gray-700">{{ grading.student?.real_name || grading.student?.username }}</div>
          <div class="mt-0.5">{{ classNames }}</div>
        </div>
      </div>

      <div class="mt-4 rounded-lg bg-gray-50 border border-gray-100 p-3">
        <div class="text-xs font-semibold text-gray-500 mb-1">考生作答</div>
        <p class="text-sm text-gray-800 whitespace-pre-wrap leading-relaxed">{{ grading.student_answer }}</p>
      </div>
      <details class="mt-2">
        <summary class="text-xs text-indigo-600 cursor-pointer">查看参考答案 / 解析</summary>
        <div class="mt-2 text-sm text-gray-600 space-y-1">
          <p><span class="font-semibold">参考答案：</span>{{ grading.question.answer }}</p>
          <p v-if="grading.question.analysis"><span class="font-semibold">解析：</span>{{ grading.question.analysis }}</p>
        </div>
      </details>
    </div>

    <!-- 初评表单（待初评 / 管理员/老师；已定稿也可查看得分点） -->
    <div v-if="authStore.canInitialGrade && (grading.status === 'pending' || grading.status === 'review_pending')" class="bg-white rounded-xl shadow-sm border border-gray-100 p-5">
      <h4 class="text-sm font-bold text-gray-900 mb-3">评分点初评（合计 {{ initialTotal }} / {{ grading.full_score }} 分）</h4>
      <div class="space-y-3">
        <div v-for="rp in grading.rubric_points" :key="rp.id" class="flex items-start gap-3">
          <div class="flex-1">
            <div class="text-sm font-medium text-gray-800">{{ rp.title }} <span class="text-xs text-gray-400">（满分 {{ rp.full_score }}）</span></div>
            <div v-if="rp.description" class="text-xs text-gray-400 mt-0.5">{{ rp.description }}</div>
          </div>
          <div class="flex items-center gap-1">
            <input v-model.number="form.scores[rp.id]" type="number" :min="0" :max="rp.full_score" :step="0.5"
              class="w-20 rounded-lg border-gray-300 text-sm focus:ring-indigo-500 focus:border-indigo-500" />
            <span class="text-xs text-gray-400">/ {{ rp.full_score }}</span>
          </div>
        </div>
        <div v-if="grading.rubric_points.length === 0" class="text-sm text-red-500">该题尚未配置评分点，请先在题库管理中设置。</div>
      </div>

      <div class="mt-4">
        <label class="text-xs font-semibold text-gray-600">简短评语（学生可见）</label>
        <textarea v-model="form.comment" rows="2" maxlength="1000"
          class="mt-1 w-full rounded-lg border-gray-300 text-sm focus:ring-indigo-500 focus:border-indigo-500"
          placeholder="例如：ACID 四点答全，隔离性解释略有偏差。"></textarea>
      </div>

      <div class="mt-3 space-y-2">
        <label class="flex items-center gap-2 text-sm text-gray-700">
          <input type="checkbox" v-model="form.mark_disputed" class="rounded border-gray-300 text-red-600 focus:ring-red-500">
          标记为争议题，提交复核老师
        </label>
        <textarea v-if="form.mark_disputed" v-model="form.dispute_reason" rows="2" required maxlength="1000"
          class="w-full rounded-lg border-red-300 text-sm focus:ring-red-500 focus:border-red-500"
          placeholder="请说明争议原因（必填，将记录到修改轨迹）"></textarea>

        <label class="flex items-center gap-2 text-sm text-gray-700">
          <input type="checkbox" v-model="form.mark_high_sample" class="rounded border-gray-300 text-yellow-600 focus:ring-yellow-500">
          手动标记为高分样卷（初评 ≥90% 会自动标记）
        </label>
      </div>

      <div class="mt-4 flex justify-end">
        <button :disabled="submitting || grading.rubric_points.length === 0"
          class="px-5 py-2 rounded-lg bg-indigo-600 text-white text-sm font-semibold hover:bg-indigo-500 disabled:opacity-50"
          @click="submitInitial">提交初评</button>
      </div>
    </div>

    <!-- 复核表单 -->
    <div v-if="authStore.canReview && grading.status === 'review_pending'" class="bg-white rounded-xl shadow-sm border border-gray-100 p-5 border-l-4 border-l-blue-500">
      <h4 class="text-sm font-bold text-gray-900 mb-3">复核意见（争议题 / 高分样卷）</h4>
      <div class="text-sm text-gray-600 mb-3">
        初评得分：<span class="font-bold text-gray-900">{{ grading.initial_score }}</span> 分 ·
        初评人：{{ grading.initial_grader?.name || '-' }}
      </div>
      <div class="space-y-3">
        <div v-for="rp in grading.rubric_points" :key="'r'+rp.id" class="flex items-center gap-3">
          <div class="flex-1 text-sm text-gray-800">{{ rp.title }}</div>
          <span class="text-xs text-gray-400 w-24 text-right">初评 {{ rp.initial_score ?? '-' }}</span>
          <input v-model.number="reviewForm.scores[rp.id]" type="number" :min="0" :max="rp.full_score" :step="0.5"
            class="w-20 rounded-lg border-gray-300 text-sm focus:ring-blue-500 focus:border-blue-500" />
        </div>
      </div>
      <div class="mt-3">
        <label class="text-xs font-semibold text-gray-600">复核后给学生的评语</label>
        <textarea v-model="reviewForm.final_comment" rows="2" maxlength="1000" class="mt-1 w-full rounded-lg border-gray-300 text-sm"></textarea>
      </div>
      <label class="mt-3 flex items-center gap-2 text-sm text-gray-700">
        <input type="checkbox" v-model="reviewForm.resolve_dispute" class="rounded border-gray-300 text-blue-600 focus:ring-blue-500">
        复核完成后解除争议标记
      </label>
      <div class="mt-2">
        <label class="text-xs font-semibold text-gray-600">复核结论 / 改分原因（必填，永久留痕）</label>
        <textarea v-model="reviewForm.reason" rows="2" required maxlength="1000"
          class="mt-1 w-full rounded-lg border-gray-300 text-sm focus:ring-blue-500 focus:border-blue-500"
          placeholder="例如：维持初评 / 第2评分点隔离性解释完整，应给满分，上调 0.5 分。"></textarea>
      </div>
      <div class="mt-4 flex justify-end gap-2">
        <button :disabled="submitting" class="px-4 py-2 rounded-lg bg-white border border-gray-300 text-sm font-semibold text-gray-700 hover:bg-gray-50" @click="submitReview('confirm')">维持初评</button>
        <button :disabled="submitting" class="px-4 py-2 rounded-lg bg-blue-600 text-white text-sm font-semibold hover:bg-blue-500" @click="submitReview('adjust')">按复核分定稿</button>
      </div>
    </div>

    <!-- 已定稿结果 -->
    <div v-if="grading.status === 'finalized'" class="bg-white rounded-xl shadow-sm border border-gray-100 p-5 border-l-4 border-l-emerald-500">
      <h4 class="text-sm font-bold text-gray-900">最终定稿</h4>
      <div class="mt-2 grid grid-cols-2 gap-3 text-sm">
        <div class="text-gray-500">初评：{{ grading.initial_score }} 分（{{ grading.initial_grader?.name || '-' }}）</div>
        <div class="text-gray-500">复核：{{ grading.final_score }} 分（{{ grading.reviewer?.name || '-' }}）</div>
      </div>
      <p v-if="grading.final_comment" class="mt-2 text-sm text-gray-700">评语：{{ grading.final_comment }}</p>
      <div v-if="authStore.isGradingStaff" class="mt-3 flex gap-2">
        <button class="text-xs px-3 py-1.5 rounded-lg border border-red-200 text-red-600 hover:bg-red-50" @click="reFlag('dispute')">重新标记争议</button>
        <button class="text-xs px-3 py-1.5 rounded-lg border border-yellow-200 text-yellow-700 hover:bg-yellow-50" @click="reFlag('sample')">追加为高分样卷</button>
      </div>
    </div>

    <!-- 内部讨论（学生不可见） -->
    <div v-if="authStore.isGradingStaff" class="bg-white rounded-xl shadow-sm border border-gray-100 p-5">
      <h4 class="text-sm font-bold text-gray-900">老师内部讨论 <span class="text-xs font-normal text-gray-400">（仅教师/复核可见，不对学生展示）</span></h4>
      <div class="mt-3 space-y-2 max-h-44 overflow-y-auto">
        <div v-for="c in internalComments" :key="c.id" class="text-sm bg-gray-50 rounded-lg p-2">
          <div class="text-xs text-gray-400">{{ roleLabel(c.author_role) }} · {{ c.author }} · {{ formatTime(c.created_at) }}</div>
          <div class="text-gray-800 mt-0.5">{{ c.content }}</div>
        </div>
        <div v-if="internalComments.length === 0" class="text-xs text-gray-400">暂无内部讨论</div>
      </div>
      <div class="mt-2 flex gap-2">
        <input v-model="newInternal" maxlength="1000" class="flex-1 rounded-lg border-gray-300 text-sm" placeholder="写下内部意见…">
        <button class="px-3 py-2 text-sm rounded-lg bg-gray-800 text-white hover:bg-gray-700" @click="addComment('internal')">发送</button>
      </div>

      <h4 class="text-sm font-bold text-gray-900 mt-5">给学生的简短评语</h4>
      <div class="mt-2 space-y-2 max-h-32 overflow-y-auto">
        <div v-for="c in studentComments" :key="c.id" class="text-sm bg-indigo-50 rounded-lg p-2 text-gray-700">
          {{ c.content }} <span class="text-xs text-gray-400">— {{ c.author }}</span>
        </div>
      </div>
      <div class="mt-2 flex gap-2">
        <input v-model="newStudent" maxlength="1000" class="flex-1 rounded-lg border-gray-300 text-sm" placeholder="对学生可见的补充评语…">
        <button class="px-3 py-2 text-sm rounded-lg bg-indigo-600 text-white hover:bg-indigo-500" @click="addComment('student')">添加</button>
      </div>
    </div>

    <!-- 修改轨迹 -->
    <div v-if="authStore.isGradingStaff" class="bg-white rounded-xl shadow-sm border border-gray-100 p-5">
      <h4 class="text-sm font-bold text-gray-900">修改轨迹（每次分数变更均可追溯）</h4>
      <ol class="mt-3 relative border-l border-gray-200 ml-2 space-y-4">
        <li v-for="log in auditLogs" :key="log.id" class="ml-4">
          <div class="absolute -left-1.5 w-3 h-3 rounded-full bg-indigo-500 border-2 border-white"></div>
          <div class="text-xs text-gray-400">{{ formatTime(log.created_at) }} · {{ log.action_text }} · {{ roleLabel(log.operator_role) }} {{ log.operator }}</div>
          <div v-if="log.score_before !== null || log.score_after !== null" class="text-sm text-gray-700 mt-0.5">
            分数：<span class="line-through text-gray-400">{{ log.score_before ?? '-' }}</span>
            → <span class="font-semibold">{{ log.score_after ?? '-' }}</span>
          </div>
          <div class="text-sm text-gray-600 mt-0.5">原因：{{ log.reason }}</div>
        </li>
        <li v-if="auditLogs.length === 0" class="ml-4 text-xs text-gray-400">暂无操作记录</li>
      </ol>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted, computed } from 'vue'
import api from '../../api'
import { useAuthStore } from '../../stores/auth'
import { useToast } from '../../composables/useToast'

const props = defineProps({ gradingId: { type: Number, required: true } })
const emit = defineEmits(['graded'])

const authStore = useAuthStore()
const { success: toastSuccess } = useToast()

const loading = ref(true)
const submitting = ref(false)
const grading = ref(null)
const auditLogs = ref([])
const form = ref({ scores: {}, comment: '', mark_disputed: false, mark_high_sample: false, dispute_reason: '' })
const reviewForm = ref({ scores: {}, final_comment: '', reason: '', resolve_dispute: true })
const newInternal = ref('')
const newStudent = ref('')

const internalComments = computed(() => (grading.value?.comments || []).filter(c => c.visibility === 'internal'))
const studentComments = computed(() => (grading.value?.comments || []).filter(c => c.visibility === 'student'))
const initialTotal = computed(() =>
  Math.round(Object.values(form.value.scores).reduce((a, b) => a + (Number(b) || 0), 0) * 100) / 100
)
const classNames = computed(() => (grading.value?.student?.classes || []).map(c => c.name).join('、') || '未分班')

const statusClass = (s) => ({
  pending: 'bg-amber-100 text-amber-700',
  review_pending: 'bg-blue-100 text-blue-700',
  finalized: 'bg-emerald-100 text-emerald-700',
}[s] || 'bg-gray-100 text-gray-600')

const roleLabel = (role) => ({ admin: '管理员', teacher: '阅卷老师', reviewer: '复核老师', student: '学生' }[role] || role)
const formatTime = (t) => t ? new Date(t).toLocaleString() : '-'

const load = async () => {
  loading.value = true
  try {
    const { data } = await api.get(`/grading/gradings/${props.gradingId}`)
    grading.value = data.grading
    const initScores = {}
    const reviewScores = {}
    data.grading.rubric_points.forEach(rp => {
      initScores[rp.id] = rp.initial_score !== null ? rp.initial_score : 0
      reviewScores[rp.id] = rp.final_score !== null ? rp.final_score : rp.initial_score
    })
    form.value.scores = initScores
    form.value.comment = data.grading.initial_comment || ''
    reviewForm.value.scores = reviewScores
    reviewForm.value.final_comment = data.grading.final_comment || data.grading.initial_comment || ''
    const audit = await api.get(`/grading/gradings/${props.gradingId}/audit`)
    auditLogs.value = audit.data.audit_logs
  } finally {
    loading.value = false
  }
}

const submitInitial = async () => {
  if (form.value.mark_disputed && !form.value.dispute_reason.trim()) {
    useToast().error('标记争议题必须填写原因')
    return
  }
  submitting.value = true
  try {
    await api.post(`/grading/gradings/${props.gradingId}/initial`, {
      points: Object.entries(form.value.scores).map(([rubric_point_id, score]) => ({ rubric_point_id: Number(rubric_point_id), score: Number(score) })),
      comment: form.value.comment,
      mark_disputed: form.value.mark_disputed,
      dispute_reason: form.value.dispute_reason,
      mark_high_sample: form.value.mark_high_sample,
    })
    toastSuccess('初评已提交')
    emit('graded')
    await load()
  } finally {
    submitting.value = false
  }
}

const submitReview = async (decision) => {
  if (!reviewForm.value.reason.trim()) {
    useToast().error('复核必须填写结论/改分原因')
    return
  }
  submitting.value = true
  try {
    await api.post(`/grading/gradings/${props.gradingId}/review`, {
      decision,
      reason: reviewForm.value.reason,
      final_comment: reviewForm.value.final_comment,
      resolve_dispute: reviewForm.value.resolve_dispute,
      points: Object.entries(reviewForm.value.scores).map(([rubric_point_id, score]) => ({ rubric_point_id: Number(rubric_point_id), score: Number(score) })),
    })
    toastSuccess('复核完成，最终分数已定稿')
    emit('graded')
    await load()
  } finally {
    submitting.value = false
  }
}

const reFlag = async (type) => {
  const reason = window.prompt(type === 'dispute' ? '请输入重新标记争议的原因：' : '请输入追加高分样卷的原因：')
  if (!reason) return
  await api.post(`/grading/gradings/${props.gradingId}/${type}`, {
    [type === 'dispute' ? 'is_disputed' : 'is_high_score_sample']: true,
    reason,
  })
  toastSuccess('已提交复核')
  emit('graded')
  await load()
}

const addComment = async (visibility) => {
  const content = visibility === 'internal' ? newInternal.value.trim() : newStudent.value.trim()
  if (!content) return
  await api.post(`/grading/gradings/${props.gradingId}/comments`, { content, visibility })
  if (visibility === 'internal') newInternal.value = ''
  else newStudent.value = ''
  await load()
}

onMounted(load)
</script>
