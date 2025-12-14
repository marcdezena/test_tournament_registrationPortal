<?php
/**
 * Rate Limiter
 * 
 * Provides rate limiting functionality to prevent abuse of the system.
 */

function checkRateLimit($key, $limit = 5, $timeWindow = 60) {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    $now = time();
    $windowStart = $now - $timeWindow;
    $key = 'rate_limit_' . $key;
    
    if (!isset($_SESSION[$key])) {
        $_SESSION[$key] = [];
    }
    
    // Remove old entries
    $_SESSION[$key] = array_filter($_SESSION[$key], function($timestamp) use ($windowStart) {
        return $timestamp > $windowStart;
    });
    
    if (count($_SESSION[$key]) >= $limit) {
        http_response_code(429);
        header('Retry-After: ' . $timeWindow);
        die('Too many requests. Please try again later.');
    }
    
    $_SESSION[$key][] = $now;
    return true;
}
