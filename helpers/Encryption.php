<?php
namespace SPSync\Helpers;

if (!defined('ABSPATH')) {
    exit;
}

class Encryption
{
    /**
     * Encrypts a string value using OpenSSL AES-256-CBC and keys derived from WordPress salts.
     *
     * @param string $value Plain text value to encrypt.
     * @return string Base64 encoded concatenated IV + ciphertext.
     */
    public static function encrypt($value)
    {
        if (empty($value)) {
            return '';
        }
        
        $key = self::get_key();
        if (!$key) {
            return base64_encode($value); // Fallback base64
        }
        
        $method = 'aes-256-cbc';
        $iv_length = openssl_cipher_iv_length($method);
        $iv = openssl_random_pseudo_bytes($iv_length);
        
        $encrypted = openssl_encrypt($value, $method, $key, OPENSSL_RAW_DATA, $iv);
        if ($encrypted === false) {
            return '';
        }
        
        return base64_encode($iv . $encrypted);
    }

    /**
     * Decrypts an encrypted value.
     *
     * @param string $value Base64 encoded concatenated IV + ciphertext.
     * @return string Decrypted plain text.
     */
    public static function decrypt($value)
    {
        if (empty($value)) {
            return '';
        }
        
        $raw = base64_decode($value, true);
        if ($raw === false) {
            return '';
        }
        
        $key = self::get_key();
        if (!$key) {
            return $raw; // Fallback
        }
        
        $method = 'aes-256-cbc';
        $iv_length = openssl_cipher_iv_length($method);
        
        if (strlen($raw) < $iv_length) {
            return '';
        }
        
        $iv = substr($raw, 0, $iv_length);
        $encrypted = substr($raw, $iv_length);
        
        $decrypted = openssl_decrypt($encrypted, $method, $key, OPENSSL_RAW_DATA, $iv);
        return $decrypted !== false ? $decrypted : '';
    }

    /**
     * Helper to retrieve or derive key from security salts.
     *
     * @return string|null Derived 256-bit key or null if openssl is unavailable.
     */
    private static function get_key()
    {
        if (!function_exists('openssl_encrypt')) {
            return null;
        }
        
        $salt = defined('SECURE_AUTH_KEY') ? SECURE_AUTH_KEY : (defined('AUTH_KEY') ? AUTH_KEY : 'default_fallback_salt_32_chars_long!!');
        return hash('sha256', $salt, true);
    }
}
