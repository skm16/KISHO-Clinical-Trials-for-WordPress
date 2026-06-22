<?php
/**
 * Front-end theme resolver.
 *
 * Single source of truth that turns the stored theme/theme_mode settings into
 * (a) a CSS skin class, (b) a data-skmctf-mode attribute, and (c) the set of
 * CSS/font asset handles to enqueue. Renderers and templates consult this class;
 * they never read theme settings directly.
 *
 * @package SKMCTF\Frontend
 * @license GPL-2.0-or-later
 */

namespace SKMCTF\Frontend;

use SKMCTF\Admin\Settings;

/**
 * Resolves the active theme into presentation primitives.
 */
final class Theme {

	/**
	 * Map of skin key => skin CSS class. 'skeleton' intentionally maps to ''.
	 *
	 * @var array<string,string>
	 */
	private const SKIN_CLASSES = array(
		'skeleton' => '',
		'clinical' => 'skmctf-skin--clinical',
		'warm'     => 'skmctf-skin--warm',
	);

	/**
	 * Active theme key.
	 *
	 * @return string 'skeleton' | 'clinical' | 'warm'
	 */
	public static function active(): string {
		return Settings::theme();
	}

	/**
	 * Active appearance mode.
	 *
	 * @return string 'light' | 'dark'
	 */
	public static function mode(): string {
		return Settings::theme_mode();
	}

	/**
	 * CSS skin class for the active theme ('' for skeleton).
	 *
	 * @return string
	 */
	public static function skin_class(): string {
		$key = self::active();
		return self::SKIN_CLASSES[ $key ] ?? '';
	}

	/**
	 * Whether a non-skeleton skin is active.
	 *
	 * @return bool
	 */
	public static function has_skin(): bool {
		return '' !== self::skin_class();
	}

	/**
	 * Escaped data-skmctf-mode attribute, e.g. data-skmctf-mode="dark".
	 *
	 * @return string
	 */
	public static function mode_attr(): string {
		return 'data-skmctf-mode="' . esc_attr( self::mode() ) . '"';
	}
}
