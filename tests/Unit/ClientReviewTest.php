<?php

namespace b10k\componentguide\tests\Unit;

use b10k\componentguide\helpers\ClientReview;
use b10k\componentguide\helpers\StoryAppender;
use b10k\componentguide\services\StoryParser;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

/**
 * Client review, the whole way: a decision is recorded, the latest one on a
 * story is where it stands for everyone, it travels in an exported file, and
 * an approval lands in the story file as `approval:` — read back through Twig
 * and the story parser.
 */
class ClientReviewTest extends TestCase
{
    private const HERO = <<<'TWIG'
        {# The template reads `block` itself. #}
        {% set meta = {
            title: 'Hero',
            status: 'stable',
        } %}

        {% set stories = {
            'Light': {
                description: 'Default theme. {Braces} and a # inside a string.',
                args: {
                    theme: 'light',
                    heading: 'Ride better, not just harder',
                },
            },
            'Dark': {
                args: { theme: 'dark', heading: 'Ride better' },
            }
        } %}
        TWIG;

    private const V1 = 'aaaaaaaaaaaa';

    private const V2 = 'bbbbbbbbbbbb';

    /**
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private function decision(string $decision, string $at, array $extra = []): array
    {
        return array_replace(ClientReview::record(
            'blocks-hero',
            'light',
            'Light',
            $decision,
            ['theme' => 'light', 'heading' => 'Ride better, not just harder'],
            $decision === ClientReview::CHANGES ? 'Logo clashes' : '',
            'Anna Smith',
            new \DateTimeImmutable($at),
            self::V1,
        ), $extra);
    }

    /**
     * @return array{meta: array<string, string>, stories: \b10k\componentguide\models\StoryDefinition[], errors: array}
     */
    private function read(string $source): array
    {
        $twig = new Environment(new ArrayLoader(['s' => $source . '{{ {meta: meta, stories: stories}|json_encode|raw }}']));
        $data = json_decode(trim(preg_replace('/^.*?(\{"meta")/s', '$1', $twig->render('s'))), true);

        return (new StoryParser())->parseData($data, 'hero.stories.twig');
    }

    public function testARecordIsWhatWasDecidedByWhomAndWhen(): void
    {
        $review = ClientReview::record('blocks-hero', 'light', 'Light', ClientReview::APPROVED, [], "Looks right.\n\n\n\nShip it.", '  Anna   Smith ', new \DateTimeImmutable('2026-10-30T10:00:00+01:00'), self::V1, 'anna');

        $this->assertSame('approved', $review['decision']);
        $this->assertSame('Anna Smith', $review['by']);
        $this->assertSame('2026-10-30', $review['date']);
        $this->assertSame("Looks right.\n\nShip it.", $review['comment']);
        $this->assertSame(self::V1, $review['version']);
        $this->assertSame('anna', $review['username'], 'the user can be found again by it');
        $this->assertNull(ClientReview::normalize(array_replace($review, ['username' => "an'na"]))['username']);
        $this->assertSame($review, ClientReview::normalize($review), 'a stored record reads back unchanged');
    }

    public function testAskingForChangesNeedsAComment(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ClientReview::record('blocks-hero', 'light', 'Light', ClientReview::CHANGES, [], '   ', 'Anna', new \DateTimeImmutable());
    }

    public function testUntrustedRecordsAreRefused(): void
    {
        $good = $this->decision(ClientReview::APPROVED, '2026-10-30T10:00:00+00:00');
        foreach ([
            ['decision' => 'maybe'],
            ['date' => '30/10/2026'],
            ['componentId' => '../etc'],
            ['args' => 'not a list'],
            ['by' => ''],
        ] as $broken) {
            $this->assertNull(ClientReview::normalize(array_replace($good, $broken)), json_encode($broken));
        }
        $this->assertNull(ClientReview::normalize('nope'));
        $this->assertNull(ClientReview::normalize(array_replace($good, ['version' => '../x']))['version']);
    }

    public function testTheTemplateVersionIsTheSameOnEveryCheckout(): void
    {
        $unix = "{# hero #}\n<section>{{ heading }}</section>\n";

        $this->assertSame(ClientReview::fingerprint($unix), ClientReview::fingerprint(str_replace("\n", "\r\n", $unix)));
        $this->assertNotSame(ClientReview::fingerprint($unix), ClientReview::fingerprint(str_replace('section', 'div', $unix)));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{12}$/', ClientReview::fingerprint($unix));

        // The story's args are part of it: what the story shows changed.
        $this->assertNotSame(ClientReview::fingerprint($unix, ['heading' => 'A']), ClientReview::fingerprint($unix, ['heading' => 'B']));
        $this->assertSame(
            ClientReview::fingerprint($unix, ['heading' => 'A', 'theme' => 'dark']),
            ClientReview::fingerprint($unix, ['theme' => 'dark', 'heading' => 'A']),
            'key order is not a change',
        );
    }

    public function testTheLatestDecisionOnAStoryWinsWhoeverMadeIt(): void
    {
        $approved = $this->decision(ClientReview::APPROVED, '2026-10-30T10:00:00+00:00', ['by' => 'Client']);
        $changes = $this->decision(ClientReview::CHANGES, '2026-10-30T11:00:00+00:00', ['by' => 'Admin']);
        $dark = $this->decision(ClientReview::APPROVED, '2026-10-30T12:00:00+00:00', ['storyId' => 'dark']);

        $latest = ClientReview::latest([$approved, $dark, $changes]);

        $this->assertSame(['blocks-hero|light', 'blocks-hero|dark'], array_keys($latest));
        $this->assertSame('Admin', $latest['blocks-hero|light']['by'], 'the admin asked after the client approved');
        $this->assertSame(ClientReview::STATE_CHANGES, ClientReview::state($latest['blocks-hero|light'], null, self::V1)['state']);
    }

    public function testNewestFirstGoesByTheMomentNotTheText(): void
    {
        // 1 November 2026, Los Angeles: the clocks go back at 02:00, so the
        // hour from 01:00 happens twice — first at -07:00, then at -08:00.
        $first = $this->decision(ClientReview::APPROVED, '2026-11-01T01:47:48-07:00', ['by' => 'Client']);
        $second = $this->decision(ClientReview::CHANGES, '2026-11-01T01:37:44-08:00', ['by' => 'Admin']);

        $list = [$first, $second];
        usort($list, [ClientReview::class, 'newestFirst']);
        $this->assertSame('Admin', $list[0]['by'], '01:37-08:00 is an hour after 01:47-07:00');
        $this->assertSame('Admin', ClientReview::latest([$first, $second])['blocks-hero|light']['by']);
    }

    public function testThreeStatesAndWhyAStoryIsBackToReview(): void
    {
        $approved = $this->decision(ClientReview::APPROVED, '2026-10-30T10:00:00+00:00');
        $changes = $this->decision(ClientReview::CHANGES, '2026-10-30T10:00:00+00:00');
        $fixed = array_replace($changes, ['fixed' => ['by' => 'Bohdan', 'date' => '2026-10-31']]);

        $this->assertSame(['approved', null], $this->pair(ClientReview::state($approved, null, self::V1)));
        $this->assertSame(['review', 'changed'], $this->pair(ClientReview::state($approved, null, self::V2)), 'approved on an earlier version');
        $this->assertSame(['changes', null], $this->pair(ClientReview::state($changes, null, self::V1)));
        $this->assertSame(['review', 'updated'], $this->pair(ClientReview::state($changes, null, self::V2)), 'the block changed after the request');
        $this->assertSame(['review', 'fixed'], $this->pair(ClientReview::state($fixed, null, self::V1)));
        $this->assertSame(['review', 'new'], $this->pair(ClientReview::state(null, null, self::V1)));

        // A template that cannot be read, or a decision without a version: current.
        $this->assertSame(['approved', null], $this->pair(ClientReview::state($approved, null, null)));
        $this->assertSame(['approved', null], $this->pair(ClientReview::state(array_replace($approved, ['version' => null]), null, self::V2)));
    }

    public function testWithNoDecisionHereTheStoryFileDecides(): void
    {
        // Production: nobody reviews there, the approval came in with git.
        $file = ['by' => 'Anna Smith', 'date' => '2026-10-30', 'version' => self::V1];

        $state = ClientReview::state(null, $file, self::V1);
        $this->assertSame(['approved', null], $this->pair($state));
        $this->assertSame('Anna Smith', $state['by']);
        $this->assertSame(['review', 'changed'], $this->pair(ClientReview::state(null, $file, self::V2)));

        // A request for changes made after it outranks it.
        $changes = $this->decision(ClientReview::CHANGES, '2026-10-31T10:00:00+00:00');
        $this->assertSame(['changes', null], $this->pair(ClientReview::state($changes, $file, self::V1)));
    }

    public function testABlockIsWhatItsStoriesAre(): void
    {
        $this->assertSame('changes', ClientReview::blockState(['approved', 'changes', 'review']));
        $this->assertSame('approved', ClientReview::blockState(['approved', 'approved']));
        $this->assertSame('review', ClientReview::blockState(['review', 'approved']), 'Light approved, Dark still to look at');
        $this->assertSame('review', ClientReview::blockState(['review', 'review']));
        $this->assertSame('review', ClientReview::blockState([]));
    }

    public function testTheExportedFileComesBackAsTheSameDecisions(): void
    {
        $reviews = [
            $this->decision(ClientReview::APPROVED, '2026-10-30T10:00:00+00:00'),
            $this->decision(ClientReview::CHANGES, '2026-10-30T11:00:00+00:00', ['fixed' => ['by' => 'Bohdan', 'date' => '2026-10-31']]),
        ];
        $file = ClientReview::export(array_merge($reviews, [['junk' => true]]), 'staging.example.test', new \DateTimeImmutable('2026-10-30'), 'staging');

        $back = ClientReview::import($file);
        $this->assertSame($reviews, $back['reviews']);
        $this->assertSame(1, $back['skipped']);
        $this->assertSame(['staging.example.test', 'staging'], [$back['site'], $back['environment']]);

        // A file from before the environment was written: no environment.
        $old = ClientReview::import((string)json_encode(['format' => 'component-guide/reviews', 'version' => 1, 'site' => 'staging.example.test', 'reviews' => []]));
        $this->assertNull($old['environment']);
    }

    public function testAnythingElseIsNotAReviewsFile(): void
    {
        foreach (['{"format":"other","reviews":[]}', 'not json', '{"format":"component-guide/reviews","version":9,"reviews":[]}'] as $file) {
            try {
                ClientReview::import($file);
                $this->fail('accepted ' . $file);
            } catch (\InvalidArgumentException) {
                $this->assertTrue(true);
            }
        }
    }

    public function testAnApprovalLandsInTheStoryAndReadsBack(): void
    {
        $approval = ['by' => 'Anna Smith', 'date' => '2026-10-30', 'version' => self::V1];
        $updated = StoryAppender::approve(self::HERO, 'Light', $approval);

        // One line added, as the story's first key, at its indentation.
        $this->assertSame(
            str_replace("    'Light': {\n", "    'Light': {\n        approval: { by: 'Anna Smith', date: '2026-10-30', version: '" . self::V1 . "' },\n", self::HERO),
            $updated,
        );

        $result = $this->read($updated);
        $this->assertSame([], $result['errors']);
        $this->assertCount(2, $result['stories']);
        $this->assertSame($approval, $result['stories'][0]->approval);
        $this->assertSame('light', $result['stories'][0]->args['theme']);
        $this->assertNull($result['stories'][1]->approval);
    }

    public function testALaterApprovalReplacesTheOneInTheFile(): void
    {
        $once = StoryAppender::approve(self::HERO, 'Light', ['by' => 'Anna Smith', 'date' => '2026-10-30']);
        $twice = StoryAppender::approve($once, 'Light', ['by' => 'Bo', 'date' => '2026-11-02', 'version' => self::V2]);

        $this->assertStringContainsString("        approval: { by: 'Bo', date: '2026-11-02', version: '" . self::V2 . "' },\n", $twice);
        $this->assertStringNotContainsString('Anna Smith', $twice);
        $this->assertSame(substr_count($once, "\n"), substr_count($twice, "\n"), 'replaced in place, not added');
    }

    public function testAnApprovalOnOneLineStaysOnOneLine(): void
    {
        $source = "{% set stories = {\n    'Dark': { args: { theme: 'dark' } },\n    Plain: { args: {} },\n} %}";

        $this->assertSame(
            "{% set stories = {\n    'Dark': { approval: { by: 'Bo', date: '2026-10-30' }, args: { theme: 'dark' } },\n    Plain: { args: {} },\n} %}",
            StoryAppender::approve($source, 'Dark', ['by' => 'Bo', 'date' => '2026-10-30']),
        );
        $this->assertStringContainsString(
            "Plain: { approval: { by: 'Bo', date: '2026-10-30' }, args: {} },",
            StoryAppender::approve($source, 'Plain', ['by' => 'Bo', 'date' => '2026-10-30']),
            'a bare key is found as well as a quoted one',
        );
    }

    public function testAnApprovalItCannotPlaceWithCertaintyIsRefused(): void
    {
        $cases = [
            'bare args' => "{% set stories = {\n    'Light': { theme: 'light' },\n} %}",
            'only nested' => "{% set stories = {\n    'Other': { args: { 'Light': { x: 1 } } },\n} %}",
            'no stories hash' => "{% set meta = {} %}",
        ];
        foreach ($cases as $name => $source) {
            try {
                StoryAppender::approve($source, 'Light', ['by' => 'Bo', 'date' => '2026-10-30']);
                $this->fail('approved: ' . $name);
            } catch (\RuntimeException) {
                $this->assertTrue(true, $name);
            }
        }
    }

    public function testAnApprovalTakenOutLeavesTheRestAsItWas(): void
    {
        $approved = StoryAppender::approve(self::HERO, 'Light', ['by' => 'Anna Smith', 'date' => '2026-10-30']);
        $this->assertSame(self::HERO, StoryAppender::removeApproval($approved, 'Light'), 'its own line goes');

        $oneLine = "{% set stories = {\n    'Dark': { approval: { by: 'Bo', date: '2026-10-30' }, args: { theme: 'dark' } },\n} %}";
        $this->assertSame(
            "{% set stories = {\n    'Dark': { args: { theme: 'dark' } },\n} %}",
            StoryAppender::removeApproval($oneLine, 'Dark'),
        );

        $this->assertSame(self::HERO, StoryAppender::removeApproval(self::HERO, 'Dark'), 'no approval: nothing changes');
    }

    public function testArgsAreTheSameWhateverTheKeyOrder(): void
    {
        $this->assertTrue(ClientReview::sameArgs(
            ['heading' => 'Hi', 'cards' => [['title' => 'A', 'text' => 'a'], ['title' => 'B', 'text' => 'b']]],
            ['cards' => [['text' => 'a', 'title' => 'A'], ['text' => 'b', 'title' => 'B']], 'heading' => 'Hi'],
        ));
        $this->assertFalse(ClientReview::sameArgs(['heading' => 'Hi'], ['heading' => 'Hi!']));
        $this->assertFalse(ClientReview::sameArgs(['cards' => ['A', 'B']], ['cards' => ['B', 'A']]), 'a list keeps its order');
    }

    public function testAMalformedApprovalIsReportedNotIgnored(): void
    {
        $result = $this->read(str_replace("    'Dark': {\n", "    'Dark': {\n        approval: 'yes',\n", self::HERO));

        $this->assertCount(1, $result['errors']);
        $this->assertNull($result['stories'][1]->approval);
    }

    /**
     * @param array{state: string, reason: string|null} $state
     * @return array{string, string|null}
     */
    private function pair(array $state): array
    {
        return [$state['state'], $state['reason']];
    }
}
