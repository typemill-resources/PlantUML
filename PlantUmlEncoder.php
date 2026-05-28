<?php
/*
 * SPDX-License-Identifier: MIT
 *
 * This file includes code from:
 * https://github.com/jawira/plantuml-encoding
 *
 * Copyright (c) Jawira
 */
 
namespace plugins\plantuml;

/**
 * Class PlantUmlEncoder
 * 
 * Provides an internal encoder to translate raw PlantUML strings into 
 * Deflate + Base64-like encoded strings compatible with the external PlantUML server API.
 * 
 * This class translates ASCII strings to the custom 6-bit Base64-like alphabet 
 * required exclusively by PlantUML servers.
 */
class PlantUmlEncoder
{
    /**
     * Encodes a standard text string (PlantUML markup) into the custom URL format.
     * Starts by compressing the string with Deflate algorithm (highest compression level 9).
     * 
     * @param string $text The original PlantUML code string.
     * @return string The compressed and encoded string.
     */
    public function encode($text)
    {
        $compressed = gzdeflate($text, 9);
        return $this->encode64($compressed);
    }

    /**
     * Converts a binary payload (deflated text) into the PlantUML character set.
     * It splits the binary data into 3-byte groups to parse them into 4 Base64-like characters.
     * 
     * @param string $c The compressed binary string.
     * @return string The mapped PlantUML string representation.
     */
    private function encode64($c)
    {
        $str = "";
        $len = strlen($c);
        
        // Loop over the binary string in blocks of 3 bytes
        for ($i = 0; $i < $len; $i += 3) {
            if ($i + 2 == $len) {
                // If only 2 bytes remain, pad the third byte with 0
                $str .= $this->append3bytes(ord(substr($c, $i, 1)), ord(substr($c, $i + 1, 1)), 0);
            } elseif ($i + 1 == $len) {
                // If only 1 byte remains, pad the second and third bytes with 0
                $str .= $this->append3bytes(ord(substr($c, $i, 1)), 0, 0);
            } else {
                // Full 3 bytes block available
                $str .= $this->append3bytes(ord(substr($c, $i, 1)), ord(substr($c, $i + 1, 1)), ord(substr($c, $i + 2, 1)));
            }
        }
        return $str;
    }

    /**
     * Executes bitwise shifts across 3 bytes (24 bits) to extract 4 values of 6 bits each.
     * 
     * @param int $b1 Byte 1
     * @param int $b2 Byte 2
     * @param int $b3 Byte 3
     * @return string Extracted 4-character mapped string segment.
     */
    private function append3bytes($b1, $b2, $b3)
    {
        $c1 = $b1 >> 2;
        $c2 = (($b1 & 0x3) << 4) | ($b2 >> 4);
        $c3 = (($b2 & 0xF) << 2) | ($b3 >> 6);
        $c4 = $b3 & 0x3F;
        
        $r = "";
        $r .= $this->encode6bit($c1 & 0x3F);
        $r .= $this->encode6bit($c2 & 0x3F);
        $r .= $this->encode6bit($c3 & 0x3F);
        $r .= $this->encode6bit($c4 & 0x3F);
        
        return $r;
    }

    /**
     * Maps a 6-bit integer (range 0-63) to a specific ASCII character sequence 
     * designated by the official PlantUML encoding specification.
     * 
     * Mappings:
     * - (0-9)   = '0'-'9'
     * - (10-35) = 'A'-'Z'
     * - (36-61) = 'a'-'z'
     * - 62      = '-'
     * - 63      = '_'
     *
     * @param int $b 6-bit target digit
     * @return string Character equivalent
     */
    private function encode6bit($b)
    {
        // 0-9
        if ($b < 10) {
            return chr(48 + $b);
        }
        $b -= 10;
        
        // A-Z
        if ($b < 26) {
            return chr(65 + $b);
        }
        $b -= 26;
        
        // a-z
        if ($b < 26) {
            return chr(97 + $b);
        }
        $b -= 26;
        
        // Final two digits
        if ($b == 0) {
            return '-';
        }
        if ($b == 1) {
            return '_';
        }
        
        // Fallback for illegal bounds
        return '?';
    }
}
