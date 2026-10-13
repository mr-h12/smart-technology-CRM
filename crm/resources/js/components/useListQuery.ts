import { watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';

/**
 * F-42 · A-13: a list's filters, sort and page live in the address, so Back and
 * a shared link bring them back. `arrived(key)` is the value the address came
 * with (`''` when none), read while the list sets its controls up; `keep(state)`
 * then writes every change back, leaving out an empty string or `false` (the
 * defaults).
 */
export function useListQuery(): {
    arrived: (key: string) => string;
    keep: (state: () => Record<string, string | number | boolean>) => void;
} {
    const route = useRoute();
    const router = useRouter();
    const query = { ...route.query };

    return {
        arrived: (key) => {
            const value = query[key];

            return typeof value === 'string' ? value : '';
        },
        keep: (state) => {
            watch(state, (values) => {
                void router.replace({
                    query: Object.fromEntries(
                        Object.entries(values)
                            .filter(([, value]) => value !== '' && value !== false)
                            .map(([key, value]) => [key, String(value)]),
                    ),
                });
            });
        },
    };
}
