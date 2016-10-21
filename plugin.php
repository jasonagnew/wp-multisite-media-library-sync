<?php
/**
 * @wordpress-plugin
 * Plugin Name:       WP Multisite Media Library Sync
 * Plugin URI:        http://bigbitecreative.com/
 * Description:       Keeps the media library in sync across a muiltsite but doesn't copy the files.
 * Version:           1.0.0
 * Author:            Jason Agnew
 * Author URI:        http://bigbitecreative.com/
 * License:           MIT
 */

require_once __DIR__ . '/src/class-mls-media.php';

new MLS_Media();


