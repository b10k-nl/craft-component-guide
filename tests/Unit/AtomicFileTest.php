<?php

namespace b10k\componentguide\tests\Unit;

use b10k\componentguide\helpers\AtomicFile;
use PHPUnit\Framework\TestCase;

/**
 * The developer's files: replaced whole or not at all, created only when new.
 */
class AtomicFileTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/cg-atomic-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/{,.}*', GLOB_BRACE) ?: [] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        @chmod($this->dir, 0755);
        rmdir($this->dir);
    }

    public function testReplaceLeavesNoTempFileAndKeepsThePermissions(): void
    {
        $path = $this->dir . '/hero.stories.twig';
        file_put_contents($path, 'old');
        chmod($path, 0640);

        AtomicFile::replace($path, 'new');

        $this->assertSame('new', file_get_contents($path));
        $this->assertSame(0640, fileperms($path) & 0777);
        $this->assertSame(['hero.stories.twig'], array_values(array_diff(scandir($this->dir), ['.', '..'])));
    }

    public function testCreateNeverOverwrites(): void
    {
        $path = $this->dir . '/hero.stories.twig';

        $this->assertTrue(AtomicFile::create($path, 'first'));
        $this->assertFalse(AtomicFile::create($path, 'second'), 'a second writer finds the file there');
        $this->assertSame('first', file_get_contents($path));
    }

    public function testAFailedReplaceLeavesTheOriginal(): void
    {
        if (function_exists('posix_getuid') && posix_getuid() === 0) {
            $this->markTestSkipped('root writes into read-only directories');
        }
        $path = $this->dir . '/hero.stories.twig';
        file_put_contents($path, 'old');
        chmod($this->dir, 0555);

        try {
            AtomicFile::replace($path, 'new');
            $this->fail('expected the write to fail in a read-only directory');
        } catch (\RuntimeException) {
            $this->assertSame('old', file_get_contents($path));
        }
    }
}
