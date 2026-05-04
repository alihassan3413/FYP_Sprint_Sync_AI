<script setup lang="ts">
interface Props {
  id: string
  label: string
  hint?: string
  error?: string
  type?: string
  placeholder?: string
  required?: boolean
  autofocus?: boolean
  autocomplete?: string
  tabindex?: number | string
}

withDefaults(defineProps<Props>(), {
  type: 'text',
  required: false,
  autofocus: false,
})

const model = defineModel<string>({ default: '' })
</script>

<template>
  <div class="grid gap-1.5">
    <div class="flex items-center justify-between">
      <Label :for="id" class="text-sm font-medium">
        {{ label }}
        <span v-if="required" class="text-red-500" aria-hidden="true">*</span>
      </Label>
    </div>

    <Input
      :id="id"
      v-model="model"
      :type="type"
      :required="required"
      :autofocus="autofocus"
      :autocomplete="autocomplete"
      :tabindex="tabindex"
      :placeholder="placeholder"
      :aria-invalid="!!error"
      :aria-describedby="error ? `${id}-error` : hint ? `${id}-hint` : undefined"
      :class="error && 'border-red-500 focus-visible:ring-red-500'"
    />

    <p v-if="hint && !error" :id="`${id}-hint`" class="text-xs text-gray-500">
      {{ hint }}
    </p>

    <InputError v-if="error" :id="`${id}-error`" :message="error" />
  </div>
</template>
