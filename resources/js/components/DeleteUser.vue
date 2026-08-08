<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { TriangleAlert } from 'lucide-vue-next';
import { ref } from 'vue';

import InputError from '@/components/InputError.vue';
import SettingsSection from '@/components/settings/SettingsSection.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

const passwordInput = ref<HTMLInputElement | null>(null);

const form = useForm({
    password: '',
});

const deleteUser = (event: Event) => {
    event.preventDefault();

    form.delete(route('profile.destroy'), {
        preserveScroll: true,
        onSuccess: () => closeModal(),
        onError: () => passwordInput.value?.focus(),
        onFinish: () => form.reset(),
    });
};

const closeModal = () => {
    form.clearErrors();
    form.reset();
};
</script>

<template>
    <SettingsSection
        tone="danger"
        :icon="TriangleAlert"
        title="Delete account"
        description="Permanently remove your account and everything attached to it."
    >
        <ul class="text-muted-foreground grid gap-2 text-[13px]">
            <li class="flex items-start gap-2">
                <span class="bg-destructive/60 mt-[7px] size-1 shrink-0 rounded-full" />
                Your profile, preferences and personal data are erased.
            </li>
            <li class="flex items-start gap-2">
                <span class="bg-destructive/60 mt-[7px] size-1 shrink-0 rounded-full" />
                You lose access to every workspace you belong to.
            </li>
            <li class="flex items-start gap-2">
                <span class="bg-destructive/60 mt-[7px] size-1 shrink-0 rounded-full" />
                This cannot be undone, and support cannot restore it.
            </li>
        </ul>

        <template #footer>
            <p class="text-muted-foreground text-[13px]">You will be asked to confirm with your password.</p>

            <Dialog>
                <DialogTrigger as-child>
                    <Button variant="destructive" size="sm">Delete account</Button>
                </DialogTrigger>

                <DialogContent>
                    <form class="space-y-6" @submit="deleteUser">
                        <DialogHeader class="space-y-3">
                            <DialogTitle>Delete your account?</DialogTitle>
                            <DialogDescription>
                                Everything tied to this account will be permanently deleted. Enter your password to confirm.
                            </DialogDescription>
                        </DialogHeader>

                        <div class="grid gap-2">
                            <Label for="delete-password" class="sr-only">Password</Label>
                            <Input
                                id="delete-password"
                                ref="passwordInput"
                                v-model="form.password"
                                type="password"
                                name="password"
                                autocomplete="current-password"
                                placeholder="Current password"
                            />
                            <InputError :message="form.errors.password" />
                        </div>

                        <DialogFooter>
                            <DialogClose as-child>
                                <Button type="button" variant="outline" @click="closeModal">Cancel</Button>
                            </DialogClose>

                            <Button type="submit" variant="destructive" :disabled="form.processing"> Permanently delete </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </template>
    </SettingsSection>
</template>
