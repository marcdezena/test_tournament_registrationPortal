<?php
/**
 * Security helper functions
 */

/**
 * Generate and store CSRF token if not exists
 */
function ensureCsrfToken() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Validate CSRF token
 * @param string $token Token to validate
 * @return bool True if valid, false otherwise
 */
function validateCsrfToken($token) {
    if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
        return false;
    }
    // Regenerate token after successful validation
    unset($_SESSION['csrf_token']);
    return true;
}

/**
 * Sanitize output to prevent XSS
 * @param string $data Data to sanitize
 * @return string Sanitized data
 */
function e($data) {
    return htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
}

/**
 * Validate and sanitize input
 * @param mixed $data Input data
 * @param string $type Type of validation (email, int, string, etc.)
 * @return mixed Sanitized data or false on failure
 */
function sanitizeInput($data, $type = 'string') {
    if ($data === null) {
        return false;
    }

    switch ($type) {
        case 'email':
            $data = filter_var(trim($data), FILTER_SANITIZE_EMAIL);
            return filter_var($data, FILTER_VALIDATE_EMAIL) ? $data : false;
            
        case 'int':
            return filter_var($data, FILTER_VALIDATE_INT) !== false ? (int)$data : false;
            
        case 'float':
            return filter_var($data, FILTER_VALIDATE_FLOAT) !== false ? (float)$data : false;
            
        case 'url':
            $data = filter_var(trim($data), FILTER_SANITIZE_URL);
            return filter_var($data, FILTER_VALIDATE_URL) ? $data : false;
            
        case 'alphanum':
            return preg_match('/^[a-zA-Z0-9]+$/', $data) ? $data : false;
            
        case 'string':
        default:
            return filter_var(trim($data), FILTER_SANITIZE_STRING, FILTER_FLAG_NO_ENCODE_QUOTES);
    }
}

/**
 * Set security headers
 */
function setSecurityHeaders() {
    // Prevent clickjacking
    header('X-Frame-Options: SAMEORIGIN');
    
    // Enable XSS protection
    header('X-XSS-Protection: 1; mode=block');
    
    // Prevent MIME type sniffing
    header('X-Content-Type-Options: nosniff');
    
    // Content Security Policy
    $csp = [
        "default-src 'self'",
        "script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://code.jquery.com",
        "style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net",
        "img-src 'self' data: https: http:",
        "font-src 'self' https://cdn.jsdelivr.net",
        "connect-src 'self'",
        "frame-ancestors 'self'",
        "form-action 'self'"
    ];
    
    header("Content-Security-Policy: " . implode('; ', $csp));
    
    // HSTS - Uncomment in production with SSL
    // header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
}

// Set security headers on every request
setSecurityHeaders();
