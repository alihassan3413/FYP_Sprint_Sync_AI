<script setup lang="ts">
import { Check, Loader2 } from 'lucide-vue-next';

import type { WorkspaceRoleOption } from '@/lib/members';

const props = withDefaults(
    defineProps<{
        open: boolean;
        /** The profile getting access; null closes the modal. */
        person: { public_id: string; name: string; email: string | null } | null;
        workspaceRoles?: WorkspaceRoleOption[];
    }>(),
    { workspaceRoles: () => [] },
);

const emit = defineEmits<{
    'update:open': [value: boolean];
}>();

const { workspaceRoute } = useCurrentWorkspace();

const accessRoles = [
    { value: 'member', label: 'Member', description: 'Works on the projects they are added to.' },
    { value: 'admin', label: 'Admin', description: 'Also manages people, access and workspace settings.' },
] as const;

const form = useForm<{ email: string; role: 'member' | 'admin'; workspace_role_id: number | null }>({
    email: '',
    role: 'member',
    workspace_role_id: null,
});

watch(
    () => props.open,
    (open) => {
        if (!open) return;

        form.defaults({ email: props.person?.email ?? '', role: 'member', workspace_role_id: null });
        form.reset();
        form.clearErrors();
    },
    { immediate: true },
);

const canSubmit = computed(() => form.email.trim() !== '' && !form.processing);

function submit() {
    if (!props.person || !canSubmit.value) return;

    form.post(workspaceRoute('workspace.people.access.store', { person: props.person.public_id }), {
        preserveScroll: true,
        onSuccess: () => emit('update:open', false),
    });
}

function handleClose(value: boolean) {
    if (form.processing) return;

    emit('update:open', value);
}
</script>

<template>
    <AppModal
        :open="open"
        title="Give SprintSync access"
        :description="person ? `${person.name} gets an email invitation to sign in to this workspace.` : undefined"
        size="md"
        @update:open="handleClose"
    >
        <form id="give-access-form" class="space-y-5 pt-2" @submit.prevent="submit">
            <AppFormInput
                id="access-email"
                v-model="form.email"
                type="email"
                label="Email"
                placeholder="name@company.com"
                :error="form.errors.email"
                required
                autocomplete="off"
            />

            <fieldset class="grid gap-2">
                <legend class="mb-1.5 text-sm font-medium">Access role</legend>
                <button
                    v-for="option in accessRoles"
                    :key="option.value"
                    type="button"
                    class="flex items-start gap-3 rounded-lg border px-3 py-2.5 text-left transition-colors"
                    :class="form.role === option.value ? 'border-primary bg-primary/5' : 'hover:bg-muted/50'"
                    :aria-pressed="form.role === option.value"
                    @click="form.role = option.value"
                >
                    <span
                        class="mt-0.5 flex size-4 shrink-0 items-center justify-center rounded-full border"
                        :class="form.role === option.value && 'border-primary bg-primary text-primary-foreground'"
                    >
                        <Check v-if="form.role === option.value" class="size-3" />
                    </span>
                    <span>
                        <span class="block text-sm font-medium">{{ option.label }}</span>
                        <span class="text-muted-foreground block text-xs">{{ option.description }}</span>
                    </span>
                </button>
            </fieldset>

            <div v-if="workspaceRoles.length" class="grid gap-1.5">
                <Label for="access-custom-role" class="text-sm font-medium">
                    Custom access role <span class="text-muted-foreground font-normal">(optional)</span>
                </Label>
                <select
                    id="access-custom-role"
                    v-model="form.workspace_role_id"
                    class="border-input bg-background focus:ring-ring/40 h-10 rounded-md border px-3 text-sm focus:ring-2 focus:outline-none"
                >
                    <option :value="null">None</option>
                    <option v-for="role in workspaceRoles" :key="role.id" :value="role.id">{{ role.name }}</option>
                </select>
                <InputError :message="form.errors.workspace_role_id" />
            </div>
        </form>

        <template #footer>
            <Button type="button" variant="outline" :disabled="form.processing" @click="handleClose(false)"> Cancel </Button>
            <Button type="submit" form="give-access-form" :disabled="!canSubmit">
                <Loader2 v-if="form.processing" class="mr-2 h-4 w-4 animate-spin" />
                {{ form.processing ? 'Sending…' : 'Send invitation' }}
            </Button>
        </template>
    </AppModal>
</template>
