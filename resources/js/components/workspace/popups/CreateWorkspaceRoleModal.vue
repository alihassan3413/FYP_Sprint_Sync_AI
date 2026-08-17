<script setup lang="ts">
import { Loader2 } from 'lucide-vue-next';

defineProps<{ open: boolean }>();

const emit = defineEmits<{
    'update:open': [value: boolean];
    created: [slug: string];
}>();

const form = useForm({
    name: '',
    slug: '',
});

const slugTouched = ref(false);

const { workspaceRoute } = useCurrentWorkspace();

const autoSlug = computed(() =>
    form.name
        .toLowerCase()
        .trim()
        .replace(/[^a-z0-9\s-]/g, '')
        .replace(/\s+/g, '-')
        .replace(/-+/g, '-')
        .slice(0, 32),
);

const canSubmit = computed(() => {
    return form.name.trim().length >= 3 && form.slug.trim().length >= 3 && !form.processing;
});

function submit() {
    if (!canSubmit.value) return;

    const submittedSlug = form.slug.trim();

    form.post(workspaceRoute('workspace.roles.store'), {
        preserveScroll: true,

        onSuccess: () => {
            emit('created', submittedSlug);
            reset();
            emit('update:open', false);
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

    emit('update:open', value);
}

watch(
    () => form.name,
    () => {
        if (!slugTouched.value) {
            form.slug = autoSlug.value;
        }
    },
);
</script>

<template>
    <AppModal
        :open="open"
        title="Create custom Role"
        description="Custom roles can make it easier to label your teammates."
        size="md"
        @update:open="handleClose"
    >
        <form id="create-workspace-role-form" class="space-y-5 pt-2" @submit.prevent="submit">
            <AppFormInput
                id="workspace-role-name"
                v-model="form.name"
                label="Role Name"
                placeholder="e.g. Marketing Lead"
                hint="The role which you can assign to your team members."
                :error="form.errors.name"
                required
                autofocus
                autocomplete="off"
            />

            <AppFormInput
                id="workspace-role-slug"
                v-model="form.slug"
                label="Identifier / Slug"
                placeholder="e.g. marketing-lead"
                hint="Help you define same roles with different permissions."
                :error="form.errors.slug"
                @input="slugTouched = true"
                required
                autofocus
                autocomplete="off"
            />
        </form>

        <template #footer>
            <Button type="button" variant="outline" :disabled="form.processing" @click="handleClose(false)"> Cancel </Button>

            <Button type="submit" form="create-workspace-role-form" :disabled="!canSubmit">
                <Loader2 v-if="form.processing" class="mr-2 h-4 w-4 animate-spin" />
                {{ form.processing ? 'Creating…' : 'Create & configure' }}
            </Button>
        </template>
    </AppModal>
</template>
