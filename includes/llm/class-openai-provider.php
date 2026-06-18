<?php
/**
 * OpenAI provider — calls the Chat Completions API and returns plain text or WP_Error.
 *
 * Never throws; all failure paths return WP_Error so callers can stay safe even
 * if the LLM call fails (front-end degrades gracefully).
 *
 * @package SKMCTF\LLM
 * @license GPL-2.0-or-later
 */

namespace SKMCTF\LLM;

final class Openai_Provider implements Llm_Provider {

	/** @var string OpenAI Chat Completions endpoint. */
	private const URL = 'https://api.openai.com/v1/chat/completions';

	/** @var string Default model. */
	private const DEFAULT_MODEL = 'gpt-4o-mini';

	/** @var string */
	private $key;

	/** @var string */
	private $model;

	/**
	 * @param string $api_key OpenAI API key (never logged or echoed).
	 * @param string $model   Override model ID; empty string uses the default.
	 */
	public function __construct( string $api_key, string $model = '' ) {
		$this->key   = $api_key;
		$this->model = $model !== '' ? $model : self::DEFAULT_MODEL;
	}

	/** {@inheritdoc} */
	public function id(): string {
		return 'openai';
	}

	/**
	 * {@inheritdoc}
	 *
	 * @return string|\WP_Error
	 */
	public function generate_summary( string $system, string $user, array $opts = array() ) {
		$body = array(
			'model'      => $this->model,
			'messages'   => array(
				array(
					'role'    => 'system',
					'content' => $system,
				),
				array(
					'role'    => 'user',
					'content' => $user,
				),
			),
			'max_tokens' => (int) ( $opts['max_tokens'] ?? 700 ),
		);

		$res = wp_remote_post(
			self::URL,
			array(
				'timeout' => 30,
				'headers' => array(
					'Authorization' => 'Bearer ' . $this->key,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( $body ),
			)
		);

		if ( is_wp_error( $res ) ) {
			return $res;
		}

		$code = (int) wp_remote_retrieve_response_code( $res );
		$data = json_decode( wp_remote_retrieve_body( $res ), true );

		if ( 200 !== $code ) {
			return new \WP_Error(
				'skmctf_openai',
				sprintf(
					'OpenAI API error (HTTP %d): %s',
					$code,
					isset( $data['error']['message'] ) ? $data['error']['message'] : 'unknown'
				)
			);
		}

		$text = isset( $data['choices'][0]['message']['content'] ) ? $data['choices'][0]['message']['content'] : '';
		return $text !== '' ? $text : new \WP_Error( 'skmctf_openai_empty', 'Empty response.' );
	}
}
