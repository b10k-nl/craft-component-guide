<?php

namespace b10k\componentguide\preview;

use Twig\Markup;

/**
 * A rich-text (CKEditor, Redactor) value. Craft hands these to templates as
 * markup that prints unescaped, so `{{ block.bodyText }}` outputs HTML — a
 * plain string from a story would print its tags as text instead. That is what
 * the first run on a real-style template showed: `<p>` on the card.
 *
 * Twig marks Markup `@final` in a docblock only. Craft's own rich-text value
 * (craft\htmlfield\HtmlFieldData) extends it all the same, because extending it
 * is the one way to print unescaped while still answering getRawContent() —
 * and the stand-in has to be what the template would really be handed. The
 * PHPStan exception for it is in phpstan.neon.
 */
final class StoryRichText extends Markup
{
    public function __construct(private string $html)
    {
        parent::__construct($html, 'UTF-8');
    }

    public function getRawContent(): string
    {
        return $this->html;
    }

    public function getParsedContent(): string
    {
        return $this->html;
    }
}
