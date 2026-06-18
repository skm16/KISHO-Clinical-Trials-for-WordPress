<?php
/**
 * Unit tests for Field_Mapper (pure transformer, no WordPress required).
 *
 * @package SKMCTF\Tests\Unit
 */

namespace SKMCTF\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SKMCTF\Sync\Field_Mapper;

final class FieldMapperTest extends TestCase {
	private function study(): array {
		return [ 'protocolSection' => [
			'identificationModule'       => [ 'nctId' => 'NCT06121011', 'briefTitle' => 'A Registry', 'officialTitle' => 'A Global Registry' ],
			'statusModule'               => [ 'overallStatus' => 'RECRUITING', 'lastUpdatePostDateStruct' => [ 'date' => '2026-03-10' ] ],
			'sponsorCollaboratorsModule' => [ 'leadSponsor' => [ 'name' => 'Amicus Therapeutics' ] ],
			'designModule'               => [ 'phases' => [ 'PHASE2', 'PHASE3' ], 'studyType' => 'INTERVENTIONAL' ],
			'conditionsModule'           => [ 'conditions' => [ 'Pompe Disease' ] ],
			'descriptionModule'          => [ 'briefSummary' => 'Some summary.' ],
			'eligibilityModule'          => [ 'sex' => 'ALL', 'minimumAge' => '18 Years', 'maximumAge' => 'N/A', 'eligibilityCriteria' => 'Inclusion: ...' ],
			'contactsLocationsModule'    => [ 'locations' => [ [ 'facility' => 'Clinic', 'city' => 'Boston', 'state' => 'MA', 'country' => 'United States', 'status' => 'RECRUITING', 'geoPoint' => [ 'lat' => 42.36, 'lon' => -71.06 ] ] ] ],
		] ];
	}

	public function test_maps_core_fields(): void {
		$m = Field_Mapper::map( $this->study() );
		$this->assertSame( 'NCT06121011', $m['nct_id'] );
		$this->assertSame( 'A Registry', $m['brief_title'] );
		$this->assertSame( 'RECRUITING', $m['overall_status'] );
		$this->assertSame( '2026-03-10', $m['ct_last_update'] );
		$this->assertSame( 'Amicus Therapeutics', $m['lead_sponsor'] );
		$this->assertSame( 'Phase 2/Phase 3', $m['phase'] );
		$this->assertSame( [ 'Pompe Disease' ], $m['conditions'] );
		$this->assertSame( 'https://clinicaltrials.gov/study/NCT06121011', $m['ct_url'] );
		$this->assertSame( 42.36, $m['locations'][0]['lat'] );
		$this->assertSame( 'ALL', $m['eligibility']['sex'] );
	}

	public function test_handles_empty_modules(): void {
		$m = Field_Mapper::map( [ 'protocolSection' => [ 'identificationModule' => [ 'nctId' => 'NCT00000000' ], 'designModule' => [] ] ] );
		$this->assertSame( 'NCT00000000', $m['nct_id'] );
		$this->assertSame( '', $m['phase'] );
		$this->assertSame( [], $m['conditions'] );
		$this->assertSame( [], $m['locations'] );
	}

	public function test_unmappable_study_yields_empty_nct(): void {
		$this->assertSame( '', Field_Mapper::map( [] )['nct_id'] );
	}
}
