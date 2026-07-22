<?php

namespace b10k\componentguide\tests\Unit;

use b10k\componentguide\services\TwigSnippetGenerator;
use PHPUnit\Framework\TestCase;

class TwigSnippetGeneratorTest extends TestCase
{
    private TwigSnippetGenerator $gen;

    protected function setUp(): void
    {
        $this->gen = new TwigSnippetGenerator();
    }

    public function testScalars(): void
    {
        $this->assertSame("'hello'", $this->gen->valueToTwig('hello'));
        $this->assertSame('true', $this->gen->valueToTwig(true));
        $this->assertSame('false', $this->gen->valueToTwig(false));
        $this->assertSame('42', $this->gen->valueToTwig(42));
        $this->assertSame('null', $this->gen->valueToTwig(null));
    }

    public function testFloats(): void
    {
        $this->assertSame('1.5', $this->gen->valueToTwig(1.5));
        $this->assertSame('2.0', $this->gen->valueToTwig(2.0));
        $this->assertSame('0.25', $this->gen->valueToTwig(0.25));
    }

    public function testEscapingQuotesAndBackslashes(): void
    {
        $this->assertSame("'it\\'s'", $this->gen->valueToTwig("it's"));
        $this->assertSame("'a\\\\b'", $this->gen->valueToTwig('a\\b'));
    }

    public function testList(): void
    {
        $this->assertSame("['a', 'b', 'c']", $this->gen->valueToTwig(['a', 'b', 'c']));
        $this->assertSame('[1, 2, 3]', $this->gen->valueToTwig([1, 2, 3]));
    }

    public function testAssociativeArray(): void
    {
        $this->assertSame(
            "{ label: 'Save', disabled: false }",
            $this->gen->valueToTwig(['label' => 'Save', 'disabled' => false])
        );
    }

    public function testNestedArrays(): void
    {
        $value = ['items' => ['Home', 'Shop'], 'meta' => ['count' => 2]];
        $this->assertSame(
            "{ items: ['Home', 'Shop'], meta: { count: 2 } }",
            $this->gen->valueToTwig($value)
        );
    }

    public function testNonIdentifierKeysAreQuoted(): void
    {
        $this->assertSame("{ 'data-id': 1 }", $this->gen->valueToTwig(['data-id' => 1]));
    }

    public function testFullSnippetMultiline(): void
    {
        $snippet = $this->gen->generate('_components/button/button.twig', [
            'label' => 'Save',
            'variant' => 'primary',
            'disabled' => false,
        ]);

        $expected = "{% include '_components/button/button.twig' with {\n"
            . "    label: 'Save',\n"
            . "    variant: 'primary',\n"
            . "    disabled: false\n"
            . "} only %}";

        $this->assertSame($expected, $snippet);
    }

    public function testEmptyArgsOmitsWith(): void
    {
        $this->assertSame(
            "{% include '_components/x.twig' only %}",
            $this->gen->generate('_components/x.twig', [])
        );
    }
}
