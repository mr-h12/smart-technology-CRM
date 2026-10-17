import { readdirSync, readFileSync } from 'node:fs';
import { describe, expect, it } from 'vitest';

/**
 * F-42's source scans, in one file (docs/ux-upgrade/plan.md §1): a rule read
 * from the source, because jsdom renders no layout. Each later row adds a case
 * here rather than a new scan file. Specs are skipped.
 */
const ROOT = 'resources/js';

const SOURCES: Array<[string, string]> = readdirSync(ROOT, { recursive: true, encoding: 'utf8' })
    .filter((path) => /\.(vue|ts)$/.test(path) && !path.endsWith('.spec.ts'))
    .map((path) => [path, readFileSync(`${ROOT}/${path}`, 'utf8')]);

/** `file:line` for every line of every source that matches. */
function linesMatching(pattern: RegExp, keep: (line: string, path: string) => boolean = () => true): string[] {
    return SOURCES.flatMap(([path, text]) =>
        text
            .split('\n')
            .map((line, index) => ({ line, at: `${path}:${index + 1}` }))
            .filter(({ line }) => pattern.test(line) && keep(line, path))
            .map(({ at }) => at),
    );
}

describe('UI source rules (F-42)', () => {
    it('radius per role (A-63): no xl control in AppContextBar, 2xl only on dialog panels, state plates and Ping, no 90vh', () => {
        // DS §4.2: 8px controls (lg), 12px cards (xl), 16px dialogs (2xl). A
        // segmented track stays xl: 8px buttons + its 4px padding (owner, 5.3).
        expect(
            linesMatching(/\brounded-xl\b/, (line, path) => path.endsWith('AppContextBar.vue') && !/\bsegmented\b/.test(line)),
        ).toEqual([]);

        expect(
            linesMatching(
                /\brounded-2xl\b/,
                (line, path) => !/\b(dialog-panel|modal-panel|state-plate)\b/.test(line) && !path.endsWith('pages/Ping.vue'),
            ),
        ).toEqual([]);

        // 90vh ignores the phone's toolbar, so Save can sit under it; dvh does not.
        expect(linesMatching(/\b90vh\b/)).toEqual([]);
    });

    it('one global focus rule (A-34): app.css holds :focus-visible once with --color-focus-ring, and no element repeats it', () => {
        // DS §6: every interactive element shows focus with focus-ring.
        const rules = [...readFileSync('resources/css/app.css', 'utf8').matchAll(/:focus-visible\s*\{([^}]*)\}/g)];

        expect(rules).toHaveLength(1);
        expect(rules[0]?.[1]).toMatch(/outline:\s*2px solid var\(--color-focus-ring\);/);
        expect(rules[0]?.[1]).toMatch(/outline-offset:\s*2px;/);
        expect(linesMatching(/focus-visible:outline-2\b/)).toEqual([]);
        expect(linesMatching(/outline:\s*2px solid var\(--color-focus-ring\)/)).toEqual([]);
    });

    it('focus rings only for the keyboard (A-64): no focus:outline / focus:ring utility', () => {
        // A focus: ring also shows on a mouse click; A-34's :focus-visible rule
        // draws the same ring for the keyboard only.
        expect(linesMatching(/\bfocus:(outline|ring)/)).toEqual([]);
    });

    it('one secondary button (A-51): app.css holds .row-action with :hover and :active once, and no component repeats it', () => {
        // DS §6.2: every variant has hover and active states.
        const css = readFileSync('resources/css/app.css', 'utf8');

        expect(css.match(/^\.row-action \{/gm)).toHaveLength(1);
        expect(css.match(/^\.row-action:hover/gm)).toHaveLength(1);
        expect(css.match(/^\.row-action:active/gm)).toHaveLength(1);
        expect(linesMatching(/^\.row-action\b/)).toEqual([]);
    });

    it('action variants (A-33): app.css holds the primary, the filled Danger and the row Danger text once each', () => {
        // DS §6.2; the owner's Option B: row Danger is text, the filled Danger lives inside the confirm.
        const css = readFileSync('resources/css/app.css', 'utf8');

        expect(css.match(/^\.create-action \{/gm)).toHaveLength(1);
        expect(css.match(/^\.danger-action \{/gm)).toHaveLength(1);
        expect(css.match(/^\.danger-text \{/gm)).toHaveLength(1);
    });

    it('one status chip (A-55): app.css holds .status-chip once, no component repeats it, and no chip draws a dot', () => {
        // Suppliers' and catalog's identical copies are outside A-55's files: a CHECKLIST debt row, the owner's call.
        const css = readFileSync('resources/css/app.css', 'utf8');
        const later = /^pages\/(suppliers|catalog)\//;

        expect(css.match(/^\.status-chip \{/gm)).toHaveLength(1);
        expect(linesMatching(/^\.status-chip\b/, (_, path) => !later.test(path))).toEqual([]);
        expect(linesMatching(/status-dot/)).toEqual([]);
    });

    it('native dialogs (A-04): no aria-modal div; every modal opens a <dialog> through useModalDialog', () => {
        // DS §6.6: showModal() traps focus, closes on Escape and makes the page behind inert, with no code of ours.
        expect(linesMatching(/aria-modal="true"/)).toEqual([]);

        const modals = SOURCES.filter(([path, text]) => path.endsWith('.vue') && /<dialog\b/.test(text)).map(([path]) => path);
        expect(modals.sort()).toEqual([
            'components/FileViewerDialog.vue',
            'components/imports/ImportModal.vue',
            'components/profile/EmailChallengeModal.vue',
            'components/roles/PermissionDiffModal.vue',
            'components/roles/RoleFormModal.vue',
            'components/users/ConfirmDialog.vue',
            'components/users/UserDetailsDrawer.vue',
            'components/users/UserFormModal.vue',
            'pages/approvals/ApprovalsView.vue',
            'pages/catalog/CatalogItemFormModal.vue',
            'pages/customers/CustomerFormModal.vue',
            'pages/deals/DealFormModal.vue',
            'pages/reports/ReportDetailView.vue',
            'pages/reports/ReportsView.vue',
            'pages/supplier-quotations/SupplierQuotationFormModal.vue',
            'pages/suppliers/SupplierFormModal.vue',
        ]);
        expect(modals.filter((path) => !(SOURCES.find(([p]) => p === path)?.[1] ?? '').includes('useModalDialog('))).toEqual([]);
    });

    it('one h1 per page (A-28): the context bar and the refusal hold none, Home and /403 hold one each', () => {
        // DS §8 "semantic headings": the page's own h1 is the only one.
        const h1s = (path: string): number => (SOURCES.find(([p]) => p === path)?.[1].match(/<h1\b/g) ?? []).length;

        expect(h1s('components/AppContextBar.vue')).toBe(0);
        expect(h1s('components/states/PermissionDeniedState.vue')).toBe(0);
        expect(h1s('pages/Ping.vue')).toBe(1);
        expect(h1s('pages/ForbiddenView.vue')).toBe(1);
    });

    it('one date form (A-06): no toLocaleDateString / toLocaleTimeString / toLocaleString outside displayDate.ts', () => {
        // D-70: Western digits in every locale, which only displayDate.ts asks for.
        expect(
            linesMatching(/\.toLocale(Date|Time)?String\(/, (_, path) => path !== 'domain/displayDate.ts'),
        ).toEqual([]);
    });

    it('a sticky header that sticks (A-40): no sticky <thead> inside an overflow-x-auto frame', () => {
        // An overflow-x-auto frame is a scroll container, so a sticky header sticks to it and never moves.
        // A frame that scrolls only below 640 (`sm:overflow-x-clip`) still sticks where the header is used:
        // the deals table stays a table on a phone (A-19), so it scrolls there (owner, 2026-10-14).
        const framed = SOURCES.filter(([, text]) =>
            [...text.matchAll(/class="[^"]*\boverflow-x-auto\b[^"]*"/g)].some(([attr]) => !/\bsm:overflow-x-clip\b/.test(attr)),
        ).map(([path]) => path);

        expect(linesMatching(/<thead\b[^>]*\bsticky\b/, (_, path) => framed.includes(path))).toEqual([]);
    });

    it('long text stays inside (A-52): every truncate class sits on an element with a title', () => {
        // The whole opening tag, which may span lines, is what must carry the title.
        // The one exception is the deals list's details preview: its trigger opens
        // the whole text in a popover (F-41 · 1.5, A-58), which a title would double.
        const untitled = SOURCES.flatMap(([path, text]) =>
            [...text.matchAll(/<[a-zA-Z][^>]*\btruncate\b[^>]*>/g)].filter(([tag]) => !/\btitle=|\bdetails-preview\b/.test(tag)).map(([tag]) => `${path}: ${tag}`),
        );

        expect(untitled).toEqual([]);
    });

    it('44 px targets (A-38): login\'s fields and button, every customers checkbox inside a min-h-11 label, the Back links inline-flex', () => {
        const source = (path: string) => SOURCES.find(([at]) => at === path)?.[1] ?? '';
        const short = (tags: string[]) => tags.filter((tag) => !/\bmin-h-11\b/.test(tag));

        const login = source('pages/auth/LoginView.vue');
        expect(short([...login.matchAll(/<(?:input|button)\b[^>]*>/g)].map(([tag]) => tag))).toEqual([]);

        // A checkbox is 16 px; the label around it is the 44 px target (WCAG 2.5.8 / DS §6.1).
        const customers = source('pages/customers/CustomersView.vue');
        const boxes = [...customers.matchAll(/type="checkbox"/g)].length;
        const wrapped = [...customers.matchAll(/<label\b[^>]*\bmin-h-11\b[^>]*>\s*<input\b[^>]*type="checkbox"/g)].length;
        expect(boxes).toBeGreaterThan(0);
        expect(wrapped).toBe(boxes);

        const backs = [...source('pages/customers/CustomerDetailView.vue').matchAll(/<RouterLink\b[^>]*data-testid="customer-back"[^>]*>/g)].map(([tag]) => tag);
        expect(backs).toHaveLength(3);
        expect(backs.filter((tag) => !/\binline-flex\b/.test(tag) || !/\bmin-h-11\b/.test(tag))).toEqual([]);
    });

    it('the builder (A-20, A-60, A-15): a sticky Save bar, scroll padding for it, four line columns, and no Number(', () => {
        const builder = SOURCES.find(([at]) => at === 'pages/quotations/QuotationBuilderView.vue')?.[1] ?? '';

        // A-20: Save stays in view, and a focused field never hides under the bar.
        expect(builder).toMatch(/\.action-bar\s*\{[^}]*position:\s*sticky;[^}]*inset-block-end:\s*0;/);
        expect(builder).toMatch(/scroll-padding-block-end:/);
        expect(builder).toMatch(/class="action-bar\b/);

        // A-60: label, quantity, margin and Remove — four columns, so Remove stays on its line.
        const columns = /\.line-grid\s*\{[^}]*grid-template-columns:\s*([^;]+);/.exec(builder)?.[1] ?? '';
        expect(columns.trim().split(/\s+(?![^(]*\))/)).toHaveLength(4);

        // A-15 / D-67: the SPA computes no figure.
        expect(builder).not.toMatch(/\bNumber\(/);
    });

    it('polish (A-66): named sizes, aria-controls, the link token, theme-color, one danger-fill text token, Restore dims, the dialog contains its scroll', () => {
        const source = (path: string) => SOURCES.find(([at]) => at === path)?.[1] ?? '';

        // SH-15: DS §4.1's named sizes, not Tailwind's.
        expect(source('components/SearchCombobox.vue')).not.toMatch(/\btext-(xs|sm|base)\b/);

        // SH-24: the menu button points at the drawer it toggles.
        expect(source('components/AppContextBar.vue')).toMatch(/aria-controls="app-sidebar"/);

        // SH-44: a link takes the link token.
        expect(source('pages/purchase-orders/PurchaseOrdersView.vue')).toMatch(/\.row-link\s*\{[^}]*color:\s*var\(--color-link\)/);

        // SH-48: the phone's browser bar has a colour to follow.
        expect(readFileSync('resources/views/welcome.blade.php', 'utf8')).toMatch(/<meta name="theme-color"/);

        // DC-42: text on a danger fill is one token, the one app.css's .danger-action uses.
        const fills = SOURCES.flatMap(([path, text]) =>
            [...text.matchAll(/\{[^{}]*background-color:\s*var\(--color-danger\);[^{}]*\}/g)]
                .filter(([rule]) => /color:\s*var\(--color-primary-text\)/.test(rule))
                .map(() => path));
        expect(fills).toEqual([]);

        // DC-62: a disabled Restore looks disabled, as its siblings do.
        const restore = /<button\b[^>]*data-testid="customers-row-restore"/.exec(source('pages/customers/CustomersView.vue'))?.[0] ?? '';
        expect(restore).toMatch(/\bdisabled:opacity-60\b/);

        // Q-39: scrolling past the dialog's end does not scroll the page behind it.
        expect(source('pages/supplier-quotations/SupplierQuotationFormModal.vue')).toMatch(/class="modal-panel\b[^"]*\boverscroll-contain\b/);
    });
});
