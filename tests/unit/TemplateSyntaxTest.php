<?php

declare(strict_types=1);

namespace bensomething\speakeasy\tests\unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Error\SyntaxError;
use Twig\Loader\ArrayLoader;
use Twig\Source;

/**
 * Craft renders these, not the suite, so a syntax error in one passes ECS,
 * PHPStan and every other test and surfaces only on the page it breaks.
 *
 * Lexing is as far as this goes. It proves the tags and expressions are
 * well-formed, which is the failure mode worth guarding (a `{# #}` comment
 * placed inside a `{{ }}` expression reads as an unclosed brace, for one).
 * Parsing would also check tag names, but Craft registers its own (`namespace`,
 * `tag`, `nav`), so it would report those as unknown.
 */
final class TemplateSyntaxTest extends TestCase
{
    #[DataProvider('templates')]
    public function testTheTemplateIsWellFormed(string $path): void
    {
        $name = basename($path);
        $twig = new Environment(new ArrayLoader());

        try {
            $twig->tokenize(new Source((string) file_get_contents($path), $name));
        } catch (SyntaxError $e) {
            self::fail("$name: {$e->getMessage()}");
        }

        $this->addToAssertionCount(1);
    }

    /** @return array<string, array{string}> */
    public static function templates(): array
    {
        $paths = glob(dirname(__DIR__, 2) . '/src/templates/*.twig') ?: [];

        $cases = [];
        foreach ($paths as $path) {
            $cases[basename($path)] = [$path];
        }

        return $cases;
    }
}
