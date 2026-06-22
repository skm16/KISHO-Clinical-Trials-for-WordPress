<?php
/**
 * Integration test for Condition_Cleanup::preview().
 *
 * Uses the pre_http_request filter to stub the ClinicalTrials.gov response so
 * preview() runs end-to-end without a network call.
 *
 * @package SKMCTF\Tests\Integration
 */

namespace SKMCTF\Tests\Integration;

use WP_UnitTestCase;
use SKMCTF\Sync\Condition_Cleanup;
use SKMCTF\Sync\Ctgov_Client;
use SKMCTF\Data\Trial_Repository;
use SKMCTF\Support\Logger;
use SKMCTF\Post_Types\Trial_Meta;
use SKMCTF\Admin\Settings;

final class ConditionCleanupPreviewTest extends WP_UnitTestCase {

	private function seed_trial( string $nct ): int {
		$id = self::factory()->post->create( array( 'post_type' => 'skmctf_trial' ) );
		update_post_meta( $id, Trial_Meta::KEYS['nct_id'], $nct );
		return $id;
	}

	/**
	 * Stub the CT.gov HTTP response to return exactly the given NCT studies.
	 *
	 * @param string[] $ncts NCT IDs the fake feed should return.
	 */
	private function stub_feed( array $ncts ): void {
		$studies = array();
		foreach ( $ncts as $nct ) {
			$studies[] = array(
				'protocolSection' => array(
					'identificationModule' => array( 'nctId' => $nct ),
				),
			);
		}
		$body = wp_json_encode(
			array(
				'studies'        => $studies,
				'totalCount'     => count( $studies ),
				'nextPageToken'  => null,
			)
		);
		add_filter(
			'pre_http_request',
			static function () use ( $body ) {
				return array(
					'body'     => $body,
					'response' => array( 'code' => 200, 'message' => 'OK' ),
					'headers'  => array(),
				);
			},
			10,
			3
		);
	}

	public function test_preview_returns_stored_minus_seen_and_includes(): void {
		update_option(
			Settings::OPTION,
			array_merge(
				(array) get_option( Settings::OPTION, array() ),
				array(
					'conditions'   => array( 'HHT' ),
					'statuses'     => array( 'RECRUITING' ),
					'include_ncts' => array( 'NCT00000003' ),
				)
			)
		);

		$this->seed_trial( 'NCT00000001' ); // off-condition (not seen, not included)
		$this->seed_trial( 'NCT00000002' ); // seen
		$this->seed_trial( 'NCT00000003' ); // included (exempt)

		// Feed returns only NCT00000002 for the HHT condition.
		$this->stub_feed( array( 'NCT00000002' ) );

		$clean   = new Condition_Cleanup( new Ctgov_Client(), new Trial_Repository(), new Logger() );
		$preview = $clean->preview();

		$this->assertFalse( $preview['had_error'] );
		$this->assertSame( 3, $preview['stored_count'] );
		$this->assertSame( array( 'NCT00000001' ), $preview['off_condition'] );
	}

	public function test_preview_flags_had_error_on_fetch_failure(): void {
		update_option(
			Settings::OPTION,
			array_merge(
				(array) get_option( Settings::OPTION, array() ),
				array(
					'conditions'   => array( 'HHT' ),
					'statuses'     => array( 'RECRUITING' ),
					'include_ncts' => array(),
				)
			)
		);
		$this->seed_trial( 'NCT00000001' );

		// Force the HTTP layer to error.
		add_filter(
			'pre_http_request',
			static function () {
				return new \WP_Error( 'http_request_failed', 'boom' );
			},
			10,
			3
		);

		$clean   = new Condition_Cleanup( new Ctgov_Client(), new Trial_Repository(), new Logger() );
		$preview = $clean->preview();

		$this->assertTrue( $preview['had_error'] );
	}
}
