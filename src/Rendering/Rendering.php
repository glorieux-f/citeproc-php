<?php
/*
 * citeproc-php
 *
 * @link        http://github.com/seboettg/citeproc-php for the source repository
 * @copyright   Copyright (c) 2016 Sebastian Böttger.
 * @license     https://opensource.org/licenses/MIT
 */

namespace Seboettg\CiteProc\Rendering;

use stdClass;
use Seboettg\CiteProc\Data\DataList;

/**
 * Interface RenderingInterface
 *
 * Defines "render" function.
 *
 * @package Seboettg\CiteProc\Rendering
 */
interface Rendering
{

    /**
     * @param array|DataList|stdClass $data
     * @param null|int $citationNumber
     * @return string
     */
    public function render($data, $citationNumber);
}
