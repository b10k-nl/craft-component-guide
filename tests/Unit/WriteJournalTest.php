<?php

namespace b10k\componentguide\tests\Unit;

use b10k\componentguide\services\WriteJournal;
use PHPUnit\Framework\TestCase;

class WriteJournalTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/cg-journal-' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/.git', 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $entry) {
            if (is_file($entry)) {
                @unlink($entry);
            }
        }
        @unlink($this->dir . '/storage/writes.json');
        @rmdir($this->dir . '/storage');
        @unlink($this->dir . '/.git/index');
        @rmdir($this->dir . '/.git');
        @rmdir($this->dir);
    }

    private function journal(?string $gitIndex = null): WriteJournal
    {
        return new WriteJournal($this->dir . '/storage/writes.json', $gitIndex);
    }

    private function story(string $name, string $contents): string
    {
        $path = $this->dir . '/' . $name;
        file_put_contents($path, $contents);
        return $path;
    }

    public function testRecordsAndListsAVerifiedWrite(): void
    {
        $file = $this->story('hero.stories.twig', "status: 'stable'");
        $journal = $this->journal();

        $journal->record($file, sha1_file($file), 'status');

        $entries = $journal->entries();
        $this->assertArrayHasKey($file, $entries);
        $this->assertSame('status', $entries[$file]['action']);
    }

    public function testDropsEntryWhenFileWasEditedAfterTheWrite(): void
    {
        $file = $this->story('hero.stories.twig', "status: 'stable'");
        $journal = $this->journal();
        $journal->record($file, sha1_file($file), 'status');

        // Developer reverts the change in the IDE.
        file_put_contents($file, "status: 'draft'");

        $this->assertSame([], $journal->entries());
    }

    public function testDropsEntryWhenFileIsGone(): void
    {
        $file = $this->story('hero.stories.twig', 'x');
        $journal = $this->journal();
        $journal->record($file, sha1_file($file), 'scaffold');

        unlink($file);

        $this->assertSame([], $journal->entries());
    }

    public function testDropsEntryWhenGitIndexIsNewerThanTheFile(): void
    {
        $file = $this->story('hero.stories.twig', "status: 'stable'");
        $index = $this->dir . '/.git/index';
        file_put_contents($index, 'DIRC');

        // The write happened before the last git operation.
        touch($file, time() - 60);
        touch($index, time());

        $journal = $this->journal($index);
        $journal->record($file, sha1_file($file), 'status');

        $this->assertSame([], $journal->entries());
    }

    public function testKeepsEntryWhenGitIndexIsOlderThanTheFile(): void
    {
        $file = $this->story('hero.stories.twig', "status: 'stable'");
        $index = $this->dir . '/.git/index';
        file_put_contents($index, 'DIRC');

        touch($index, time() - 60);
        touch($file, time());

        $journal = $this->journal($index);
        $journal->record($file, sha1_file($file), 'status');

        $this->assertArrayHasKey($file, $journal->entries());
    }

    public function testMarkReviewedForgetsOneOrAll(): void
    {
        $a = $this->story('a.stories.twig', 'a');
        $b = $this->story('b.stories.twig', 'b');
        $journal = $this->journal();
        $journal->record($a, sha1_file($a), 'scaffold');
        $journal->record($b, sha1_file($b), 'scaffold');

        $journal->markReviewed($a);
        $this->assertSame([$b], array_keys($journal->entries()));

        $journal->markReviewed();
        $this->assertSame([], $journal->entries());
    }

    public function testReRecordingSamePathReplacesTheEntry(): void
    {
        $file = $this->story('hero.stories.twig', 'v1');
        $journal = $this->journal();
        $journal->record($file, sha1_file($file), 'scaffold');

        file_put_contents($file, 'v2');
        $journal->record($file, sha1_file($file), 'status');

        $entries = $journal->entries();
        $this->assertCount(1, $entries);
        $this->assertSame('status', $entries[$file]['action']);
    }

    public function testLocateGitIndexWalksUp(): void
    {
        file_put_contents($this->dir . '/.git/index', 'DIRC');
        mkdir($this->dir . '/templates/_blocks', 0777, true);

        $this->assertSame(
            $this->dir . '/.git/index',
            WriteJournal::locateGitIndex($this->dir . '/templates/_blocks'),
        );

        rmdir($this->dir . '/templates/_blocks');
        rmdir($this->dir . '/templates');
    }

    public function testLocateGitIndexReturnsNullWithoutRepository(): void
    {
        $this->assertNull(WriteJournal::locateGitIndex(sys_get_temp_dir() . '/definitely/not/a/repo'));
    }
}
