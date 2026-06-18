<?php
/**
 * Unit tests for the Autoloader class.
 *
 * @package SKMCTF\Tests\Unit
 */

namespace SKMCTF\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SKMCTF\Autoloader;

final class AutoloaderTest extends TestCase {
	public function test_maps_namespaced_class_to_file_path(): void {
		$autoloader = new Autoloader( '/plugin/includes/' );
		$this->assertSame(
			'/plugin/includes/sync/class-ctgov-client.php',
			$autoloader->path_for( 'SKMCTF\\Sync\\Ctgov_Client' )
		);
		$this->assertSame(
			'/plugin/includes/class-plugin.php',
			$autoloader->path_for( 'SKMCTF\\Plugin' )
		);
		$this->assertNull( $autoloader->path_for( 'Other\\Thing' ) );
		$this->assertSame(
			'/plugin/includes/post-types/class-trial-meta.php',
			$autoloader->path_for( 'SKMCTF\\Post_Types\\Trial_Meta' )
		);
	}
}
