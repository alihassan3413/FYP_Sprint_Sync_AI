<script setup lang="ts">
import { timezoneOptions } from '@/lib/timezones';
import { Globe, Loader2 } from 'lucide-vue-next';

interface WorkspaceProfile {
    id: number;
    name: string;
    slug: string;
    timezone: string;
}

const props = defineProps<{
    open: boolean;
    workspace: WorkspaceProfile;
}>();

const emit = defineEmits<{
    'update:open': [value: boolean];
}>();

const { workspaceRoute } = useCurrentWorkspace();

const form = useForm({
    name: props.workspace.name,
    slug: props.workspace.slug,
    timezone: props.workspace.timezone,
});

const timezoneChoices = timezoneOptions();

const slugTouched = ref(true);

const autoSlug = computed(() =>
    form.name
        .toLowerCase()
        .trim()
        .replace(/[^a-z0-9\s-]/g, '')
        .replace(/\s+/g, '-')
        .replace(/-+/g, '-')
        .slice(0, 60),
);

watch(
    () => props.open,
    (open) => {
        if (open) {
            form.name = props.workspace.name;
            form.slug = props.workspace.slug;
            form.timezone = props.workspace.timezone;
            slugTouched.value = true;
        }
    },
);

watch(
    () => form.name,
    () => {
        if (!slugTouched.value) {
            form.slug = autoSlug.value;
        }
    },
);

const canSubmit = computed(() => form.name.trim().length >= 2 && form.slug.trim().length >= 2 && !form.processing);

function submit() {
    if (!canSubmit.value) return;

    form.put(workspaceRoute('workspace.update'), {
        preserveScroll: true,
        onSuccess: () => emit('update:open', false),
    });
}

function handleClose(value: boolean) {
    if (form.processing) return;

    if (!value) {
        form.clearErrors();
    }

    emit('update:open', value);
}
</script>

<template>
    <AppModal
        :open="open"
        title="Edit workspace"
        description="Update your workspace name, URL identifier and timezone."
        size="md"
        @update:open="handleClose"
    >
        <form id="rename-workspace-form" class="space-y-5 pt-2" @submit.prevent="submit">
            <AppFormInput
                id="rename-workspace-name"
                v-model="form.name"
                label="Workspace name"
                placeholder="e.g. Acme Inc."
                :error="form.errors.name"
                required
                autofocus
                autocomplete="off"
            />

            <AppFormInput
                id="rename-workspace-slug"
                v-model="form.slug"
                label="Identifier / Slug"
                placeholder="e.g. acme-inc"
                hint="Used in your workspace URL. Changing this will change your workspace links."
                :error="form.errors.slug"
                required
                autocomplete="off"
                @input="slugTouched = true"
            />

            <div class="grid gap-2">
                <Label for="rename-workspace-timezone">Timezone</Label>
                <select
                    id="rename-workspace-timezone"
                    v-model="form.timezone"
                    class="border-input bg-muted/40 focus:bg-background focus:ring-ring/40 h-9 rounded-lg border px-3 text-sm transition-colors focus:ring-2 focus:outline-none"
                    :aria-invalid="!!form.errors.timezone"
                    :class="form.errors.timezone && 'border-destructive focus-visible:ring-destructive'"
                >
                    <option v-for="option in timezoneChoices" :key="option.value" :value="option.value">
                        {{ option.label }}
                    </option>
                </select>
                <p class="text-muted-foreground flex items-center gap-1.5 text-xs">
                    <Globe class="size-3.5 shrink-0" />
                    <span>Invoice dates, reminders and scheduled sending follow this timezone.</span>
                </p>
                <InputError :message="form.errors.timezone" />
            </div>
        </form>

        <template #footer>
            <Button type="button" variant="outline" :disabled="form.processing" @click="handleClose(false)"> Cancel </Button>
            <Button type="submit" form="rename-workspace-form" :disabled="!canSubmit">
                <Loader2 v-if="form.processing" class="mr-2 h-4 w-4 animate-spin" />
                {{ form.processing ? 'Saving…' : 'Save changes' }}
            </Button>
        </template>
    </AppModal>
</template>
