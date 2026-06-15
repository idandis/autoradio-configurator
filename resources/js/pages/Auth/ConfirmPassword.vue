<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
const form = useForm({
    password: '',
});

const submit = () => {
    form.post('/confirm-password', {
        onFinish: () => form.reset('password'),
    });
};
</script>

<template>
    <Head title="Confirm password" />

    <div class="mb-6 space-y-2 text-center">
        <h1 class="text-xl font-medium">Confirm password</h1>
        <p class="text-sm text-muted-foreground">
            This is a secure area of the application. Please confirm your
            password before continuing.
        </p>
    </div>

    <form @submit.prevent="submit">
        <div class="space-y-6">
            <div class="grid gap-2">
                <Label for="password">Password</Label>
                <PasswordInput
                    id="password"
                    v-model="form.password"
                    class="mt-1 block w-full"
                    required
                    autocomplete="current-password"
                    autofocus
                />

                <InputError :message="form.errors.password" />
            </div>

            <div class="flex items-center">
                <Button
                    class="w-full"
                    :disabled="form.processing"
                    data-test="confirm-password-button"
                    type="submit"
                >
                    <Spinner v-if="form.processing" />
                    Confirm password
                </Button>
            </div>
        </div>
    </form>
</template>
