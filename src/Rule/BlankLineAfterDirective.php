<?php

declare(strict_types=1);

/*
 * This file is part of DOCtor-RST.
 *
 * (c) Oskar Stark <oskarstark@googlemail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Rule;

use App\Annotations\Rule\Description;
use App\Rst\RstParser;
use App\Value\Lines;
use App\Value\RuleGroup;

/**
 * @Description("Make sure you have a blank line after each directive.")
 */
class BlankLineAfterDirective extends AbstractRule implements LineContentRule
{
    public static function getGroups(): array
    {
        return [
            RuleGroup::Sonata(),
            RuleGroup::Symfony(),
        ];
    }

    public function check(Lines $lines, int $number): ?string
    {
        $lines->seek($number);
        $line = $lines->current();

        if (!$line->isDirective()) {
            return null;
        }

        foreach (self::unSupportedDirectives() as $type) {
            if (RstParser::directiveIs($line, $type)) {
                return null;
            }
        }

        $lines->next();

        while ($lines->valid() && RstParser::isOption($lines->current())) {
            $lines->next();
        }

        if (!$lines->valid() || !$lines->current()->isBlank()) {
            return sprintf('Please add a blank line after "%s" directive', $line->raw()->toString());
        }

        return null;
    }

    /**
     * @return array<int, string>
     */
    public static function unSupportedDirectives(): array
    {
        return [
            RstParser::DIRECTIVE_INDEX,
            RstParser::DIRECTIVE_TOCTREE,
            RstParser::DIRECTIVE_INCLUDE,
            RstParser::DIRECTIVE_IMAGE,
            RstParser::DIRECTIVE_ADMONITION,
            RstParser::DIRECTIVE_ROLE,
            RstParser::DIRECTIVE_FIGURE,
            RstParser::DIRECTIVE_CLASS,
            RstParser::DIRECTIVE_RST_CLASS,
            RstParser::DIRECTIVE_CONTENTS,
            RstParser::DIRECTIVE_CODEIMPORT,
        ];
    }
}
