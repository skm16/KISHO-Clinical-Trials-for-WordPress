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

	public function test_new_llm_meta_keys_exist(): void {
		$keys = \SKMCTF\Post_Types\Trial_Meta::KEYS;
		$this->assertSame( 'skmctf_study_purpose', $keys['study_purpose'] );
		$this->assertSame( 'skmctf_who_can_join', $keys['who_can_join'] );
		$this->assertSame( 'skmctf_doctor_questions', $keys['doctor_questions'] );
		$this->assertSame( 'skmctf_study_purpose_source_date', $keys['study_purpose_source_date'] );
		$this->assertSame( 'skmctf_who_can_join_source_date', $keys['who_can_join_source_date'] );
		$this->assertSame( 'skmctf_doctor_questions_source_date', $keys['doctor_questions_source_date'] );
	}

	public function test_sanitize_string_list_filters_and_trims(): void {
		$out = \SKMCTF\Post_Types\Trial_Meta::sanitize_string_list( array( ' Ask about risks ', '', 'Eligibility?' ) );
		$this->assertSame( array( 'Ask about risks', 'Eligibility?' ), $out );
	}
}
