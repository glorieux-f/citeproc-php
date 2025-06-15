<?php
/*
 * citeproc-php
 *
 * @link        http://github.com/seboettg/citeproc-php for the source repository
 * @copyright   Copyright (c) 2016 Sebastian Böttger.
 * @license     https://opensource.org/licenses/MIT
 */

namespace Seboettg\CiteProc\Styles;

use SimpleXMLElement;

/**
 * Trait DelimiterTrait
 * @package Seboettg\CiteProc\Styles
 * @author Sebastian Böttger <seboettg@gmail.com>
 */
trait DelimiterTrait
{

    /**
     * @param SimpleXMLElement $node
     */
    protected function initDelimiterAttributes(SimpleXMLElement $node)
    {
        $this->delimiter = (string)$node->attributes()->delimiter;
    }
}
