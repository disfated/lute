<?php

namespace App\Parse;

use App\Entity\Language;
use App\Parse\ParsedToken;

class SpaceDelimitedParser extends AbstractParser {

    public function getParsedTokens(string $text, Language $lang) {
        return $this->parse_to_tokens($text, $lang);
    }

    /**
     * Returns array of matches in same format as preg_match or
     * preg_match_all
     * @param string $pattern  The pattern to search for, as a string.
     * @param string $subject  The input string.
     * @return array
     *
     * Ref https://stackoverflow.com/questions/1725227/preg-match-and-utf-8-in-php
     */
    private function pregMatchCapture($pattern, $subject)
    {
        $matchInfo = array();
        $flag      = PREG_OFFSET_CAPTURE;

        // var_dump([$method, $pattern, $subject, $matchInfo, $flag, $offset]);
        $offset = 0;
        $n = preg_match_all($pattern, $subject, $matchInfo, $flag, $offset);

        $result = array();
        if ($n !== 0 && !empty($matchInfo)) {
            foreach ($matchInfo as $matches) {
                $positions = array();
                foreach ($matches as $match) {
                    $matchedText   = $match[0];
                    $matchedLength = $match[1];
                    // dump($subject);
                    $positions[]   = array(
                        $matchedText,
                        mb_strlen(mb_strcut($subject, 0, $matchedLength))
                    );
                }
                $result[] = $positions;
            }
        }
        return $result;
    }

    private function parse_to_tokens(string $text, Language $lang) {

        $replace = explode("|", $lang->getLgCharacterSubstitutions());
        foreach ($replace as $value) {
            $fromto = explode("=", trim($value));
            if (count($fromto) >= 2) {
                $rfrom = trim($fromto[0]);
                $rto = trim($fromto[1]);
                $text = str_replace($rfrom, $rto, $text);
            }
        }

        $text = str_replace("\r\n",  "\n", $text);
        $text = str_replace('{', '[', $text);
        $text = str_replace('}', ']', $text);

        $tokens = [];
        $paras = explode("\n", $text);
        $pcount = count($paras);
        for ($i = 0; $i < $pcount; $i++) {
            $para = $paras[$i];
            $this->parse_para($para, $lang, $tokens);
            if ($i != ($pcount - 1))
                $tokens[] = new ParsedToken('¶', false, true);
        }

        return $tokens;
    }

    private function parse_para(string $text, Language $lang, &$tokens) {

        $termchar = $lang->getLgRegexpWordCharacters();
        $splitSentence = preg_quote($lang->getLgRegexpSplitSentences());
        $splitex = str_replace('.', '\\.', $lang->getLgExceptionsSplitSentences());
        $m = $this->pregMatchCapture("/($splitex|[$termchar]*)/ui", $text);
        $wordtoks = array_filter($m[0], fn($t) => $t[0] != "");
        // dump($text);
        // dump($termchar . '    ' . $splitex);
        // dump($m);
        // dump($wordtoks);

        $addNonWords = function($s) use (&$tokens, $splitSentence) {
            if ($s == "")
                return;
            // dump("ADDING NON WORDS $s");
            $pattern = '/[' . $splitSentence . ']/ui';
            $allmatches = $this->pregMatchCapture($pattern, $s);
            $hasEOS = count($allmatches) > 0;
            $tokens[] = new ParsedToken($s, false, $hasEOS);
        };

        $pos = 0;
        foreach ($wordtoks as $wt) {
            // dump("handle token " . $wt[0]);
            $w = $wt[0];
            $wp = $wt[1];

            // stuff before
            $s = mb_substr($text, $pos, $wp - $pos);
            $addNonWords($s);

            // the word
            $tokens[] = new ParsedToken($w, true, false);

            $pos = $wp + mb_strlen($w);
        }
        // Get part after last, if any.
        $s = mb_substr($text, $pos);
        $addNonWords($s);
        // dump($tokens);

        return;
    }
}