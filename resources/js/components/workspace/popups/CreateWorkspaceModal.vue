<!-- components/CreateWorkspaceModal.vue -->
<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { Loader2, Briefcase } from 'lucide-vue-next'

defineProps<{ open: boolean }>()
const emit = defineEmits<{
  'update:open': [value: boolean]
  created: [workspace: { name: string; slug: string }]
}>()

const name = ref('')
const slug = ref('')
const slugTouched = ref(false)
const error = ref('')
const isSubmitting = ref(false)

// Auto-generate slug from name until user manually edits it
const autoSlug = computed(() =>
  name.value
    .toLowerCase()
    .trim()
    .replace(/[^a-z0-9\s-]/g, '')
    .replace(/\s+/g, '-')
    .slice(0, 32)
)

watch(name, () => {
  if (!slugTouched.value) slug.value = autoSlug.value
})

const canSubmit = computed(
  () => name.value.trim().length >= 3 && slug.value.length >= 3 && !isSubmitting.value
)

async function handleCreate() {
  if (!canSubmit.value) return
  error.value = ''
  isSubmitting.value = true
  try {
    // await api.createWorkspace({ name: name.value, slug: slug.value })
    emit('created', { name: name.value, slug: slug.value })
    reset()
    emit('update:open', false)
  } catch (e: any) {
    error.value = e?.message ?? 'Something went wrong. Please try again.'
  } finally {
    isSubmitting.value = false
  }
}

function reset() {
  name.value = ''
  slug.value = ''
  slugTouched.value = false
  error.value = ''
}

function handleClose(value: boolean) {
  if (isSubmitting.value) return  // don't close mid-submit
  if (!value) reset()
  emit('update:open', value)
}
</script>

<template>
  <AppModal
    :open="open"
    title="Create a workspace"
    description="Workspaces keep your team's projects, members, and billing separate."
    size="md"
    :close-on-overlay-click="!isSubmitting"
    @update:open="handleClose"
  >

    <form class="space-y-5 pt-2" @submit.prevent="handleCreate">
      <AppFormInput
        id="workspace-name"
        v-model="name"
        label="Workspace name"
        placeholder="Acme Inc."
        hint="The name your team will see. You can change this later."
        required
        autofocus
        autocomplete="off"
      />

      <div class="grid gap-1.5">
        <Label for="workspace-slug" class="text-sm font-medium">
          Workspace URL
          <span class="text-red-500" aria-hidden="true">*</span>
        </Label>
        <div class="flex items-stretch rounded-md border border-gray-300 bg-white focus-within:border-indigo-500 focus-within:ring-2 focus-within:ring-indigo-100 transition">
          <span class="flex items-center pl-3 pr-1 text-sm text-gray-500 select-none">
            yourapp.com/
          </span>
          <input
            id="workspace-slug"
            v-model="slug"
            @input="slugTouched = true"
            class="flex-1 bg-transparent py-2 pr-3 text-sm text-gray-900 placeholder:text-gray-400 focus:outline-none"
            placeholder="acme"
            autocomplete="off"
            spellcheck="false"
          />
        </div>
        <p class="text-xs text-gray-500">
          Lowercase letters, numbers, and dashes only.
        </p>
      </div>

      <div
        v-if="error"
        role="alert"
        class="rounded-md bg-red-50 px-3 py-2 text-sm text-red-700 ring-1 ring-red-100"
      >
        {{ error }}
      </div>
    </form>

    <template #footer>
      <Button
        variant="outline"
        :disabled="isSubmitting"
        @click="handleClose(false)"
      >
        Cancel
      </Button>
      <Button :disabled="!canSubmit" @click="handleCreate">
        <Loader2 v-if="isSubmitting" class="mr-2 h-4 w-4 animate-spin" />
        {{ isSubmitting ? 'Creating…' : 'Create workspace' }}
      </Button>
    </template>
  </AppModal>
</template>
