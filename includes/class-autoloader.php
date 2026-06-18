<?php
/**
 * Autoloader for the SKMCTF namespace.
 *
 * @package SKMCTF
 */

namespace SKMCTF;

/**
 * PSR-4-style autoloader scoped to the SKMCTF namespace.
 */
final class Autoloader {

	/**
	 * Absolute base directory for class files.
	 *
	 * @var string
	 */
	private string $base_dir;

	/**
	 * Constructor.
	 *
	 * @param string $base_dir Absolute path to the includes/ directory.
	 */
	public function __construct( string $base_dir ) {
		$this->base_dir = rtrim( $base_dir, '/\\' ) . '/';
	}

	/**
	 * Register this autoloader with spl_autoload_register.
	 *
	 * @return void
	 */
	public function register(): void {
		spl_autoload_register( array( $this, 'load' ) );
	}

	/**
	 * Load a class file if it belongs to the SKMCTF namespace.
	 *
	 * @param string $class_name Fully-qualified class name.
	 * @return void
	 */
	public function load( string $class_name ): void { // phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames.classFound -- required by spl_autoload_register callback signature
		$path = $this->path_for( $class_name );
		if ( $path && is_readable( $path ) ) {
			require $path;
		}
	}

	/**
	 * Map SKMCTF\Sub\Class_Name -> base/sub/class-class-name.php
	 *
	 * @param string $class_name Fully-qualified class name.
	 * @return string|null File path, or null if not in SKMCTF namespace.
	 */
	public function path_for( string $class_name ): ?string { // phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames.classFound -- renamed from $class; caller-facing method may accept any FQN
		if ( strpos( $class_name, 'SKMCTF\\' ) !== 0 ) {
			return null;
		}
		$relative   = substr( $class_name, strlen( 'SKMCTF\\' ) );
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
