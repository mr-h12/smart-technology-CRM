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

    it('focus rings only for the keyboard (A-64): no focus:outline / focus:ring utility outside pages/deals', () => {
        // A focus: ring also shows on a mouse click; A-34's :focus-visible rule
        // draws the same ring for the keyboard only.
        // ponytail: pages/deals waits for F-42 step 15 (after F-41 · 1.8, plan §3); drop the exemption there.
        expect(linesMatching(/\bfocus:(outline|ring)/, (_, path) => !path.startsWith('pages/deals/'))).toEqual([]);
    });

    it('one secondary button (A-51): app.css holds .row-action with :hover and :active once, and no component outside pages/deals repeats it', () => {
        // DS §6.2: every variant has hover and active states.
        // ponytail: pages/deals waits for F-42 step 15 (after F-41 · 1.8, plan §3); drop the exemption there.
        const css = readFileSync('resources/css/app.css', 'utf8');

        expect(css.match(/^\.row-action \{/gm)).toHaveLength(1);
        expect(css.match(/^\.row-action:hover/gm)).toHaveLength(1);
        expect(css.match(/^\.row-action:active/gm)).toHaveLength(1);
        expect(linesMatching(/^\.row-action\b/, (_, path) => !path.startsWith('pages/deals/'))).toEqual([]);
    });

    it('action variants (A-33): app.css holds the primary, the filled Danger and the row Danger text once each', () => {
        // DS §6.2; the owner's Option B: row Danger is text, the filled Danger lives inside the confirm.
        const css = readFileSync('resources/css/app.css', 'utf8');

        expect(css.match(/^\.create-action \{/gm)).toHaveLength(1);
        expect(css.match(/^\.danger-action \{/gm)).toHaveLength(1);
        expect(css.match(/^\.danger-text \{/gm)).toHaveLength(1);
    });

    it('one status chip (A-55): app.css holds .status-chip once, no component outside pages/deals repeats it, and no chip draws a dot', () => {
        // ponytail: pages/deals waits for F-42 step 15 (after F-41 · 1.8, plan §3); drop the exemption there.
        // Suppliers' and catalog's identical copies are outside A-55's files: a CHECKLIST debt row, the owner's call.
        const css = readFileSync('resources/css/app.css', 'utf8');
        const later = /^pages\/(deals|suppliers|catalog)\//;

        expect(css.match(/^\.status-chip \{/gm)).toHaveLength(1);
        expect(linesMatching(/^\.status-chip\b/, (_, path) => !later.test(path))).toEqual([]);
        expect(linesMatching(/status-dot/)).toEqual([]);
    });

    it('native dialogs (A-04): no aria-modal div outside pages/deals; every modal opens a <dialog> through useModalDialog', () => {
        // DS §6.6: showModal() traps focus, closes on Escape and makes the page behind inert, with no code of ours.
        // ponytail: pages/deals waits for F-42 step 15 (after F-41 · 1.8, plan §3); drop the exemption there.
        expect(linesMatching(/aria-modal="true"/, (_, path) => !path.startsWith('pages/deals/'))).toEqual([]);

        const modals = SOURCES.filter(([path, text]) => path.endsWith('.vue') && !path.startsWith('pages/deals/') && /<dialog\b/.test(text)).map(([path]) => path);
        expect(modals.sort()).toEqual([
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

    // ponytail (the three cases below): pages/deals waits for F-42 step 15 (after F-41 · 1.8, plan §3); drop the exemption there.
    it('one date form (A-06): no toLocaleDateString / toLocaleTimeString / toLocaleString outside displayDate.ts', () => {
        // D-70: Western digits in every locale, which only displayDate.ts asks for.
        expect(
            linesMatching(/\.toLocale(Date|Time)?String\(/, (_, path) => path !== 'domain/displayDate.ts' && !path.startsWith('pages/deals/')),
        ).toEqual([]);
    });

    it('a sticky header that sticks (A-40): no sticky <thead> inside an overflow-x-auto frame', () => {
        // An overflow-x-auto frame is a scroll container, so a sticky header sticks to it and never moves.
        const framed = SOURCES.filter(([path, text]) => !path.startsWith('pages/deals/') && /\boverflow-x-auto\b/.test(text)).map(([path]) => path);

        expect(linesMatching(/<thead\b[^>]*\bsticky\b/, (_, path) => framed.includes(path))).toEqual([]);
    });

    it('long text stays inside (A-52): every truncate class sits on an element with a title', () => {
        // The whole opening tag, which may span lines, is what must carry the title.
        const untitled = SOURCES.filter(([path]) => !path.startsWith('pages/deals/')).flatMap(([path, text]) =>
            [...text.matchAll(/<[a-zA-Z][^>]*\btruncate\b[^>]*>/g)].filter(([tag]) => !/\btitle=/.test(tag)).map(([tag]) => `${path}: ${tag}`),
        );

        expect(untitled).toEqual([]);
    });
});
