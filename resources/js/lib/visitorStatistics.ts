export const statisticSessionUuid = () => {
    try {
        const storageKey = 'autoradiocanario-statistics-session';
        const existing = window.sessionStorage.getItem(storageKey);
        if (existing) return existing;

        const uuid = window.crypto?.randomUUID?.() ?? 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (character) => {
            const random = Math.floor(Math.random() * 16);
            const value = character === 'x' ? random : (random & 0x3) | 0x8;
            return value.toString(16);
        });
        window.sessionStorage.setItem(storageKey, uuid);
        return uuid;
    } catch {
        return null;
    }
};

export const statisticReferrer = () => {
    if (!document.referrer) return null;

    try {
        const referrer = new URL(document.referrer);
        return `${referrer.origin}${referrer.pathname}`.slice(0, 2048);
    } catch {
        return null;
    }
};

export const trackVisitorEntry = async (locale: string) => {
    try {
        const csrfToken = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content;
        const searchParams = new URLSearchParams(window.location.search);
        await fetch('/configurator/statistics', {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                ...(csrfToken ? { 'X-CSRF-TOKEN': csrfToken } : {}),
            },
            credentials: 'same-origin',
            keepalive: true,
            body: JSON.stringify({
                session_uuid: statisticSessionUuid(),
                event_type: 'configurator_entered',
                installation_selected: false,
                camera_selected: false,
                language: locale,
                referrer: statisticReferrer(),
                utm_source: searchParams.get('utm_source'),
                utm_campaign: searchParams.get('utm_campaign'),
                device_type: window.innerWidth < 768 ? 'mobile' : window.innerWidth < 1024 ? 'tablet' : 'desktop',
            }),
        });
    } catch {
        // Visitor analytics are best-effort and must never affect the configurator.
    }
};
