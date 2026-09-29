<template>
  <div class="space-y-6">
    <div class="flex justify-between items-center">
      <h1 class="text-2xl font-bold text-gray-900">题库管理</h1>
      <button @click="openAddModal" class="bg-indigo-600 text-white px-4 py-2 rounded hover:bg-indigo-700">添加题目</button>
    </div>
    <div v-if="loading" class="text-center py-8">
      <div class="animate-spin rounded-full h-8 w-8 border-b-2 border-indigo-600 mx-auto"></div>
      <p class="mt-2 text-gray-500">加载中...</p>
    </div>
    <div v-else-if="questions.length === 0" class="text-center py-8 text-gray-500 bg-white rounded-lg shadow">
      暂无题目，请点击"添加题目"创建
    </div>
    <div v-else class="bg-white shadow overflow-hidden sm:rounded-lg">
      <table class="min-w-full divide-y divide-gray-200">
        <thead class="bg-gray-50">
          <tr>
            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">ID</th>
            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">题目</th>
            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">类型</th>
            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">分值</th>
            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">操作</th>
          </tr>
        </thead>
        <tbody class="bg-white divide-y divide-gray-200">
          <tr v-for="q in questions" :key="q.id" class="hover:bg-gray-50">
            <td class="px-6 py-4">{{ q.id }}</td>
            <td class="px-6 py-4 truncate max-w-xs">{{ q.title }}</td>
            <td class="px-6 py-4">{{ getTypeName(q.type) }}</td>
            <td class="px-6 py-4">{{ q.score }}</td>
            <td class="px-6 py-4">
              <button @click="openEditModal(q)" class="text-indigo-600 hover:text-indigo-900 mr-3">编辑</button>
              <button v-if="q.type === 'essay'" @click="openRubric(q)" class="text-amber-600 hover:text-amber-800 mr-3">评分点</button>
              <button @click="deleteQuestion(q)" class="text-red-600 hover:text-red-900">删除</button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- 添加/编辑题目模态框 (Business Modal - z-50) -->
    <Teleport to="body">
      <Transition
        enter-active-class="ease-out duration-200"
        enter-from-class="opacity-0"
        enter-to-class="opacity-100"
        leave-active-class="ease-in duration-150"
        leave-from-class="opacity-100"
        leave-to-class="opacity-0"
      >
        <div v-if="showModal" class="fixed inset-0 z-50 overflow-y-auto" aria-labelledby="modal-title" role="dialog" aria-modal="true">
          <!-- Backdrop -->
          <div class="fixed inset-0 bg-gray-600/75 backdrop-blur-sm transition-opacity" @click="closeModal"></div>

          <!-- Modal Panel -->
          <div class="flex min-h-full items-center justify-center p-4">
            <Transition
              enter-active-class="ease-out duration-200"
              enter-from-class="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
              enter-to-class="opacity-100 translate-y-0 sm:scale-100"
              leave-active-class="ease-in duration-150"
              leave-from-class="opacity-100 translate-y-0 sm:scale-100"
              leave-to-class="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
            >
              <div class="relative w-full max-w-2xl transform overflow-hidden rounded-2xl bg-white shadow-2xl transition-all border border-gray-100">
                <!-- Header -->
                <div class="px-6 py-4 border-b border-gray-100 bg-gray-50/50 flex justify-between items-center">
                  <h3 class="text-lg font-bold text-gray-900">{{ isEditing ? '编辑题目' : '添加题目' }}</h3>
                  <button @click="closeModal" class="text-gray-400 hover:text-gray-500 bg-white rounded-full p-1 hover:bg-gray-100 transition-colors">
                    <span class="sr-only">Close</span>
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                      <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                  </button>
                </div>

                <!-- Body -->
                <div class="px-6 py-6 max-h-[calc(100vh-14rem)] overflow-y-auto custom-scrollbar">
                  <div class="space-y-5">
                    <!-- Category -->
                    <div>
                      <label class="block text-sm font-semibold text-gray-700 mb-2">分类 <span class="text-red-500">*</span></label>
                      <select v-model="form.category_id" class="input-base">
                        <option value="">请选择分类</option>
                        <option v-for="cat in categories" :key="cat.id" :value="cat.id">{{ cat.name }}</option>
                      </select>
                    </div>
                    
                    <!-- Type -->
                    <div>
                      <label class="block text-sm font-semibold text-gray-700 mb-2">题目类型</label>
                      <select v-model="form.type" class="input-base">
                        <option value="single_choice">单选题</option>
                        <option value="multiple_choice">多选题</option>
                        <option value="true_false">判断题</option>
                        <option value="fill_blank">填空题</option>
                        <option value="essay">问答题</option>
                      </select>
                    </div>
                    
                    <!-- Content -->
                    <div>
                      <label class="block text-sm font-semibold text-gray-700 mb-2">题目内容 <span class="text-red-500">*</span></label>
                      <textarea v-model="form.title" rows="3" class="input-base resize-y" placeholder="请输入题目详细描述..."></textarea>
                    </div>
                    
                    <!-- Options -->
                    <div v-if="['single_choice', 'multiple_choice'].includes(form.type)" class="bg-gray-50 p-4 rounded-xl border border-gray-200/60">
                      <label class="block text-sm font-semibold text-gray-700 mb-3">选项设置</label>
                      <div class="space-y-3">
                        <div v-for="(opt, key) in ['A', 'B', 'C', 'D']" :key="key" class="flex items-center gap-3">
                          <span class="flex-shrink-0 w-8 h-8 flex items-center justify-center rounded-full bg-white border border-gray-200 text-sm font-medium text-gray-500 shadow-sm">{{ opt }}</span>
                          <input v-model="form.options[opt]" type="text" class="input-base flex-1" :placeholder="`输入选项 ${opt} 的内容`" />
                        </div>
                      </div>
                    </div>
                    
                    <!-- Answer -->
                    <div>
                      <label class="block text-sm font-semibold text-gray-700 mb-2">正确答案 <span class="text-red-500">*</span></label>
                      <input v-model="form.answer" type="text" class="input-base" :placeholder="getAnswerPlaceholder()" />
                      <p class="mt-1 text-xs text-gray-500">提示: {{ getAnswerPlaceholder() }}</p>
                    </div>
                    
                    <!-- Analysis -->
                    <div>
                      <label class="block text-sm font-semibold text-gray-700 mb-2">解析</label>
                      <textarea v-model="form.analysis" rows="2" class="input-base resize-y" placeholder="请输入题目解析（选填）"></textarea>
                    </div>
                    
                    <!-- Meta -->
                    <div class="grid grid-cols-2 gap-5">
                      <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-2">难度</label>
                        <select v-model="form.difficulty" class="input-base">
                          <option :value="1">简单</option>
                          <option :value="2">中等</option>
                          <option :value="3">困难</option>
                        </select>
                      </div>
                      <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-2">分值</label>
                        <input v-model="form.score" type="number" min="0" step="0.5" class="input-base" />
                      </div>
                    </div>
                  </div>
                </div>

                <!-- Footer -->
                <div class="px-6 py-4 border-t border-gray-100 bg-gray-50 md:flex md:flex-row-reverse md:gap-3">
                  <button 
                    @click="saveQuestion" 
                    :disabled="saving" 
                    class="btn-primary w-full md:w-auto min-w-[100px]"
                  >
                    <span v-if="saving" class="flex items-center gap-2">
                       <svg class="animate-spin h-4 w-4 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>
                       保存中...
                    </span>
                    <span v-else>保存</span>
                  </button>
                  <button 
                    @click="closeModal" 
                    class="btn-secondary w-full md:w-auto mt-3 md:mt-0 min-w-[80px]"
                  >
                    取消
                  </button>
                </div>
              </div>
            </Transition>
          </div>
        </div>
      </Transition>
    </Teleport>

    <!-- 主观题评分点管理弹窗 -->
    <Teleport to="body">
      <div v-if="rubricShow" class="fixed inset-0 z-[80] flex items-center justify-center bg-gray-600/70 p-4" @click.self="rubricShow = false">
        <div class="bg-white rounded-2xl shadow-2xl w-full max-w-2xl max-h-[85vh] flex flex-col">
          <div class="px-6 py-4 border-b border-gray-100 flex items-center justify-between">
            <div>
              <h3 class="text-lg font-bold text-gray-900">评分点设置</h3>
              <p class="text-xs text-gray-400 mt-0.5 truncate max-w-md">{{ rubricQuestion?.title }}</p>
            </div>
            <button class="text-gray-400 hover:text-gray-600 text-2xl leading-none" @click="rubricShow = false">×</button>
          </div>

          <div class="p-6 overflow-y-auto flex-1">
            <div class="space-y-3">
              <div v-for="rp in rubricPoints" :key="rp.id" class="flex items-start gap-3 bg-gray-50 rounded-lg p-3">
                <div class="flex-1">
                  <input v-model="rp.title" class="w-full rounded border-gray-300 text-sm font-medium" placeholder="评分点名称，如：ACID 四个特性">
                  <input v-model="rp.description" class="w-full mt-1.5 rounded border-gray-300 text-xs" placeholder="评分说明 / 参考答案要点">
                </div>
                <div class="flex items-center gap-1 pt-1">
                  <input v-model.number="rp.score" type="number" min="0" step="0.5" class="w-20 rounded border-gray-300 text-sm">
                  <span class="text-xs text-gray-400">分</span>
                </div>
                <button class="text-red-500 hover:text-red-700 text-sm pt-1.5" @click="removeRubric(rp)">删除</button>
              </div>
              <button class="w-full py-2 rounded-lg border border-dashed border-gray-300 text-sm text-gray-500 hover:border-indigo-400 hover:text-indigo-600" @click="addRubricRow">+ 添加评分点</button>
            </div>
            <div class="mt-3 text-sm text-gray-600">
              评分点满分合计：<span class="font-bold text-indigo-600">{{ rubricTotal }}</span> 分
              <span class="text-xs text-gray-400">（保存后将同步为题目分值）</span>
            </div>
          </div>

          <div class="px-6 py-4 border-t border-gray-100 flex justify-end gap-2">
            <button class="px-4 py-2 rounded-lg border border-gray-300 text-sm" @click="rubricShow = false">关闭</button>
            <button class="px-4 py-2 rounded-lg bg-indigo-600 text-white text-sm font-semibold hover:bg-indigo-500" @click="saveRubric">保存评分点</button>
          </div>
        </div>
      </div>
    </Teleport>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import api from '../../api'
import { useModal } from '../../composables/useModal'
import { useToast } from '../../composables/useToast'

const { confirm, alert } = useModal()
const toast = useToast()
const shouldUseGlobalErrorModal = (status) => status === 422 || status === 403 || status >= 500

const showModal = ref(false)
const isEditing = ref(false)
const editingId = ref(null)
const saving = ref(false)
const questions = ref([])
const categories = ref([])
const loading = ref(true)

const defaultForm = {
  category_id: '',
  type: 'single_choice',
  title: '',
  options: { A: '', B: '', C: '', D: '' },
  answer: '',
  analysis: '',
  difficulty: 1,
  score: 1
}

const form = ref({ ...defaultForm })

const typeNames = {
  single_choice: '单选题',
  multiple_choice: '多选题',
  true_false: '判断题',
  fill_blank: '填空题',
  essay: '问答题'
}

const getTypeName = (type) => typeNames[type] || type

const getAnswerPlaceholder = () => {
  if (form.value.type === 'single_choice') return '如：A'
  if (form.value.type === 'multiple_choice') return '如：ABD'
  if (form.value.type === 'true_false') return '如：true 或 false'
  return '请输入答案'
}

const fetchQuestions = async () => {
  try {
    const response = await api.get('/questions')
    questions.value = response.data.questions.data
  } catch (e) {
    console.error('Failed to fetch questions:', e)
  } finally {
    loading.value = false
  }
}

const fetchCategories = async () => {
  try {
    const response = await api.get('/questions/categories')
    categories.value = response.data.categories
  } catch (e) {
    console.error('Failed to fetch categories:', e)
  }
}

const openAddModal = () => {
  isEditing.value = false
  editingId.value = null
  form.value = { ...defaultForm, options: { A: '', B: '', C: '', D: '' } }
  showModal.value = true
}

const openEditModal = (question) => {
  isEditing.value = true
  editingId.value = question.id
  form.value = {
    category_id: question.category_id,
    type: question.type,
    title: question.title,
    options: question.options || { A: '', B: '', C: '', D: '' },
    answer: question.answer,
    analysis: question.analysis || '',
    difficulty: question.difficulty,
    score: question.score
  }
  showModal.value = true
}

const closeModal = () => {
  showModal.value = false
}

const saveQuestion = async () => {
  if (!form.value.category_id || !form.value.title || !form.value.answer) {
    alert('请填写必填字段：分类、题目内容、正确答案', '提示', 'warning')
    return
  }
  saving.value = true
  try {
    const data = { ...form.value }
    if (!['single_choice', 'multiple_choice'].includes(data.type)) {
      data.options = null
    }
    if (isEditing.value) {
      await api.put(`/questions/${editingId.value}`, data)
    } else {
      await api.post('/questions', data)
    }
    closeModal()
    await fetchQuestions()
  } catch (e) {
    console.error('Failed to save question:', e)
    if (!shouldUseGlobalErrorModal(e.response?.status)) {
      toast.error(e.response?.data?.error || '保存失败')
    }
  } finally {
    saving.value = false
  }
}

const deleteQuestion = async (question) => {
  const confirmed = await confirm(`确定要删除题目 "${question.title.substring(0, 20)}..." 吗？`, '删除确认')
  if (!confirmed) return
  try {
    await api.delete(`/questions/${question.id}`)
    await fetchQuestions()
  } catch (e) {
    console.error('Failed to delete question:', e)
  }
}

onMounted(() => {
  fetchQuestions()
  fetchCategories()
})

/* ---------- 主观题评分点管理 ---------- */
const rubricShow = ref(false)
const rubricQuestion = ref(null)
const rubricPoints = ref([])

const rubricTotal = computed(() =>
  Math.round(rubricPoints.value.reduce((sum, p) => sum + (Number(p.score) || 0), 0) * 100) / 100
)

const openRubric = async (question) => {
  rubricQuestion.value = question
  rubricPoints.value = []
  rubricShow.value = true
  try {
    const { data } = await api.get(`/questions/${question.id}/rubric`)
    rubricPoints.value = data.rubric_points.map(p => ({ ...p, _existing: true }))
  } catch (e) {
    console.error(e)
  }
}

const addRubricRow = () => {
  rubricPoints.value.push({ id: null, title: '', description: '', score: 0, sort_order: rubricPoints.value.length + 1, _existing: false })
}

const removeRubric = async (rp) => {
  if (rp._existing && rp.id) {
    const ok = await confirm('删除该评分点？', '删除确认')
    if (!ok) return
    try {
      await api.delete(`/questions/${rubricQuestion.value.id}/rubric/${rp.id}`)
      toast.success('评分点已删除')
    } catch (e) {
      console.error(e)
    }
  }
  rubricPoints.value = rubricPoints.value.filter(p => p !== rp)
}

const saveRubric = async () => {
  const rows = rubricPoints.value.filter(p => p.title.trim())
  if (rows.length === 0) {
    alert('请至少填写一个评分点', '提示', 'warning')
    return
  }
  try {
    for (const rp of rows) {
      const payload = { title: rp.title, description: rp.description, score: Number(rp.score) || 0, sort_order: rp.sort_order }
      if (rp._existing && rp.id) {
        await api.put(`/questions/${rubricQuestion.value.id}/rubric/${rp.id}`, payload)
      } else {
        await api.post(`/questions/${rubricQuestion.value.id}/rubric`, payload)
      }
    }
    toast.success('评分点已保存，题目分值已同步')
    rubricShow.value = false
    await fetchQuestions()
  } catch (e) {
    console.error('Failed to save rubric:', e)
  }
}
</script>
