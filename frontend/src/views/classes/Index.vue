<template>
  <div class="space-y-6">
    <div class="flex items-center justify-between flex-wrap gap-3">
      <div>
        <h1 class="text-2xl font-bold text-gray-900">班级管理</h1>
        <p class="text-sm text-gray-500 mt-1">维护班级与学生归属，批阅进度按班级统计。</p>
      </div>
      <button class="px-4 py-2 rounded-lg bg-indigo-600 text-white text-sm font-semibold hover:bg-indigo-500" @click="openCreate">新建班级</button>
    </div>

    <div v-if="loading" class="text-center py-8 text-gray-400 text-sm">加载中…</div>
    <div v-else class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
      <div v-for="c in classes" :key="c.id" class="bg-white rounded-xl shadow-sm border border-gray-100 p-5">
        <div class="flex items-start justify-between">
          <div>
            <h3 class="font-bold text-gray-900">{{ c.name }}</h3>
            <p class="text-xs text-gray-400 mt-1">{{ c.description || '暂无描述' }}</p>
          </div>
          <span class="text-xs bg-indigo-50 text-indigo-700 rounded-full px-2 py-0.5">{{ c.student_count }} 人</span>
        </div>
        <div class="mt-4 flex gap-2">
          <button class="flex-1 text-sm px-3 py-1.5 rounded-lg border border-gray-200 text-gray-700 hover:bg-gray-50" @click="openAssign(c)">分配学生</button>
          <button class="text-sm px-3 py-1.5 rounded-lg border border-gray-200 text-gray-700 hover:bg-gray-50" @click="openEdit(c)">编辑</button>
          <button class="text-sm px-3 py-1.5 rounded-lg border border-red-200 text-red-600 hover:bg-red-50" @click="remove(c)">删除</button>
        </div>
      </div>
    </div>

    <!-- 创建/编辑弹窗 -->
    <div v-if="formShow" class="fixed inset-0 z-[80] flex items-center justify-center bg-gray-600/60 p-4" @click.self="formShow = false">
      <div class="bg-white rounded-2xl shadow-xl w-full max-w-md p-6">
        <h3 class="text-lg font-bold text-gray-900">{{ editId ? '编辑班级' : '新建班级' }}</h3>
        <div class="mt-4 space-y-3">
          <div>
            <label class="text-sm font-medium text-gray-700">班级名称</label>
            <input v-model="form.name" class="mt-1 w-full rounded-lg border-gray-300 text-sm" />
          </div>
          <div>
            <label class="text-sm font-medium text-gray-700">描述</label>
            <input v-model="form.description" class="mt-1 w-full rounded-lg border-gray-300 text-sm" />
          </div>
        </div>
        <div class="mt-5 flex justify-end gap-2">
          <button class="px-4 py-2 rounded-lg border border-gray-300 text-sm" @click="formShow = false">取消</button>
          <button class="px-4 py-2 rounded-lg bg-indigo-600 text-white text-sm font-semibold" @click="saveForm">保存</button>
        </div>
      </div>
    </div>

    <!-- 分配学生弹窗 -->
    <div v-if="assignShow" class="fixed inset-0 z-[80] flex items-center justify-center bg-gray-600/60 p-4" @click.self="assignShow = false">
      <div class="bg-white rounded-2xl shadow-xl w-full max-w-lg p-6">
        <h3 class="text-lg font-bold text-gray-900">分配学生 — {{ assignTarget?.name }}</h3>
        <p class="text-xs text-gray-400 mt-1">勾选后保存，将全量覆盖该班学生名单。</p>
        <div class="mt-4 max-h-80 overflow-y-auto divide-y divide-gray-100 border rounded-lg">
          <label v-for="s in allStudents" :key="s.id" class="flex items-center gap-3 p-3 hover:bg-gray-50 cursor-pointer">
            <input type="checkbox" :value="s.id" v-model="selectedStudents" class="rounded border-gray-300 text-indigo-600">
            <div class="text-sm">
              <span class="font-medium text-gray-800">{{ s.real_name || s.username }}</span>
              <span class="text-gray-400 ml-2">{{ s.email }}</span>
            </div>
          </label>
          <div v-if="allStudents.length === 0" class="p-6 text-center text-sm text-gray-400">暂无可分配学生</div>
        </div>
        <div class="mt-5 flex justify-end gap-2">
          <button class="px-4 py-2 rounded-lg border border-gray-300 text-sm" @click="assignShow = false">取消</button>
          <button class="px-4 py-2 rounded-lg bg-indigo-600 text-white text-sm font-semibold" @click="saveAssign">保存分配</button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import api from '../../api'
import { useToast } from '../../composables/useToast'

const { success, error } = useToast()
const loading = ref(true)
const classes = ref([])
const allStudents = ref([])

const formShow = ref(false)
const editId = ref(null)
const form = ref({ name: '', description: '' })

const assignShow = ref(false)
const assignTarget = ref(null)
const selectedStudents = ref([])

const load = async () => {
  loading.value = true
  try {
    const { data } = await api.get('/classes')
    classes.value = data.classes
  } finally {
    loading.value = false
  }
}

const openCreate = () => { editId.value = null; form.value = { name: '', description: '' }; formShow.value = true }
const openEdit = (c) => { editId.value = c.id; form.value = { name: c.name, description: c.description || '' }; formShow.value = true }

const saveForm = async () => {
  if (!form.value.name.trim()) return error('班级名称不能为空')
  if (editId.value) {
    await api.put(`/classes/${editId.value}`, form.value)
    success('班级已更新')
  } else {
    await api.post('/classes', form.value)
    success('班级已创建')
  }
  formShow.value = false
  await load()
}

const remove = async (c) => {
  if (!window.confirm(`确定删除班级「${c.name}」？学生不会被删除，仅解除归属。`)) return
  await api.delete(`/classes/${c.id}`)
  success('班级已删除')
  await load()
}

const openAssign = async (c) => {
  assignTarget.value = c
  const [{ data: students }, { data: members }] = await Promise.all([
    api.get('/classes/options/students'),
    api.get(`/classes/${c.id}/students`),
  ])
  allStudents.value = students.students
  selectedStudents.value = members.students.map(s => s.id)
  assignShow.value = true
}

const saveAssign = async () => {
  await api.put(`/classes/${assignTarget.value.id}/students`, { student_ids: selectedStudents.value })
  success('学生分配已更新')
  assignShow.value = false
  await load()
}

onMounted(load)
</script>
