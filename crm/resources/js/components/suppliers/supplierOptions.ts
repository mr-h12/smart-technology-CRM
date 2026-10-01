import type { Supplier } from '@/services/suppliers';

/**
 * What the three supplier comboboxes share (F-24 · 1.4, 1.2): `SupplierPicker`'s
 * many suppliers on a catalog item, the offer form's one, and the offers list's
 * filter. All speak the same `suppliers.picker.*` sentences and describe an
 * option the same way.
 */
export const SUPPLIER_KEYS = {
    more: 'suppliers.picker.more',
    forbidden: 'suppliers.picker.forbidden',
    failed: 'suppliers.picker.failed',
    empty: 'suppliers.picker.empty',
    noMatch: 'suppliers.picker.noMatch',
    retry: 'state.retry',
};

/**
 * Contact and phone when present, then the inactive word — what tells two
 * same-named suppliers apart, and Design System §6.3's warning on a
 * deactivated one. Each part sits in its own isolate (U+2068 … U+2069):
 * joined bare, a phone after an Arabic contact drew before it in English
 * (UAX #9 W2, N1; `rtl-ui-verifier`, 2026-09-28).
 */
export function supplierDetail(supplier: Supplier, t: (key: string) => string): string {
    return [supplier.contact_person, supplier.phone, supplier.is_active ? null : t('suppliers.status.inactive')]
        .filter((part): part is string => typeof part === 'string' && part !== '')
        .map((part) => `\u2068${part}\u2069`)
        .join(t('suppliers.picker.detailSeparator'));
}
