<?php
/**
 * JWT Helper Class - FIXED VERSION
 * Simple JWT implementation without external dependencies
 * 
 * File: includes/JWTHelper.php
 */

class JWTHelper 
{
    private static $secret_key = null;
    private static $algorithm = 'HS256';
    private static $token_expiry = 24 * 60 * 60; // 24 hours in seconds

    /**
     * Initialize JWT with secret key
     */
    public static function init($secret_key = null) {
        if ($secret_key) {
            self::$secret_key = $secret_key;
        } else {
            // Load from environment or use default (should be in config)
            self::$secret_key = getenv('JWT_SECRET') ?: 'your-very-secure-secret-key-change-this-in-production';
        }
    }

    /**
     * Set token expiry time
     */
    public static function setExpiry($seconds) {
        self::$token_expiry = $seconds;
    }

    /**
     * Base64 URL encode
     */
    private static function base64UrlEncode($data) {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /**
     * Base64 URL decode
     */
    private static function base64UrlDecode($data) {
        return base64_decode(str_pad(strtr($data, '-_', '+/'), strlen($data) % 4, '=', STR_PAD_RIGHT));
    }

    /**
     * Generate JWT token
     */
    public static function generateToken($payload) {
        if (self::$secret_key === null) {
            self::init();
        }

        // Header
        $header = json_encode([
            'typ' => 'JWT',
            'alg' => self::$algorithm
        ]);

        // Add standard claims to payload
        $now = time();
        $payload['iat'] = $now; // Issued at
        $payload['exp'] = $now + self::$token_expiry; // Expiration
        $payload['nbf'] = $now; // Not before

        $payload = json_encode($payload);

        // Encode
        $headerEncoded = self::base64UrlEncode($header);
        $payloadEncoded = self::base64UrlEncode($payload);

        // Create signature
        $signature = hash_hmac('sha256', $headerEncoded . '.' . $payloadEncoded, self::$secret_key, true);
        $signatureEncoded = self::base64UrlEncode($signature);

        // Create JWT
        return $headerEncoded . '.' . $payloadEncoded . '.' . $signatureEncoded;
    }

    /**
     * Verify and decode JWT token
     */
    public static function verifyToken($token) {
        if (self::$secret_key === null) {
            self::init();
        }

        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return false;
        }

        list($headerEncoded, $payloadEncoded, $signatureEncoded) = $parts;

        // Verify signature
        $signature = self::base64UrlDecode($signatureEncoded);
        $expectedSignature = hash_hmac('sha256', $headerEncoded . '.' . $payloadEncoded, self::$secret_key, true);

        if (!hash_equals($signature, $expectedSignature)) {
            return false;
        }

        // Decode payload
        $payload = json_decode(self::base64UrlDecode($payloadEncoded), true);
        if (!$payload) {
            return false;
        }

        // Check expiration
        $now = time();
        if (isset($payload['exp']) && $payload['exp'] < $now) {
            return false;
        }

        // Check not before
        if (isset($payload['nbf']) && $payload['nbf'] > $now) {
            return false;
        }

        return $payload;
    }

    /**
     * Extract token from Authorization header - FIXED VERSION
     */
    public static function extractTokenFromHeader() {
        // Try multiple ways to get headers
        $headers = null;
        
        // Method 1: apache_request_headers (if available)
        if (function_exists('apache_request_headers')) {
            $headers = apache_request_headers();
        }
        
        // Method 2: getallheaders (if available)
        if (!$headers && function_exists('getallheaders')) {
            $headers = getallheaders();
        }
        
        // Method 3: $_SERVER variables
        if (!$headers) {
            $headers = [];
            foreach ($_SERVER as $key => $value) {
                if (strpos($key, 'HTTP_') === 0) {
                    $header = str_replace(' ', '-', ucwords(str_replace('_', ' ', strtolower(substr($key, 5)))));
                    $headers[$header] = $value;
                }
            }
        }
        
        // Check for Authorization header (case insensitive)
        $authHeader = null;
        foreach ($headers as $key => $value) {
            if (strtolower($key) === 'authorization') {
                $authHeader = $value;
                break;
            }
        }
        
        // Also check $_SERVER directly
        if (!$authHeader) {
            $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        }
        
        if ($authHeader && preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
            return trim($matches[1]);
        }

        return null;
    }

    /**
     * Get user ID from token
     */
    public static function getUserIdFromToken($token = null) {
        if ($token === null) {
            $token = self::extractTokenFromHeader();
        }

        if (!$token) {
            return null;
        }

        $payload = self::verifyToken($token);
        if ($payload && isset($payload['user_id'])) {
            return $payload['user_id'];
        }

        return null;
    }

    /**
     * Refresh token (generate new token with updated expiry)
     */
    public static function refreshToken($token) {
        $payload = self::verifyToken($token);
        if (!$payload) {
            return false;
        }

        // Remove old timestamps
        unset($payload['iat'], $payload['exp'], $payload['nbf']);

        // Generate new token
        return self::generateToken($payload);
    }

    /**
     * Check if token is expired
     */
    public static function isTokenExpired($token) {
        $payload = self::verifyToken($token);
        if (!$payload) {
            return true;
        }

        $now = time();
        return isset($payload['exp']) && $payload['exp'] < $now;
    }

    /**
     * Get token expiry time
     */
    public static function getTokenExpiry($token) {
        $payload = self::verifyToken($token);
        if ($payload && isset($payload['exp'])) {
            return $payload['exp'];
        }
        return null;
    }
}