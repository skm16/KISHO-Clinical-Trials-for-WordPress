<?php
/**
 * Tests for Trial_Meta class.
 *
 * @package SKMCTF\Tests\Unit
 */

namespace SKMCTF\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SKMCTF\Post_Types\Trial_Meta;

final class TrialMetaTest extends TestCase {
    public function test_nct_id_sanitizes_to_uppercase_canonical_form(): void {
        $this->assertSame( 'NCT01234567', Trial_Meta::sanitize_nct( ' nct01234567 ' ) );
        $this->assertSame( '', Trial_Meta::sanitize_nct( 'not-an-nct' ) );
        $this->assertSame( '', Trial_Meta::sanitize_nct( 'NCT123' ) );
    }
    public function test_keys_map_is_complete(): void {
        $keys = Trial_Meta::KEYS;
        foreach ( [ 'nct_id','official_title','brief_title','overall_status','phase','study_type','conditions','lead_sponsor','locations','eligibility','brief_summary','plain_summary','plain_summary_source_date','ct_last_update','last_synced','ct_url' ] as $short ) {
            $this->assertArrayHasKey( $short, $keys, "missing short key $short" );
            $this->assertStringStartsWith( 'skmctf_', $keys[ $short ] );
        }
    }
    public function test_definitions_each_have_sanitize_callback(): void {
        foreach ( Trial_Meta::definitions() as $meta_key => $def ) {
            $this->assertArrayHasKey( 'sanitize_callback', $def, "$meta_key needs sanitize_callback" );
            $this->assertIsCallable( $def['sanitize_callback'] );
        }
    }
}
