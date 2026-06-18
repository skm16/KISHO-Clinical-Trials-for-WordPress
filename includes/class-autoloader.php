<?php
/**
 * Autoloader for the SKMCTF namespace.
 *
 * @package SKMCTF
 */

namespace SKMCTF;

final class Autoloader {
	private string $base_dir;

	public function __construct( string $base_dir ) {
		$this->base_dir = rtrim( $base_dir, '/\\' ) . '/';
	}

	public function register(): void {
		spl_autoload_register( array( $this, 'load' ) );
	}

	public function load( string $class ): void {
		$path = $this->path_for( $class );
		if ( $path && is_readable( $path ) ) {
			require $path;
		}
	}

	/**
	 * Map SKMCTF\Sub\Class_Name -> base/sub/class-class-name.php
	 *
	 * @param string $class Fully-qualified class name.
	 * @return string|null File path, or null if not in SKMCTF namespace.
	 */
	public function path_for( string $class ): ?string {
		if ( strpos( $class, 'SKMCTF\\' ) !== 0 ) {
			return null;
		}
		$relative   = substr( $class, strlen( 'SKMCTF\\' ) );
		$parts      = explode( '\\', $relative );
		$class_part = array_pop( $parts );
		$file       = 'class-' . str_replace( '_', '-', strtolower( $class_part ) ) . '.php';
		$dir        = $parts ? implode(
			'/',
			array_map(
				static function ( $p ) {
					return str_replace( '_', '-', strtolower( $p ) );
				},
				$parts
			)
		) . '/' : '';
		return $this->base_dir . $dir . $file;
	}
}
