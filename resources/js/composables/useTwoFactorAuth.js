import { useHttp } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { qrCode, recoveryCodes, secretKey } from '@/routes/two-factor';
const errors = ref([]);
const manualSetupKey = ref(null);
const qrCodeSvg = ref(null);
const recoveryCodesList = ref([]);
const hasSetupData = computed(() => qrCodeSvg.value !== null && manualSetupKey.value !== null);
export const useTwoFactorAuth = () => {
    const http = useHttp();
    const fetchQrCode = async () => {
        try {
            const { svg } = (await http.submit(qrCode()));
            qrCodeSvg.value = svg;
        }
        catch {
            errors.value.push('Failed to fetch QR code');
            qrCodeSvg.value = null;
        }
    };
    const fetchSetupKey = async () => {
        try {
            const { secretKey: key } = (await http.submit(secretKey()));
            manualSetupKey.value = key;
        }
        catch {
            errors.value.push('Failed to fetch a setup key');
            manualSetupKey.value = null;
        }
    };
    const clearSetupData = () => {
        manualSetupKey.value = null;
        qrCodeSvg.value = null;
        clearErrors();
    };
    const clearErrors = () => {
        errors.value = [];
    };
    const clearTwoFactorAuthData = () => {
        clearSetupData();
        clearErrors();
        recoveryCodesList.value = [];
    };
    const fetchRecoveryCodes = async () => {
        try {
            clearErrors();
            recoveryCodesList.value = (await http.submit(recoveryCodes()));
        }
        catch {
            errors.value.push('Failed to fetch recovery codes');
            recoveryCodesList.value = [];
        }
    };
    const fetchSetupData = async () => {
        try {
            clearErrors();
            await Promise.all([fetchQrCode(), fetchSetupKey()]);
        }
        catch {
            qrCodeSvg.value = null;
            manualSetupKey.value = null;
        }
    };
    return {
        qrCodeSvg,
        manualSetupKey,
        recoveryCodesList,
        errors,
        hasSetupData,
        clearSetupData,
        clearErrors,
        clearTwoFactorAuthData,
        fetchQrCode,
        fetchSetupKey,
        fetchSetupData,
        fetchRecoveryCodes,
    };
};
