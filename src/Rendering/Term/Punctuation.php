<?php

namespace Seboettg\CiteProc\Rendering\Term;

use ReflectionClass;
use Seboettg\CiteProc\CiteProc;

class Punctuation
{
    public const OPEN_QUOTE = "open-quote";
    public const CLOSE_QUOTE = "close-quote";
    public const OPEN_INNER_QUOTE = "open-inner-quote";
    public const CLOSE_INNER_QUOTE = "close-inner-quote";
    public const PAGE_RANGE_DELIMITER = "page-range-delimiter";
    public const COLON = "colon";
    public const COMMA = "comma";
    public const SEMICOLON = "semicolon";
    /** Default punctuation array to clone  */
    private static array $_PUN = [
        self::OPEN_QUOTE => "“",
        self::CLOSE_QUOTE => "”",
        self::OPEN_INNER_QUOTE => "‘",
        self::CLOSE_INNER_QUOTE => "’",
        self::PAGE_RANGE_DELIMITER => "-",
        self::COLON => ":",
        self::COMMA => ",",
        self::SEMICOLON => ";",
    ];
    /** last language requested */
    private static $language;
    /** Punctuation cached by language, with default values*/
    private static $punMap;


    private static function cachePun() {
        if (!isset($punMap)) $punMap = self::$_PUN;
        static $locale = CiteProc::getContext()->getLocale();
        $language = $locale->getLanguage();
        if ($language === self::$language) return;
        self::$language = $language;
        self::$punMap = self::$_PUN;
        $oClass = new ReflectionClass(__CLASS__);
        foreach($oClass->getConstants() as $const => $localeKey) {
            if ($const[0] == '_') continue;
            $pun = $locale->filter("terms", $localeKey)->single;
            if ($pun !== null) self::$punMap[$localeKey] = $pun;
        }
    }
 
    public static function isPunctuation(String $chars): bool
    {
        self::cachePun();
        return isset(self::$punMap[trim($chars)]); 
    }
}
