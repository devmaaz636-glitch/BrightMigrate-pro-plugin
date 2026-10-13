<?php
/**
 * Archive failures.
 *
 * @package BrightMigrate
 */

namespace BrightIdeas\Migrate\Archive;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Raised when an archive cannot be read or written safely.
 *
 * The message is safe to log. It never includes a password.
 *
 * @since 1.0.0
 */
class ArchiveException extends \RuntimeException {}
