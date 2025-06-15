<?php
/*
 * citeproc-php
 *
 * @link        http://github.com/seboettg/citeproc-php for the source repository
 * @copyright   Copyright (c) 2016 Sebastian Böttger.
 * @license     https://opensource.org/licenses/MIT
 */

namespace Seboettg\CiteProc\Rendering;

use SimpleXMLElement;
use stdClass;
use Seboettg\CiteProc\CiteProc;
use Seboettg\CiteProc\Exception\CiteProcException;
use Seboettg\CiteProc\RenderingState;
use Seboettg\CiteProc\Styles\AffixesTrait;
use Seboettg\CiteProc\Styles\ConsecutivePunctuationCharacterTrait;
use Seboettg\CiteProc\Styles\DisplayTrait;
use Seboettg\CiteProc\Styles\FormattingTrait;
use Seboettg\CiteProc\Styles\QuotesTrait;
use Seboettg\CiteProc\Styles\TextCaseTrait;
use Seboettg\CiteProc\Terms\Locator;
use Seboettg\CiteProc\Util\CiteProcHelper;
use Seboettg\CiteProc\Util\NumberHelper;
use Seboettg\CiteProc\Util\PageHelper;
use Seboettg\CiteProc\Util\StringHelper;
use function Seboettg\CiteProc\ucfirst;

/**
 * Class Term
 *
 * @package Seboettg\CiteProc\Node\Style
 *
 * @author Sebastian Böttger <seboettg@gmail.com>
 */
class Text implements Rendering
{
    use FormattingTrait,
        AffixesTrait,
        TextCaseTrait,
        DisplayTrait,
        ConsecutivePunctuationCharacterTrait,
        QuotesTrait;

    private $renderFunction = [];

    /**
     * Render HTML-style markup in rich text fields, see CSL documentation:
     * https://citeproc-js.readthedocs.io/en/latest/csl-json/markup.html#html-like-formatting-tags
     * 
     * Adaptation of Zotero CSL processor, with simplifications:
     * https://github.com/zotero/zotero/blob/408f1274f4d98b72204393dd1392d71d6e7d507e/chrome/content/zotero/xpcom/utilities_internal.js#L2448C1-L2565C3
     * - Inverse styles are not implemented (should be handled by CSS).
     * - Only allowed tags and attributes are retained; others are escaped.
     * 
     * Example title:
     * "Read <i><i>Laissez-Faire</i> Banking</i> in the <span style=\"font-variant:small-caps;\">xxi</span><sup>st</sup>"
     * 
     * Implemented as a custom Lambda Function.
     * 
     * @param stdClass $data ? optional, possible $data context, not fully relevant
     * @param string $text ! required, text to output
     * @return string Safe HTML string with allowed tags and attributes
     */
    public static function renderTextRich($data, $text)
    {
        static $ENT_FLAGS = ENT_SUBSTITUTE;
        static $allowedTags = [
            'b' => [],
            'i' => [],
            'span' => [
                'style' => 'font-variant:small-caps;',
                'class' => 'nocase',
            ],
            'sub' => [],
            'sup' => [],
        ];

        // Remove ASCII control characters 0-31 and 127 (DEL)
        $text = preg_replace('/[\x00-\x1F\x7F]/', '', $text);

        // Fast path: no tags
        if (strpos($text, "<") === false) {
            return htmlspecialchars($text, $ENT_FLAGS, 'UTF-8');
        }

        // Tokenize tags and keep offsets
        preg_match_all('/<(\/)?([^ >]+)([^>]*)?>/', $text, $tagMatches, PREG_OFFSET_CAPTURE);

        $tagStack = [];
        $output = '';
        $lastPos = 0;
        $count = count($tagMatches[0]);

        for ($i = 0; $i < $count; $i++) {
            $match = $tagMatches[0][$i][0];
            $matchPos = $tagMatches[0][$i][1];

            // Output text before this tag
            if ($matchPos > $lastPos) {
                $output .= htmlspecialchars(substr($text, $lastPos, $matchPos - $lastPos), $ENT_FLAGS, 'UTF-8');
            }
            $lastPos = $matchPos + strlen($match);

            $tagName = strtolower($tagMatches[2][$i][0]);
            $isClosing = $tagMatches[1][$i][0];

            if (!array_key_exists($tagName, $allowedTags)) {
                // Not an allowed tag, escape
                $output .= htmlspecialchars($match, $ENT_FLAGS, 'UTF-8');
                continue;
            }

            if ($isClosing) {
                // Only close if it matches the last open tag
                if (end($tagStack) === $tagName) {
                    array_pop($tagStack);
                    $output .= "</$tagName>";
                } else {
                    // Malformed nesting: escape
                    $output .= htmlspecialchars($match, $ENT_FLAGS, 'UTF-8');
                }
                continue;
            }

            // Allowed opening tag. Check attributes if any required.
            $allowedAttrs = $allowedTags[$tagName];
            if (!$allowedAttrs) {
                // No attributes needed or allowed
                $output .= "<$tagName>";
                $tagStack[] = $tagName;
                continue;
            }

            // Parse attributes in tag
            $attrString = isset($tagMatches[3][$i][0]) ? $tagMatches[3][$i][0] : '';
            if ($attrString === '') {
                $output .= htmlspecialchars($match, $ENT_FLAGS, 'UTF-8');
                continue;
            }
            preg_match_all('/([a-zA-Z_:][a-zA-Z0-9_\-.:]*)\s*=\s*([\'"])(.*?)\2/', $attrString, $attrMatches, PREG_SET_ORDER);

            $found = false;
            foreach ($attrMatches as $m) {
                $attrName = strtolower($m[1]);
                if (!array_key_exists($attrName, $allowedAttrs)) continue;
                // attribute value may be in uppercase because of text-case
                $attrValue = mb_strtolower(trim($m[3]), "UTF-8");
                // For style attribute: normalize spacing, trailing semicolon
                if ($attrName === 'style') {
                    $attrValue = preg_replace('/\s+|;$/', '', $attrValue) . ';';
                }
                if ($attrValue !== $allowedAttrs[$attrName]) continue;
                $found = true;
                $output .= "<$tagName $attrName=\"{$allowedAttrs[$attrName]}\">";
                $tagStack[] = $tagName;
                break;
            }
            if (!$found) {
                $output .= htmlspecialchars($match, $ENT_FLAGS, 'UTF-8');
            }
        }

        // Output trailing text
        if ($lastPos < strlen($text)) {
            $output .= htmlspecialchars(substr($text, $lastPos), $ENT_FLAGS, 'UTF-8');
        }

        // Close any remaining open tags
        while ($tagStack) {
            $output .= '</' . array_pop($tagStack) . '>';
        }

        return $output;
    }

    /**
     * Default text rendering, escaped.
     * 
     * @param stdClass $data ? optional, possible $data context, not fully relevant
     * @param string $text ! required, text to output
     * @return string Safe HTML string with allowed tags and attributes
     */
    public static function renderTextEscaped($data, $text)
    {
        return StringHelper::clearApostrophes (
            htmlspecialchars($text, ENT_HTML5)
        );        
    }

    /**
     * @var string
     */
    private $toRenderType;

    /**
     * @var string
     */
    private $toRenderTypeValue;

    /**
     * @var string
     */
    private $form = "long";

    /**
     * Text constructor.
     *
     * @param SimpleXMLElement $node
     */
    public function __construct(SimpleXMLElement $node)
    {
        static $attrsMap = array_flip(['value', 'variable', 'macro', 'term']);
        foreach ($node->attributes() as $attribute) {
            $name = $attribute->getName();
            if (!isset($attrsMap[$name])) continue;
            $this->toRenderType = $name;
            $this->toRenderTypeValue = (string) $attribute;
            if ($name === "form") {
                $this->form = (string) $attribute;
            }
        }
        // find the render function of text
        // for performances, the possible functions are set at compile time of the CSL schema
        $this->renderFunction['bibliography'] = CiteProcHelper::getAdditionMarkupFunction('text', 'bibliography');
        $this->renderFunction['citation'] = CiteProcHelper::getAdditionMarkupFunction('text', 'citation');
        $this->renderFunction['default'] = CiteProcHelper::getAdditionMarkupFunction('text');
        if (!is_callable($this->renderFunction['default'])) $this->renderFunction['default'] = [__CLASS__,'renderTextEscaped'];
        if (!is_callable($this->renderFunction['bibliography'])) $this->renderFunction['bibliography'] = $this->renderFunction['default'];
        if (!is_callable($this->renderFunction['citation'])) $this->renderFunction['citation'] = $this->renderFunction['default'];

        $this->initFormattingAttributes($node);
        $this->initDisplayAttributes($node);
        $this->initTextCaseAttributes($node);
        $this->initAffixesAttributes($node);
        $this->initQuotesAttributes($node);

    }

    /**
     * @param  stdClass $data
     * @param  int|null $citationNumber
     * @return string
     */
    public function render($data, $citationNumber = null)
    {
        $lang = (isset($data->language)) ? 
            $data->language : 
            strtok(CiteProc::getContext()->getLocale()->getLanguage(), '-');

        $renderedText = "";
        $type = $this->toRenderTypeValue;
        switch ($this->toRenderType) {
            case 'value':
                $renderedText = $this->applyTextCase($type, $lang);
                break;
            case 'variable':
                if ($type === "locator" && CiteProc::getContext()->isModeCitation()) {
                    $renderedText = $this->renderLocator($data, $citationNumber);
                // for test sort_BibliographyCitationNumberDescending.json
                } elseif ($type === "citation-number") {
                    $renderedText = $this->renderCitationNumber($data, $citationNumber);
                    break;
                } elseif ($type == "page" || $type == "chapter-number" || $type == "folio") {
                    $renderedText = !empty($data->{$type}) ? $this->renderPage($data->{$type}) : '';
                } else {
                    $renderedText = $this->renderVariable($data, $lang);
                }
                if (CiteProc::getContext()->getRenderingState()->getValue() === RenderingState::SUBSTITUTION) {
                    unset($data->{$type});
                }
                if (!CiteProcHelper::isUsingAffixesByMarkupExtentsion($data, $type)) {
                    $renderedText = $this->applyAdditionalMarkupFunction($data, $renderedText);
                }
                break;
            case 'macro':
                $renderedText = $this->renderMacro($data);
                break;
            case 'term':
                $term = CiteProc::getContext()
                    ->getLocale()
                    ->filter("terms", $type, $this->form)
                    ->single;
                $renderedText = !empty($term) ? $this->applyTextCase($term, $lang) : "";
        }
        if (!empty($renderedText)) {
            $renderedText = $this->formatRenderedText($data, $renderedText);
        }
        return $renderedText;
    }

    /**
     * @return string
     */
    public function getSource()
    {
        return $this->toRenderType;
    }

    /**
     * @return string
     */
    public function getVariable()
    {
        return $this->toRenderTypeValue;
    }

    private function renderPage($page)
    {
        if (preg_match(NumberHelper::PATTERN_COMMA_AMPERSAND_RANGE, $page)) {
            $page = $this->normalizeDateRange($page);
            $ranges = preg_split("/[-–]/", trim($page));
            if (count($ranges) > 1) {
                if (!empty(CiteProc::getContext()->getGlobalOptions())
                    && !empty(CiteProc::getContext()->getGlobalOptions()->getPageRangeFormat())
                ) {
                    return PageHelper::processPageRangeFormats(
                        $ranges,
                        CiteProc::getContext()->getGlobalOptions()->getPageRangeFormat()
                    );
                }
                list($from, $to) = $ranges;
                return $from . "–" . $to;
            }
        }
        return $page;
    }

    private function renderLocator($data, $citationNumber)
    {
        $citationItem = CiteProc::getContext()->getCitationItemById($data->id);
        if (!empty($citationItem->label)) {
            $locatorData = new stdClass();
            $propertyName = Locator::mapLocatorLabelToRenderVariable($citationItem->label);
            $locatorData->{$propertyName} = trim($citationItem->locator);
            $renderTypeValueTemp = $this->toRenderTypeValue;
            $this->toRenderTypeValue = $propertyName;
            $result = $this->render($locatorData, $citationNumber);
            $this->toRenderTypeValue = $renderTypeValueTemp;
            return $result;
        }
        return isset($citationItem->locator) ? trim($citationItem->locator) : '';
    }

    private function normalizeDateRange($page)
    {
        if (preg_match("/^(\d+)\s?--?\s?(\d+)$/", trim($page), $matches)) {
            return $matches[1]."-".$matches[2];
        }
        return $page;
    }

    /**
     * @param  $data
     * @param  $renderedText
     * @return mixed
     */
    private function applyAdditionalMarkupFunction($data, $renderedText)
    {
        return CiteProcHelper::applyAdditionMarkupFunction($data, $this->toRenderTypeValue, $renderedText);
    }

    /**
     * @param  $data
     * @param  $lang
     * @return string
     */
    private function renderVariable($data, $lang)
    {
        // check if there is an attribute with prefix short or long e.g. shortTitle or longAbstract
        // test case group_ShortOutputOnly.json
        $value = "";
        $form = $this->form;
        if ($form == "short" || $form == "long") {
            $attrWithPrefix = $form . ucfirst($this->toRenderTypeValue);
            $attrWithSuffix = $this->toRenderTypeValue . "-" . $form;
            if (isset($data->{$attrWithPrefix}) && !empty($data->{$attrWithPrefix})) {
                $value = $data->{$attrWithPrefix};
            } else {
                if (isset($data->{$attrWithSuffix}) && !empty($data->{$attrWithSuffix})) {
                    $value = $data->{$attrWithSuffix};
                } else {
                    if (isset($data->{$this->toRenderTypeValue})) {
                        $value = $data->{$this->toRenderTypeValue};
                    }
                }
            }
        } else {
            if (!empty($data->{$this->toRenderTypeValue})) {
                $value = $data->{$this->toRenderTypeValue};
            }
        }
        if (is_array($value)) {
            // How inform user here that he’s provinding unexpected data? #184
            $value = implode(" ", $value);
        }
        if (empty($value) || trim($value) == "") return $value;
        // apply text case before function for escaping tags
        $value = $this->applyTextCase($value, $lang);
        if (CiteProc::getContext()->isModeBibliography()) {
            return $this->renderFunction['bibliography']($data, $value);
        }
        else if (CiteProc::getContext()->isModeCitation()) {
            return $this->renderFunction['citation']($data, $value);
        }
        else {
            return $this->renderFunction['default']($data, $value);
        }
    }

    /**
     * @param  $data
     * @param  $renderedText
     * @return string
     */
    private function formatRenderedText($data, $renderedText)
    {
        $text = $this->format($renderedText);
        $res = $this->addAffixes($text);
        if (CiteProcHelper::isUsingAffixesByMarkupExtentsion($data, $this->toRenderTypeValue)) {
            $res = $this->applyAdditionalMarkupFunction($data, $res);
        }
        if (!empty($res)) {
            $res = $this->removeConsecutiveChars($res);
        }
        $res = $this->addSurroundingQuotes($res);
        return $this->wrapDisplayBlock($res);
    }

    /**
     * @param  $data
     * @param  $citationNumber
     * @return int|mixed
     */
    private function renderCitationNumber($data, $citationNumber)
    {
        $renderedText = $citationNumber + 1;
        if (!CiteProcHelper::isUsingAffixesByMarkupExtentsion($data, $this->toRenderTypeValue)) {
            $renderedText = $this->applyAdditionalMarkupFunction($data, $renderedText);
        }
        return $renderedText;
    }

    /**
     * @param  $data
     * @return string
     */
    private function renderMacro($data)
    {
        $macro = CiteProc::getContext()->getMacro($this->toRenderTypeValue);
        if (is_null($macro)) {
            try {
                throw new CiteProcException("Macro \"".$this->toRenderTypeValue."\" does not exist.");
            } catch (CiteProcException $e) {
                $renderedText = "";
            }
        } else {
            $renderedText = $macro->render($data);
        }
        return $renderedText;
    }
}
