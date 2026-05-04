<script setup lang="ts">
import { Button } from "@/components/ui/button";
import { Label } from "@/components/ui/label";
import { Loader2 } from "lucide-vue-next";

defineProps<{ open: boolean }>();

const emit = defineEmits<{
  "update:open": [value: boolean];
  created: [];
}>();

const form = useForm({
  name: "",
  slug: "",
});

const slugTouched = ref(false);

const autoSlug = computed(() =>
  form.name
    .toLowerCase()
    .trim()
    .replace(/[^a-z0-9\s-]/g, "")
    .replace(/\s+/g, "-")
    .replace(/-+/g, "-")
    .slice(0, 32)
);

watch(
  () => form.name,
  () => {
    if (!slugTouched.value) {
      form.slug = autoSlug.value;
    }
  }
);

const canSubmit = computed(() => {
  return form.name.trim().length >= 3 && form.slug.trim().length >= 3 && !form.processing;
});

function submit() {
  if (!canSubmit.value) return;

  form.post(route("workspace.store"), {
    preserveScroll: true,

    onSuccess: () => {
      emit("created");
      reset();
      emit("update:open", false);
    },

    onFinish: () => {
      // no need to manually set processing false
      // Inertia does that automatically
    },
  });
}

function reset() {
  form.reset();
  form.clearErrors();
  slugTouched.value = false;
}

function handleClose(value: boolean) {
  if (form.processing) return;

  if (!value) {
    reset();
  }

  emit("update:open", value);
}
</script>

<template>
  <AppModal
    :open="open"
    title="Create a workspace"
    description="Workspaces keep your team's projects, members, and settings separate."
    size="md"
    :close-on-overlay-click="!form.processing"
    @update:open="handleClose"
  >

    <form id="create-workspace-form" class="space-y-5 pt-2" @submit.prevent="submit">

      <AppFormInput
        id="workspace-name"
        v-model="form.name"
        label="Workspace name"
        placeholder="Acme Inc."
        hint="The name your team will see. You can change this later."
        :error="form.errors.name"
        required
        autofocus
        autocomplete="off"
      />

      <div class="grid gap-1.5">

        <Label for="workspace-slug" class="text-sm font-medium">
          Workspace URL
          <span class="text-red-500" aria-hidden="true">*</span>
        </Label>

        <div
          class="flex items-stretch rounded-md border border-input bg-background transition focus-within:border-ring focus-within:ring-2 focus-within:ring-ring/20"
          :class="
            form.errors.slug
              ? 'border-destructive focus-within:border-destructive focus-within:ring-destructive/20'
              : ''
          "
        >
          <span
            class="flex select-none items-center pl-3 pr-1 text-sm text-muted-foreground"
          >
            yourapp.com/
          </span>

          <input
            id="workspace-slug"
            v-model="form.slug"
            @input="slugTouched = true"
            class="flex-1 bg-transparent py-2 pr-3 text-sm text-foreground placeholder:text-muted-foreground focus:outline-none"
            placeholder="acme"
            autocomplete="off"
            spellcheck="false"
          />
        </div>

        <p v-if="form.errors.slug" class="text-sm text-destructive">
          {{ form.errors.slug }}
        </p>

        <p v-else class="text-xs text-muted-foreground">
          Lowercase letters, numbers, and dashes only.
        </p>
      </div>
    </form>

    <template #footer>
      <Button
        type="button"
        variant="outline"
        :disabled="form.processing"
        @click="handleClose(false)"
      >
        Cancel
      </Button>

      <Button type="submit" form="create-workspace-form" :disabled="!canSubmit">
        <Loader2 v-if="form.processing" class="mr-2 h-4 w-4 animate-spin" />
        {{ form.processing ? "Creating…" : "Create workspace" }}
      </Button>
      
    </template>
  </AppModal>
</template>
