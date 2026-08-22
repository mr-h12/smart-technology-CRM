<?php

declare(strict_types=1);

namespace Tests\Feature\Design;

use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/**
 * Point 4.4 — the four states §8 says a screen is not complete without.
 *
 * "Loading, empty, error, disabled, and permission-denied states" is a line in
 * a checklist, and checklists are satisfied by whatever the author remembers on
 * the day. The rules underneath it are sharper, and each one names a way of
 * building these that looks finished and is not:
 *
 *   §9.5  a state must be "visible without relying on color alone" — so a red
 *         box is not an error state and a spinner alone is not a loading state.
 *   §6.1  "Loading controls retain their width and show a text alternative."
 *   §6.4  danger is "Red icon + label", never the colour by itself.
 *   §8    announced status changes, meaningful icon labels, 44 × 44px targets,
 *         and no component hard-coding a colour past the tokens.
 *   SEC-09 hiding is "a visual complement to API enforcement", never the
 *         enforcement — and a denial that explains itself is an oracle.
 *
 * Every assertion below is one of those sentences, made into something a build
 * can fail on.
 *
 * ── What this file cannot do ───────────────────────────────────────────────
 *
 * It reads source. It does not mount a component, so it cannot prove a screen
 * reader announced anything, that the spinner is visible in Dark Blue, or that
 * a real 44px target is reachable by thumb. It proves the attributes that make
 * those possible are present, which is the part that silently disappears.
 */
final class StateComponentsTest extends TestCase
{
    private const DIRECTORY = 'resources/js/components/states';

    /** @var array<string, string> */
    private const STATES = [
        'loading' => 'LoadingState.vue',
        'empty' => 'EmptyState.vue',
        'error' => 'ErrorState.vue',
        'permission denied' => 'PermissionDeniedState.vue',
    ];

    /** @return array<string, array{0: string}> */
    public static function states(): array
    {
        $cases = [];

        foreach (self::STATES as $label => $file) {
            $cases[$label] = [$file];
        }

        return $cases;
    }

    public function test_all_four_documented_states_exist(): void
    {
        // §8 names five; disabled belongs to the control that is disabled, not
        // to a whole region, so it is a §6.2 button state rather than one of
        // these. The other four are regions and live here.
        $found = array_map(
            static fn (string $path): string => basename($path),
            self::files(),
        );

        sort($found);
        $expected = array_values(self::STATES);
        sort($expected);

        self::assertSame($expected, $found, 'The set of state components changed.');
    }

    #[DataProvider('states')]
    public function test_the_state_is_carried_by_an_icon_and_text_not_by_colour(string $file): void
    {
        // §9.5. This is the assertion the whole file exists for: colour is the
        // one channel a reader may not have, whether through a screen reader,
        // a colour-vision difference, or Dark Blue rendering the same token
        // differently. Icon *and* text, in every state.
        $source = self::read($file);

        self::assertStringContainsString('<svg', $source, "{$file} has no icon (§6.4: icon + text).");
        self::assertMatchesRegularExpression('/\bt\(/', $source, "{$file} renders no text (§9.5: never colour alone).");
    }

    #[DataProvider('states')]
    public function test_the_icon_is_decorative_and_the_text_carries_the_meaning(string $file): void
    {
        // §8 asks for "meaningful icon labels". The meaningful label here is the
        // heading beside the icon, so the glyph is hidden from assistive
        // technology rather than given a duplicate name that reads twice.
        $source = self::read($file);

        if (preg_match_all('/<svg\b[^>]*>/', $source, $matches) === false) {
            throw new RuntimeException('Reading icons failed: '.preg_last_error_msg());
        }

        foreach ($matches[0] as $tag) {
            self::assertStringContainsString('aria-hidden="true"', $tag, "An icon in {$file} is not hidden from assistive technology.");
        }
    }

    #[DataProvider('states')]
    public function test_the_state_hard_codes_no_colour(string $file): void
    {
        // §8: "No component hard-codes a color, radius, spacing, shadow, or
        // direction that bypasses tokens." A hex here would look right in one
        // theme and wrong in the other two, and nothing would report it.
        $source = self::read($file);

        if (preg_match_all('/#[0-9a-fA-F]{3,8}\b/', $source, $matches) === false) {
            throw new RuntimeException('Scanning for colours failed: '.preg_last_error_msg());
        }

        self::assertSame([], $matches[0], "{$file} hard-codes a colour instead of consuming a token (§3.2, §8).");
        self::assertStringNotContainsString('rgb(', $source);
    }

    public function test_loading_announces_itself_and_keeps_its_width(): void
    {
        // §6.1: "Loading controls retain their width and show a text
        // alternative." w-full is the width half; role="status" with a polite
        // live region is what makes it an announced state (§8) rather than a
        // picture of one.
        $source = self::read(self::STATES['loading']);

        self::assertStringContainsString('role="status"', $source);
        self::assertStringContainsString('aria-live="polite"', $source);
        self::assertStringContainsString('w-full', $source, 'A loading state that collapses makes the page jump when data lands.');
        self::assertStringContainsString('motion-reduce:animate-none', $source, 'A spinner must stop for prefers-reduced-motion.');
    }

    public function test_error_interrupts_and_offers_the_documented_action(): void
    {
        // role="alert" is assertive: an error that appears after the page has
        // settled has to interrupt, or a screen-reader user goes on waiting for
        // data that will never arrive. §6.4 requires the action to be clear.
        $source = self::read(self::STATES['error']);

        self::assertStringContainsString('role="alert"', $source);
        self::assertStringContainsString("t('state.retry')", $source);
        self::assertStringContainsString('$emit(\'retry\')', $source, 'The retry control must hand the decision back to the caller.');

        // §8: "Touch targets of at least 44 × 44px for primary mobile actions."
        // Tailwind's spacing scale is 0.25rem, so 11 is 2.75rem is 44px.
        self::assertStringContainsString('min-h-11', $source);
        self::assertStringContainsString('min-w-11', $source);
    }

    public function test_permission_denied_explains_nothing_about_what_was_denied(): void
    {
        // SEC-09 and §9.3. A denial that names the resource, the permission or
        // the record turns an access-control boundary into an enumeration
        // oracle: it confirms the thing exists and says what it is, which is
        // what the refusal was protecting. So this component takes no input at
        // all — there is nothing for a caller to pass in and leak.
        $source = self::read(self::STATES['permission denied']);

        self::assertStringNotContainsString('defineProps', $source, 'A denial with props is a denial that can be made to say too much.');
        self::assertStringNotContainsString('useRoute', $source);

        // And no retry control: a 403 does not become a 200 by asking again, so
        // offering the button would be an invitation to a wall.
        //
        // The template, not the file. The first version read the whole thing and
        // failed on the comment above explaining why there is no retry — the
        // third time in this suite a check has read its own documentation as
        // code, and the reason every scanner here strips comments first.
        $template = self::template($source);

        self::assertStringNotContainsString('retry', $template);
        self::assertStringNotContainsString('<button', $template, 'A denial offers no action; there is none to offer.');
        self::assertStringContainsString('role="status"', $template);
    }

    // ───────────────────────────────────────────────────────────── mechanics

    /** A component's <template> block, with markup comments removed. */
    private static function template(string $source): string
    {
        if (preg_match('/<template>(.*)<\/template>/s', $source, $matches) !== 1) {
            throw new RuntimeException('The component has no template block.');
        }

        $stripped = preg_replace('/<!--.*?-->/s', '', $matches[1]);

        if ($stripped === null) {
            throw new RuntimeException('Stripping comments failed: '.preg_last_error_msg());
        }

        return $stripped;
    }

    /** @return list<string> */
    private static function files(): array
    {
        $paths = glob(base_path(self::DIRECTORY).'/*.vue');

        if ($paths === false) {
            throw new RuntimeException('Could not list '.self::DIRECTORY.'.');
        }

        sort($paths);

        return $paths;
    }

    private static function read(string $file): string
    {
        $contents = file_get_contents(base_path(self::DIRECTORY.'/'.$file));

        if ($contents === false) {
            throw new RuntimeException("Could not read {$file}.");
        }

        return $contents;
    }
}
