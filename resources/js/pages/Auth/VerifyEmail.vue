<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { logout } from '@/routes';

defineProps<{
    status?: string;
}>();

const form = useForm({});

const submit = () => {
    form.post('/email/verification-notification');
};
</script>

<template>
    <Head title="Email verification" />

    <div
        v-if="status === 'verification-link-sent'"
        class="mb-4 text-center text-sm font-medium text-green-600"
    >
        A new verification link has been sent to the email address you provided
        during registration.
    </div>

    <div class="mb-6 space-y-2 text-center">
        <h1 class="text-xl font-medium">Email verification</h1>
        <p class="text-sm text-muted-foreground">
            Please verify your email address by clicking on the link we just
            emailed to you.
        </p>
    </div>

    <form @submit.prevent="submit" class="space-y-6 text-center">
        <Button :disabled="form.processing" variant="secondary" type="submit">
            <Spinner v-if="form.processing" />
            Resend verification email
        </Button>

        <Link
            :href="logout()"
            as="button"
            method="post"
            class="mx-auto block text-sm text-muted-foreground underline underline-offset-4"
        >
            Log out
        </Link>
    </form>
</template>
