<?php
/**
 * Anthropic provider — calls the Messages API and returns plain text or WP_Error.
 *
 * Never throws; all failure paths return WP_Error so callers can stay safe even
 * if the LLM call fails (front-end degrades gracefully).
 *
 * @package SKMCTF\LLM
 * @license GPL-2.0-or-later
 */

namespace SKMCTF\LLM;

final class Anthropic_Provider implements Llm_Provider {

	/** @var string Anthropic Messages API endpoint. */
	private const URL = 'https://api.anthropic.com/v1/messages';

	/** @var string Default model — cheapest tier suitable for 8th-grade summaries. */
	private const DEFAULT_MODEL = 'claude-haiku-4-5-20251001';

	/** @var string */
	private $key;

	/** @var string */
	private $model;

	/**
	 * @param string $api_key Anthropic API key (never logged or echoed).
	 * @param string $model   Override model ID; empty string uses the default.
	 */
	public function __construct( string $api_key, string $model = '' ) {
		$this->key   = $api_key;
		$this->model = $model !== '' ? $model : self::DEFAULT_MODEL;
	}

	/** {@inheritdoc} */
	public function id(): string {
		return 'anthropic';
	}

	/**
	 * {@inheritdoc}
	 *
	 * @return string|\WP_Error
	 */
	public function generate_summary( string $system, string $user, array $opts = [] ) {
		$body = [
			'model'      => $this->model,
			'max_tokens' => (int) ( $opts['max_tokens'] ?? 700 ),
			'system'     => $system,
			'messages'   => [ [ 'role' => 'user', 'content' => $user ] ],
		];

		$res = wp_remote_post(
			self::URL,
			[
				'timeout' => 30,
				'headers' => [
					'x-api-key'         => $this->key,
					'anthropic-version' => '2023-06-01',
					'content-type'      => 'application/json',
				],
				'body'    => wp_json_encode( $body ),
			]
		);

		if ( is_wp_error( $res ) ) {
			return $res;
		}

		$code = (int) wp_remote_retrieve_response_code( $res );
		$data = json_decode( wp_remote_retrieve_body( $res ), true );

		if ( 200 !== $code ) {
			return new \WP_Error(
				'skmctf_anthropic',
				sprintf(
					'Anthropic API error (HTTP %d): %s',
					$code,
					isset( $data['error']['message'] ) ? $data['error']['message'] : 'unknown'
				)
			);
		}

		$text = isset( $data['content'][0]['text'] ) ? $data['content'][0]['text'] : '';
		return $text !== '' ? $text : new \WP_Error( 'skmctf_anthropic_empty', 'Empty response.' );
	}
}
