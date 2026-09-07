<?php

namespace b10k\componentguide\tests\Unit;

use b10k\componentguide\services\StoryStatusWriter;
use PHPUnit\Framework\TestCase;

class StoryStatusWriterTest extends TestCase
{
    private StoryStatusWriter $writer;

    /** @var string[] */
    private array $tmpFiles = [];

    protected function setUp(): void
    {
        $this->writer = new StoryStatusWriter();
    }

    protected function tearDown(): void
    {
        foreach ($this->tmpFiles as $file) {
            @unlink($file);
        }
        $this->tmpFiles = [];
    }

    // ---- Twig -----------------------------------------------------------

    public function testTwigReplacesOnlyTheMetaStatusAndNothingElse(): void
    {
        $source = <<<'TWIG'
            {# Hand-written comment that must survive.   trailing spaces too   #}

            {% set meta = {
                title: 'Hero',
                description: 'Opening section. Has {braces} in the text.',
                status: 'draft',
            } %}

            {% set stories = {
                'Default': {
                    args: {
                        heading: 'Ride better',
                        status: 'draft',
                    },
                },
            } %}

            TWIG;

        $result = $this->writer->rewrite($source, 'stable', true);

        // Exactly one substitution: the meta status.
        $this->assertSame(
            str_replace("    status: 'draft',\n} %}", "    status: 'stable',\n} %}", $source),
            $result,
        );
        // The story arg that happens to be called `status` is untouched.
        $this->assertStringContainsString("heading: 'Ride better',\n            status: 'draft',", $result);
        // Everything outside the one line is byte-identical.
        $this->assertSame(strlen($source) + strlen('stable') - strlen('draft'), strlen($result));
    }

    public function testTwigKeepsDoubleQuotes(): void
    {
        $source = "{% set meta = { title: \"X\", status: \"draft\" } %}\n{% set stories = {} %}\n";
        $this->assertSame(
            "{% set meta = { title: \"X\", status: \"stable\" } %}\n{% set stories = {} %}\n",
            $this->writer->rewrite($source, 'stable', true),
        );
    }

    public function testTwigInsertsStatusWhenMetaHasNone(): void
    {
        $source = <<<'TWIG'
            {% set meta = {
                title: 'Hero',
            } %}
            {% set stories = {} %}

            TWIG;

        $result = $this->writer->rewrite($source, 'draft', true);

        $this->assertStringContainsString("{% set meta = {\n    status: 'draft',\n    title: 'Hero',\n} %}", $result);
    }

    public function testTwigInsertsInlineWhenMetaIsOneLine(): void
    {
        $source = "{% set meta = { title: 'Hero' } %}\n{% set stories = {} %}\n";
        $this->assertSame(
            "{% set meta = { status: 'draft', title: 'Hero' } %}\n{% set stories = {} %}\n",
            $this->writer->rewrite($source, 'draft', true),
        );
    }

    public function testTwigWithoutMetaIsRefused(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->writer->rewrite("{% set stories = { 'Default': { args: {} } } %}\n", 'stable', true);
    }

    // ---- PHP ------------------------------------------------------------

    public function testPhpReplacesOnlyTheMetaStatus(): void
    {
        $source = <<<'PHP'
            <?php

            return [
                'meta' => [
                    'title' => 'Button',
                    'status' => 'draft',
                ],
                'stories' => [
                    'Primary' => [
                        'args' => ['label' => 'Save', 'status' => 'draft'],
                    ],
                ],
            ];

            PHP;

        $result = $this->writer->rewrite($source, 'stable', false);

        $this->assertStringContainsString("'title' => 'Button',\n        'status' => 'stable',", $result);
        $this->assertStringContainsString("['label' => 'Save', 'status' => 'draft']", $result);
        $this->assertSame(strlen($source) + 1, strlen($result));
    }

    public function testPhpInsertsStatusWhenMetaHasNone(): void
    {
        $source = <<<'PHP'
            <?php

            return [
                'meta' => [
                    'title' => 'Button',
                ],
                'stories' => [],
            ];

            PHP;

        $result = $this->writer->rewrite($source, 'draft', false);

        $this->assertStringContainsString("'meta' => [\n        'status' => 'draft',\n        'title' => 'Button',\n    ],", $result);
    }

    public function testPhpSimpleFormatIsRefused(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->writer->rewrite("<?php\nreturn ['Primary' => ['label' => 'Save']];\n", 'stable', false);
    }

    // ---- Writing to disk ------------------------------------------------

    public function testSetStatusWritesFileAndReturnsHashOfWhatItWrote(): void
    {
        $base = tempnam(sys_get_temp_dir(), 'cg');
        $file = $base . '.stories.twig';
        $this->tmpFiles[] = $base;
        $this->tmpFiles[] = $file;
        file_put_contents($file, "{% set meta = { status: 'draft' } %}\n{% set stories = {} %}\n");

        $hash = $this->writer->setStatus($file, 'stable');

        $written = file_get_contents($file);
        $this->assertSame("{% set meta = { status: 'stable' } %}\n{% set stories = {} %}\n", $written);
        $this->assertSame(sha1($written), $hash);
    }

    public function testSetStatusRejectsUnknownStatus(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->writer->setStatus('/nonexistent.stories.twig', 'shipped');
    }
}
