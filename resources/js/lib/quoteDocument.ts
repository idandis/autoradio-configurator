import template from '../../quote/document.html?raw';

export const renderQuoteDocument = (values: Record<string, string>) =>
    template.replace(/\{\{([a-z_]+)\}\}/g, (_, key: string) => values[key] ?? '');
