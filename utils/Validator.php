<?php

class Validator {
    public static function validateUrl($url) {
        $url = trim($url);
        
        // Add scheme if missing
        if (!preg_match("~^(?:f|ht)tps?://~i", $url)) {
            $url = "https://" . $url;
        }

        if (filter_var($url, FILTER_VALIDATE_URL) === FALSE) {
            return false;
        }
        
        return $url;
    }

    public static function sanitize($input) {
        return htmlspecialchars(strip_tags(trim($input)), ENT_QUOTES, 'UTF-8');
    }
}
