<script setup lang="ts">
import { Plus, UserRound, X } from 'lucide-vue-next';

export interface ParticipantOption {
    id: number;
    name: string;
    email: string;
}

const props = defineProps<{
    options: ParticipantOption[];
    userIdsError?: string;
    emailsError?: string;
}>();

const selectedUserIds = defineModel<number[]>('userIds', { required: true });
const emails = defineModel<string[]>('emails', { required: true });

const draft = ref('');
const draftError = ref<string | null>(null);

const takenEmails = computed(() => {
    const chosen = props.options.filter((option) => selectedUserIds.value.includes(option.id)).map((option) => option.email.toLowerCase());

    return [...chosen, ...emails.value.map((email) => email.toLowerCase())];
});

function toggleUser(id: number) {
    selectedUserIds.value = selectedUserIds.value.includes(id)
        ? selectedUserIds.value.filter((selected) => selected !== id)
        : [...selectedUserIds.value, id];
}

function addEmail() {
    const value = draft.value.trim().toLowerCase();
    draftError.value = null;

    if (value === '') return;

    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value)) {
        draftError.value = 'Enter a valid email address.';

        return;
    }

    if (takenEmails.value.includes(value)) {
        draftError.value = 'That person is already on the list.';

        return;
    }

    emails.value = [...emails.value, value];
    draft.value = '';
}

function removeEmail(email: string) {
    emails.value = emails.value.filter((existing) => existing !== email);
}
</script>

<template>
    <div class="grid gap-3">
        <Label>Participants</Label>

        <div v-if="options.length > 0" class="border-border/70 max-h-40 divide-y overflow-y-auto rounded-lg border">
            <button
                v-for="option in options"
                :key="option.id"
                type="button"
                class="hover:bg-muted/40 flex w-full items-center gap-2.5 px-3 py-2 text-left transition-colors"
                @click="toggleUser(option.id)"
            >
                <span
                    :class="[
                        'flex size-4 shrink-0 items-center justify-center rounded border',
                        selectedUserIds.includes(option.id) ? 'bg-foreground border-foreground text-background' : 'border-border',
                    ]"
                >
                    <UserRound v-if="selectedUserIds.includes(option.id)" class="size-2.5" />
                </span>

                <span class="min-w-0 flex-1">
                    <span class="text-foreground block truncate text-[13px] font-medium">{{ option.name }}</span>
                    <span class="text-muted-foreground block truncate text-[11px]">{{ option.email }}</span>
                </span>
            </button>
        </div>

        <InputError :message="userIdsError" />

        <div class="flex gap-2">
            <Input v-model="draft" type="email" placeholder="Invite someone outside the project" @keydown.enter.prevent="addEmail" />

            <Button type="button" variant="outline" size="sm" class="shrink-0 gap-1.5" @click="addEmail">
                <Plus class="size-3.5" />
                Add
            </Button>
        </div>

        <p v-if="draftError" class="text-destructive text-xs">{{ draftError }}</p>
        <InputError :message="emailsError" />

        <div v-if="emails.length > 0" class="flex flex-wrap gap-1.5">
            <span
                v-for="email in emails"
                :key="email"
                class="border-border bg-muted/40 inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-[11px]"
            >
                {{ email }}
                <button type="button" class="text-muted-foreground hover:text-destructive" @click="removeEmail(email)">
                    <X class="size-3" />
                </button>
            </span>
        </div>
    </div>
</template>
