<script setup lang="ts">
import { computed, ref } from 'vue';
import { Eye, EyeOff } from 'lucide-vue-next';

import { Input } from '@/components/ui/input';

interface Props {
  id: string;
  placeholder?: string;
  autocomplete?: string;
  required?: boolean;
  tabindex?: number | string;
  /** Show a strength meter beneath the field */
  showStrength?: boolean;
}

withDefaults(defineProps<Props>(), {
  placeholder: '',
  autocomplete: 'current-password',
  required: false,
  showStrength: false,
});

const model = defineModel<string>({ default: '' });
const visible = ref(false);

/* ----------------------------------------------------------------------------
 * Strength scoring — 0..4
 * Pure heuristic: length, mixed case, digits, symbols.
 * Replace with zxcvbn if you want serious entropy estimation.
 * ------------------------------------------------------------------------- */
const score = computed(() => {
  const v = model.value;
  if (!v) return 0;
  let s = 0;
  if (v.length >= 8) s++;
  if (v.length >= 12) s++;
  if (/[a-z]/.test(v) && /[A-Z]/.test(v)) s++;
  if (/\d/.test(v) && /[^A-Za-z0-9]/.test(v)) s++;
  return Math.min(4, s);
});

const strengthLabel = computed(() =>
  ['Too short', 'Weak', 'Fair', 'Good', 'Strong'][score.value],
);

const strengthColor = computed(() =>
  ['bg-muted', 'bg-rose-500', 'bg-amber-500', 'bg-sky-500', 'bg-emerald-500'][
    score.value
  ],
);

const strengthTextColor = computed(() =>
  [
    'text-muted-foreground',
    'text-rose-600 dark:text-rose-400',
    'text-amber-600 dark:text-amber-400',
    'text-sky-600 dark:text-sky-400',
    'text-emerald-600 dark:text-emerald-400',
  ][score.value],
);
</script>

<template>
  <div class="grid gap-2">
    <div class="relative">
      <Input
        :id="id"
        v-model="model"
        :type="visible ? 'text' : 'password'"
        :placeholder="placeholder"
        :autocomplete="autocomplete"
        :required="required"
        :tabindex="tabindex"
        class="pr-10"
      />
      <button
        type="button"
        :aria-label="visible ? 'Hide password' : 'Show password'"
        class="absolute right-2.5 top-1/2 -translate-y-1/2 rounded-md p-1 text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
        @click="visible = !visible"
      >
        <Eye v-if="!visible" class="size-4" />
        <EyeOff v-else class="size-4" />
      </button>
    </div>

    <div v-if="showStrength" class="mt-0.5 grid gap-1.5">
      <div class="flex gap-1">
        <div
          v-for="i in 4"
          :key="i"
          class="h-1 flex-1 rounded-full transition-colors"
          :class="i <= score ? strengthColor : 'bg-muted'"
        />
      </div>
      <p
        v-if="model"
        :class="['text-[11px] font-medium', strengthTextColor]"
      >
        {{ strengthLabel }}
      </p>
    </div>
  </div>
</template>
