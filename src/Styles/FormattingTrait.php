<?php
/*
 * citeproc-php
 *
 * @link        http://github.com/seboettg/citeproc-php for the source repository
 * @copyright   Copyright (c) 2016 Sebastian Böttger.
 * @license     https://opensource.org/licenses/MIT
 */

namespace Seboettg\CiteProc\Styles;

use Seboettg\CiteProc\Util\CiteProcHelper;
use Seboettg\Collection\ArrayList;
use SimpleXMLElement;

/**
 * Trait FormattingTrait
 * @package Seboettg\CiteProc\Styles
 * @author Sebastian Böttger <seboettg@gmail.com>
 */
trait FormattingTrait
{
    private static $formatters;

    private static function init()
    {
        // initialize static default formatters
        // https://docs.citationstyles.org/en/stable/specification.html#formatting
        self::$formatters = [
            "bold" => function($data, $text) {
                return "<b>$text</b>";
            },
            "italic" => function($data, $text) {
                return "<i>$text</i>";
            },
            "light" => function($data, $text) {
                return "<span style=\"font-weight:200\">$text</b>";
            },
            "oblique" => function($data, $text) {
                return "<i>$text</i>";
            },
            "small-caps" => function($data, $text) {
                return "<span style=\"font-variant: small-caps\">$text</span>";
            },
            "sub" => function($data, $text) {
                return "<sub>$text</sub>";
            },
            "sup" => function($data, $text) {
                return "<sup>$text</sup>";
            },
            "underline" => function($data, $text) {
                return "<u>$text</u>";
            },
        ];

    }

    /**
     * A possible formatter for superscript in plain text
     */
    public static function superUnicode($data, $text) {
        $superscript_map = [
            '0' => '⁰', '1' => '¹', '2' => '²', '3' => '³',
            '4' => '⁴', '5' => '⁵', '6' => '⁶', '7' => '⁷',
            '8' => '⁸', '9' => '⁹',
            'a' => 'ᵃ', 'b' => 'ᵇ', 'c' => 'ᶜ', 'd' => 'ᵈ',
            'e' => 'ᵉ', 'é' => 'ᵉ', 'è' => 'ᵉ',
            'f' => 'ᶠ', 'g' => 'ᵍ', 'h' => 'ʰ',
            'i' => 'ⁱ', 'j' => 'ʲ', 'k' => 'ᵏ', 'l' => 'ˡ',
            'm' => 'ᵐ', 'n' => 'ⁿ', 'o' => 'ᵒ', 'p' => 'ᵖ',
            'r' => 'ʳ', 's' => 'ˢ', 't' => 'ᵗ', 'u' => 'ᵘ',
            'v' => 'ᵛ', 'w' => 'ʷ', 'x' => 'ˣ', 'y' => 'ʸ',
            'z' => 'ᶻ',
            'A' => 'ᴬ', 'B' => 'ᴮ', 'D' => 'ᴰ', 'E' => 'ᴱ',
            'G' => 'ᴳ', 'H' => 'ᴴ', 'I' => 'ᴵ', 'J' => 'ᴶ',
            'K' => 'ᴷ', 'L' => 'ᴸ', 'M' => 'ᴹ', 'N' => 'ᴺ',
            'O' => 'ᴼ', 'P' => 'ᴾ', 'R' => 'ᴿ', 'T' => 'ᵀ',
            'U' => 'ᵁ', 'V' => 'ⱽ', 'W' => 'ᵂ',
            '+' => '⁺', '-' => '⁻', '=' => '⁼', '(' => '⁽',
            ')' => '⁾'
        ];
        return strtr($text, $superscript_map);
    }

    /**
     * @var array
     */
    private static $formattingAttributes = [
        'class' => true, // not in csl schema, a css class name
        // 'font-family' => true, // not in CSL schema, was in code, 
        'font-style' => ["italic", "oblique"], // default: normal
        'font-variant' => ["small-caps"], // default: normal
        'font-weight' => ["bold", "light"], // default: normal
        'text-decoration' => ["underline"], // default: none 
        'vertical-align' => ["sub", "sup"], // default: baseline
    ];

    /**
     * @var ArrayList
     */
    private $formatSteps = [];

    /**
     * @var bool
     */
    private $stripPeriods = false;

    /**
     * @var string
     */
    private $format;

    /**
     * Compile formatting attributes as a list of functions to perform on text.
     * 
     * @param SimpleXMLElement $node
     */
    protected function initFormattingAttributes(SimpleXMLElement $node)
    {
        // ensure static init
        if (self::$formatters == null) self::init();
        // loop on attributes to find formating information
        foreach ($node->attributes() as $attribute) {
            $name = (string) $attribute->getName();
            $value = (string) $attribute;
            if (!array_key_exists($name, self::$formattingAttributes)) continue;
            $function = CiteProcHelper::getAdditionMarkupFunction($value);
            echo "fun: $value " . is_callable($function) . "\n";
            // if no user defined function, use default html
            if ($function == null) $function = self::$formatter[$value] ?? null;

            if ($function == null && $name == 'class') {
                $function = function($data, $text) use($value) {
                    return "<span class=\"$value\">$text</span>";
                };
            }
            if (is_callable($function)) array_push($this->formatSteps, $function);
        }
    }


    /**
     * Format text according to the compiled list of function to apply.
     */
    protected function format($text, $data=null)
    {
        // do not style spaces
        if (trim($text) == '') return $text;
        // Apply rendering function program
        foreach ($this->formatSteps as $function) {
            $text = $function($data, $text);
        }
        return $text;
    }
}


