<script setup lang="ts">
import { Link2, Loader2 } from 'lucide-vue-next';

import type { Department, LinkableUser, Person } from '@/lib/people';

const props = defineProps<{
    open: boolean;
    departments: Department[];
    linkableUsers: LinkableUser[];
    /** Present when editing; absent when adding someone new. */
    person?: Person | null;
    /** Starting values when adding a profile for someone who already has SprintSync access. */
    prefill?: { name: string; email: string; user_id: number } | null;
}>();

const emit = defineEmits<{
    'update:open': [value: boolean];
}>();

const { workspaceRoute } = useCurrentWorkspace();

const isEditing = computed(() => !!props.person);

const form = useForm<{ name: string; email: string; title: string; department: string; user_id: number | null }>({
    name: '',
    email: '',
    title: '',
    department: '',
    user_id: null,
});

function fill() {
    const person = props.person;

    form.defaults({
        name: person?.name ?? props.prefill?.name ?? '',
        email: person?.email ?? props.prefill?.email ?? '',
        title: person?.title ?? '',
        department: person?.department?.name ?? '',
        user_id: person?.linked_user?.id ?? props.prefill?.user_id ?? null,
    });
    form.reset();
    form.clearErrors();
}

watch(
    () => props.open,
    (open) => open && fill(),
    { immediate: true },
);

/** Members linked to someone other than this person cannot be picked. */
function takenByOther(user: LinkableUser): boolean {
    return user.linked_person !== null && user.linked_person !== props.person?.public_id;
}

/** A free member with this email; the server connects them when the team member is added. */
const suggestedUser = computed(() => {
    const email = form.email.trim().toLowerCase();

    if (!email || form.user_id !== null) return null;

    return props.linkableUsers.find((user) => user.email.toLowerCase() === email && !takenByOther(user)) ?? null;
});

const linkedUser = computed(() => props.linkableUsers.find((user) => user.id === form.user_id) ?? null);

const isNewDepartment = computed(() => {
    const name = form.department.trim().toLowerCase();

    return name !== '' && !props.departments.some((department) => department.name.toLowerCase() === name);
});

const canSubmit = computed(() => form.name.trim().length >= 2 && !form.processing);

function submit() {
    if (!canSubmit.value) return;

    const options = { preserveScroll: true, onSuccess: () => emit('update:open', false) };

    if (props.person) {
        form.put(workspaceRoute('workspace.people.update', { person: props.person.public_id }), options);
    } else {
        form.post(workspaceRoute('workspace.people.store'), options);
    }
}

function handleClose(value: boolean) {
    if (form.processing) return;

    emit('update:open', value);
}
</script>

<template>
    <AppModal
        :open="open"
        :title="isEditing ? 'Edit team member' : 'Add team member'"
        :description="isEditing ? undefined : 'Someone who works with your business. SprintSync access is optional and can be given afterwards.'"
        size="md"
        @update:open="handleClose"
    >
        <form id="person-form" class="space-y-5 pt-2" @submit.prevent="submit">
            <AppFormInput
                id="person-name"
                v-model="form.name"
                label="Name"
                placeholder="e.g. Ali Hassan"
                :error="form.errors.name"
                required
                autofocus
                autocomplete="off"
            />

            <div class="grid gap-1.5">
                <AppFormInput
                    id="person-email"
                    v-model="form.email"
                    type="email"
                    label="Email"
                    placeholder="Optional"
                    :error="form.errors.email"
                    autocomplete="off"
                />
                <p v-if="linkedUser" class="text-muted-foreground inline-flex items-center gap-1.5 text-xs" data-testid="linked-login">
                    <Link2 class="size-3.5" />
                    Connected to {{ linkedUser.name }}'s SprintSync login.
                </p>
                <p
                    v-else-if="suggestedUser && !person"
                    class="text-muted-foreground inline-flex items-center gap-1.5 text-xs"
                    data-testid="link-suggestion"
                >
                    <Link2 class="size-3.5" />
                    {{ suggestedUser.name }} already uses SprintSync with this email, so they'll be connected automatically.
                </p>
                <InputError :message="form.errors.user_id" />
            </div>

            <AppFormInput
                id="person-title"
                v-model="form.title"
                label="Job title"
                placeholder="e.g. Developer, Designer, CSR"
                :error="form.errors.title"
                autocomplete="off"
            />

            <div class="grid gap-1.5">
                <Label for="person-department" class="text-sm font-medium">Department</Label>
                <input
                    id="person-department"
                    v-model="form.department"
                    list="person-department-options"
                    class="border-input bg-background focus:ring-ring/40 h-10 rounded-md border px-3 text-sm focus:ring-2 focus:outline-none"
                    placeholder="Pick one, or type a new name"
                    autocomplete="off"
                    :aria-invalid="!!form.errors.department"
                />
                <datalist id="person-department-options">
                    <option v-for="department in departments" :key="department.public_id" :value="department.name" />
                </datalist>
                <p v-if="isNewDepartment" class="text-muted-foreground text-xs">
                    "{{ form.department.trim() }}" will be created as a new department.
                </p>
                <InputError :message="form.errors.department" />
            </div>
        </form>

        <template #footer>
            <Button type="button" variant="outline" :disabled="form.processing" @click="handleClose(false)"> Cancel </Button>
            <Button type="submit" form="person-form" :disabled="!canSubmit">
                <Loader2 v-if="form.processing" class="mr-2 h-4 w-4 animate-spin" />
                {{ form.processing ? 'Saving…' : isEditing ? 'Save changes' : 'Add team member' }}
            </Button>
        </template>
    </AppModal>
</template>
