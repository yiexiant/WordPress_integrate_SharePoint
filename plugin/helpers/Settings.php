<?php
namespace SPSync\Helpers;

if (!defined('ABSPATH')) {
    exit;
}

class Settings
{
    /**
     * Get a setting option from the custom settings table.
     *
     * @param string $name Option name.
     * @param mixed $default Default fallback.
     * @return mixed Option value.
     */
    public static function get($name, $default = '')
    {
        global $wpdb;
        $table = $wpdb->prefix . 'spsync_settings';
        
        $value = $wpdb->get_var($wpdb->prepare(
            "SELECT option_value FROM $table WHERE option_name = %s",
            $name
        ));
        
        if ($value === null) {
            return $default;
        }
        
        // Transparent decryption for client secret
        if ($name === 'client_secret') {
            $encrypt_enabled = self::get('encrypt_secret', '1');
            if ($encrypt_enabled === '1') {
                return Encryption::decrypt($value);
            }
        }
        
        return $value;
    }

    /**
     * Store or update a setting option in the custom settings table.
     *
     * @param string $name Option name.
     * @param mixed $value Option value.
     * @return bool True on success, false on failure.
     */
    public static function set($name, $value)
    {
        global $wpdb;
        $table = $wpdb->prefix . 'spsync_settings';
        
        // Transparent encryption for client secret
        if ($name === 'client_secret') {
            $encrypt_enabled = self::get('encrypt_secret', '1');
            if ($encrypt_enabled === '1') {
                $value = Encryption::encrypt($value);
            }
        }

        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table WHERE option_name = %s",
            $name
        ));

        if ($exists) {
            return $wpdb->update(
                $table,
                [ 'option_value' => $value ],
                [ 'option_name' => $name ],
                [ '%s' ],
                [ '%s' ]
            ) !== false;
        } else {
            return $wpdb->insert(
                $table,
                [
                    'option_name'  => $name,
                    'option_value' => $value
                ],
                [ '%s', '%s' ]
            ) !== false;
        }
    }
    
    /**
     * Create a setting only if it does not exist yet. Atomic (the option name is the primary key),
     * so two requests racing for the same lock can never both win.
     *
     * @return bool True if this call created it.
     */
    public static function add($name, $value)
    {
        global $wpdb;
        $table = $wpdb->prefix . 'spsync_settings';
        return (int) $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO $table (option_name, option_value) VALUES (%s, %s)",
            $name,
            (string) $value
        )) === 1;
    }

    /**
     * Replace a setting only while it still holds $expected (atomic compare-and-set).
     *
     * @return bool True if this call replaced it.
     */
    public static function replace_if($name, $expected, $value)
    {
        global $wpdb;
        $table = $wpdb->prefix . 'spsync_settings';
        return (int) $wpdb->query($wpdb->prepare(
            "UPDATE $table SET option_value = %s WHERE option_name = %s AND option_value = %s",
            (string) $value,
            $name,
            (string) $expected
        )) === 1;
    }

    /**
     * Delete a setting option from the custom table.
     *
     * @param string $name Option name.
     * @return bool True on success, false on failure.
     */
    public static function delete($name)
    {
        global $wpdb;
        $table = $wpdb->prefix . 'spsync_settings';
        return $wpdb->delete($table, [ 'option_name' => $name ], [ '%s' ]) !== false;
    }
}
