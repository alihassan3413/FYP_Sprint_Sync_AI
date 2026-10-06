<script setup lang="ts">
import { CheckCircle2, Loader2, Mail, Pencil, Send, ShieldCheck } from 'lucide-vue-next';

import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';

type Settings = {
    host: string;
    port: number;
    encryption: string;
    username: string | null;
    from_email: string;
    from_name: string;
    reply_to_email: string | null;
};

const props = defineProps<{
    /** Never includes the password: only whether one is saved. */
    settings: Settings | null;
    hasPassword: boolean;
    verifiedAt: string | null;
    lastTestedAt: string | null;
    lastTestError: string | null;
    encryptionOptions: { value: string; label: string }[];
    allowedPorts: number[];
    testRecipient: string;
}>();

const { workspaceRoute } = useCurrentWorkspace();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Settings', href: workspaceRoute('workspace.settings') },
    { title: 'Email delivery', href: workspaceRoute('workspace.mail-settings.edit') },
];

const editing = ref(props.settings === null);

const form = useForm({
    host: props.settings?.host ?? '',
    port: String(props.settings?.port ?? 587),
    encryption: props.settings?.encryption ?? 'tls',
    username: props.settings?.username ?? '',
    password: '',
    from_email: props.settings?.from_email ?? '',
    from_name: props.settings?.from_name ?? '',
    reply_to_email: props.settings?.reply_to_email ?? '',
});

function save() {
    form.put(workspaceRoute('workspace.mail-settings.update'), {
        preserveScroll: true,
        onSuccess: () => {
            form.reset('password');
            editing.value = false;
        },
        onError: () => form.reset('password'),
    });
}

function cancelEditing() {
    form.reset();
    form.clearErrors();
    editing.value = false;
}

const testForm = useForm({ recipient: props.testRecipient });

function sendTest() {
    testForm.post(workspaceRoute('workspace.mail-settings.test'), { preserveScroll: true });
}

const formatDateTime = (iso: string) =>
    new Date(iso).toLocaleString(undefined, { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' });

const encryptionLabel = computed(() => props.encryptionOptions.find((option) => option.value === props.settings?.encryption)?.label);
</script>

<template>
    <Head title="Email delivery" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-6 p-4 md:p-6 lg:p-8">
            <AppPageHeader
                eyebrow="Settings"
                title="Email delivery"
                description="Invoices are emailed to your clients from your own email account, so replies come back to you."
            />

            <div class="grid items-start gap-6 lg:grid-cols-[minmax(0,1fr)_340px]">
                <!-- Connected summary -->
                <section v-if="settings && !editing" class="bg-card space-y-5 rounded-3xl border p-5 sm:p-6" data-testid="mail-summary">
                    <div class="flex items-start gap-3">
                        <span class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-emerald-500/10 text-emerald-600">
                            <Mail class="size-5" />
                        </span>
                        <div class="min-w-0 flex-1">
                            <p class="font-semibold">{{ verifiedAt ? 'Connected' : 'Saved · send a test email to check it' }}</p>
                            <p class="text-muted-foreground truncate text-sm">
                                Sending as {{ settings.from_name }} &lt;{{ settings.from_email }}&gt;
                            </p>
                        </div>
                        <Button variant="outline" size="sm" class="gap-1.5" data-testid="edit-mail" @click="editing = true">
                            <Pencil class="size-3.5" />
                            Edit
                        </Button>
                    </div>

                    <dl class="grid gap-3 border-t pt-5 text-sm sm:grid-cols-2">
                        <div>
                            <dt class="text-muted-foreground">Server</dt>
                            <dd class="font-medium break-all">{{ settings.host }}:{{ settings.port }}</dd>
                        </div>
                        <div>
                            <dt class="text-muted-foreground">Security</dt>
                            <dd class="font-medium">{{ encryptionLabel }}</dd>
                        </div>
                        <div>
                            <dt class="text-muted-foreground">Username</dt>
                            <dd class="font-medium break-all">{{ settings.username || 'None' }}</dd>
                        </div>
                        <div>
                            <dt class="text-muted-foreground">Password</dt>
                            <dd class="font-medium">{{ hasPassword ? 'Saved' : 'None' }}</dd>
                        </div>
                        <div v-if="settings.reply_to_email" class="sm:col-span-2">
                            <dt class="text-muted-foreground">Replies go to</dt>
                            <dd class="font-medium break-all">{{ settings.reply_to_email }}</dd>
                        </div>
                    </dl>
                </section>

                <!-- Form -->
                <form v-else class="bg-card space-y-8 rounded-3xl border p-5 sm:p-6" data-testid="mail-form" @submit.prevent="save">
                    <section class="space-y-4">
                        <div>
                            <h2 class="text-sm font-semibold">Your email server</h2>
                            <p class="text-muted-foreground mt-1 text-xs">
                                Your email provider lists these as "SMTP settings". For Google Workspace or Microsoft 365, use an app password.
                            </p>
                        </div>
                        <div class="grid gap-4 sm:grid-cols-2">
                            <AppFormInput
                                id="host"
                                v-model="form.host"
                                class="sm:col-span-2"
                                label="Server address"
                                placeholder="smtp.example.com"
                                :error="form.errors.host"
                                required
                            />
                            <div class="grid gap-1.5">
                                <Label for="port" class="text-sm font-medium">Port <span class="text-red-500" aria-hidden="true">*</span></Label>
                                <select
                                    id="port"
                                    v-model="form.port"
                                    class="border-input bg-background h-10 rounded-md border px-3 text-sm"
                                    data-testid="port"
                                >
                                    <option v-for="port in allowedPorts" :key="port" :value="String(port)">{{ port }}</option>
                                </select>
                                <p class="text-muted-foreground text-xs">Usually 587 with TLS, or 465 with SSL.</p>
                                <InputError :message="form.errors.port" />
                            </div>
                            <div class="grid gap-1.5">
                                <Label for="encryption" class="text-sm font-medium"
                                    >Security <span class="text-red-500" aria-hidden="true">*</span></Label
                                >
                                <select
                                    id="encryption"
                                    v-model="form.encryption"
                                    class="border-input bg-background h-10 rounded-md border px-3 text-sm"
                                    data-testid="encryption"
                                >
                                    <option v-for="option in encryptionOptions" :key="option.value" :value="option.value">{{ option.label }}</option>
                                </select>
                                <p class="text-muted-foreground text-xs">Choose None only if your provider tells you to.</p>
                                <InputError :message="form.errors.encryption" />
                            </div>
                            <AppFormInput
                                id="username"
                                v-model="form.username"
                                label="Username"
                                hint="Usually your full email address"
                                autocomplete="off"
                                :error="form.errors.username"
                            />
                            <div class="grid gap-1.5">
                                <AppFormInput
                                    id="password"
                                    v-model="form.password"
                                    type="password"
                                    label="Password"
                                    autocomplete="new-password"
                                    :error="form.errors.password"
                                />
                                <p v-if="hasPassword" class="text-muted-foreground text-xs">Leave blank to keep the current password.</p>
                            </div>
                        </div>
                    </section>

                    <section class="space-y-4">
                        <div>
                            <h2 class="text-sm font-semibold">Who invoices come from</h2>
                            <p class="text-muted-foreground mt-1 text-xs">This is what your clients see in their inbox.</p>
                        </div>
                        <div class="grid gap-4 sm:grid-cols-2">
                            <AppFormInput
                                id="from_email"
                                v-model="form.from_email"
                                type="email"
                                label="From email"
                                placeholder="billing@yourcompany.com"
                                :error="form.errors.from_email"
                                required
                            />
                            <AppFormInput id="from_name" v-model="form.from_name" label="From name" :error="form.errors.from_name" required />
                            <AppFormInput
                                id="reply_to_email"
                                v-model="form.reply_to_email"
                                class="sm:col-span-2"
                                type="email"
                                label="Reply-to email"
                                hint="Optional · where client replies should go, if not the from email"
                                :error="form.errors.reply_to_email"
                            />
                        </div>
                    </section>

                    <div class="flex items-center justify-end gap-3 border-t pt-5">
                        <Button v-if="settings" type="button" variant="ghost" @click="cancelEditing">Cancel</Button>
                        <Button type="submit" :disabled="form.processing" data-testid="save-mail">
                            <Loader2 v-if="form.processing" class="mr-2 size-4 animate-spin" />
                            Save
                        </Button>
                    </div>
                </form>

                <aside class="space-y-6">
                    <section class="bg-card rounded-3xl border p-5 sm:p-6" data-testid="mail-test">
                        <h2 class="text-sm font-semibold">Send a test email</h2>
                        <p class="text-muted-foreground mt-1 text-xs">Uses your saved settings. Nothing is sent to clients.</p>

                        <form class="mt-4 space-y-3" @submit.prevent="sendTest">
                            <AppFormInput
                                id="recipient"
                                v-model="testForm.recipient"
                                type="email"
                                label="Send to"
                                :error="testForm.errors.recipient"
                            />
                            <Button
                                type="submit"
                                variant="outline"
                                class="w-full gap-1.5"
                                :disabled="!settings || testForm.processing"
                                data-testid="send-test"
                            >
                                <Loader2 v-if="testForm.processing" class="size-4 animate-spin" />
                                <Send v-else class="size-4" />
                                Send test email
                            </Button>
                        </form>
                        <p v-if="!settings" class="text-muted-foreground mt-2 text-xs">Save your settings first.</p>

                        <p v-if="verifiedAt" class="mt-4 flex items-center gap-1.5 text-xs text-emerald-600" data-testid="mail-verified">
                            <CheckCircle2 class="size-3.5" />
                            Last test worked · {{ formatDateTime(verifiedAt) }}
                        </p>
                        <p v-else-if="lastTestError" class="text-destructive mt-4 text-xs" data-testid="mail-test-error">
                            Last test failed: {{ lastTestError }}
                        </p>
                    </section>

                    <section class="bg-card rounded-3xl border p-5 sm:p-6">
                        <h2 class="flex items-center gap-1.5 text-sm font-semibold">
                            <ShieldCheck class="size-4 text-emerald-600" />
                            Your password is safe
                        </h2>
                        <p class="text-muted-foreground mt-1 text-xs">
                            It is stored encrypted, only used to send your emails, and never shown again after you save it.
                        </p>
                    </section>
                </aside>
            </div>
        </div>
    </AppLayout>
</template>
