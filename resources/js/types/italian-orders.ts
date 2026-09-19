export type OrderAddress = {
    name?: string;
    line1: string;
    line2?: string;
    postal_code: string;
    city: string;
    province: string;
    country: string;
};

export type ItalianOrderSummary = {
    is_test: boolean;
    id: number;
    number: string;
    customer_name: string;
    email: string;
    total_amount: number;
    currency: string;
    payment_status: string;
    fulfillment_status: string;
    created_at: string;
};

export type ItalianOrderDetail = ItalianOrderSummary & {
    refunds: Array<{ id: number; amount: number; status: string; created_at: string; stripe_refund_id: string | null }>;
    phone: string | null;
    shipping_address: OrderAddress;
    billing_address: OrderAddress | null;
    subtotal_amount: number;
    import_amount: number;
    shipping_amount: number;
    discount_amount: number;
    carrier: string | null;
    tracking_number: string | null;
    internal_notes: string | null;
    paid_at: string | null;
    shipped_at: string | null;
    delivered_at: string | null;
    version: number;
    items: Array<{
        id: number;
        product_handle: string;
        sku: string | null;
        title: string;
        variant_title: string | null;
        quantity: number;
        unit_amount: number;
        import_unit_amount: number;
        total_amount: number;
        import_total_amount: number;
    }>;
    events: Array<{
        id: number;
        created_at: string;
        kind: 'fulfillment' | 'payment';
        from_status: string;
        to_status: string;
        user: { name: string } | null;
        changes: Record<string, string | null>;
    }>;
};

export const orderMoney = (cents: number, currency = 'EUR') =>
    new Intl.NumberFormat('it-IT', { style: 'currency', currency }).format(cents / 100);

export const orderDate = (value: string | null) => value
    ? new Intl.DateTimeFormat('it-IT', { dateStyle: 'short', timeStyle: 'short', timeZone: 'Europe/Rome' }).format(new Date(value))
    : '—';
