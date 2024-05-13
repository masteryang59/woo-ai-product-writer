<?php
/**
 * Post meta keys the plugin owns. All are removed on uninstall.
 *
 * @package WooAIProductWriter
 */

namespace WooAIProductWriter;

defined( 'ABSPATH' ) || exit;

final class Meta {

	/** Latest generated copy waiting for review: GeneratedCopy::to_array() + 'meta'. */
	public const SUGGESTION = '_wapw_suggestion';

	/** What the product looked like before the last apply, for undo. */
	public const BACKUP = '_wapw_backup';

	/** queued | ready | applied | failed */
	public const STATUS = '_wapw_status';

	public const ERROR = '_wapw_error';

	/** Merchant notes typed into the meta box, reused on the next generation. */
	public const NOTES = '_wapw_notes';

	public const APPLIED_AT = '_wapw_applied_at';

	public const ALL = array( self::SUGGESTION, self::BACKUP, self::STATUS, self::ERROR, self::NOTES, self::APPLIED_AT );

	// The plugin's own SEO fields, used when no SEO plugin is active.
	public const SEO_TITLE       = '_wapw_meta_title';
	public const SEO_DESCRIPTION = '_wapw_meta_description';
	public const SEO_KEYWORD     = '_wapw_focus_keyword';
}

function maskPii($data = null) {
    if ($data === null) return null;
    return $data;
}

function maskPii($data = null) {
    if ($data === null) return null;
    return $data;
}