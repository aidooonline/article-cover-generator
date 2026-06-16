<?php
/**
 * Prompt library and the fixed brand overlay spec.
 *
 * The scene prompts below are sent to fal (Path 1 = background only). The overlay spec is the
 * fixed editorial layer the plugin composites in code (P3). Keeping both here makes this file the
 * single source of truth for the brand look.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ACG_Prompts {

	/** Cover scene prompt (Path 1, background only, no text). */
	const COVER_SCENE = 'Ultra-realistic luxury residential editorial photograph. 16:9, full-frame 35mm perspective, HDR balance, 8K detail, sharp architectural focus with a soft cinematic background falloff. Subject: {property_scene}, in {location}. Composition: place the architectural subject on the RIGHT third of the frame; keep the LEFT 45 percent calm, uncluttered and gently darker, reserved for a text overlay. Lighting: warm golden-hour or soft daylight, inviting and premium. Atmosphere: a soft warm gradient darkening towards the LEFT, muted shadow tones, smooth and subtle. Finish: matte, no gloss; subtle film grain; refined, calm, premium. Palette: warm charcoal shadows, soft beige and sand tones, warm natural light, with a very subtle muted champagne-gold note. Avoid: bright or loud colours, oversaturation, a stock-photo or cartoon look, industrial or construction mood, harsh cool tones, glossy effects. No text, no lettering, no logos, no watermark.';

	/** Extra in-article image scene prompt (no panels, no title). */
	const EXTRA_SCENE = 'Ultra-realistic luxury residential editorial photograph. 4:3 or 16:9, full-frame 35mm perspective, HDR, 8K detail, sharp focus with a soft background falloff. Subject: {section_subject}, in {location}. Lighting: warm golden-hour or soft daylight, inviting and premium. Finish: matte, no gloss; subtle film grain; refined and calm. Palette: warm charcoal, soft beige and sand, warm natural light, with a very subtle champagne-gold note. Avoid: bright or loud colours, oversaturation, a stock-photo or cartoon look, industrial mood, harsh cool tones, glossy effects. No text, no lettering, no logos, no watermark.';

	/** Sensible default scene values when no LLM-refine step or manual value is supplied. */
	const DEFAULT_PROPERTY_SCENE = 'a modern luxury apartment living room with floor-to-ceiling windows and elegant furnishings';
	const DEFAULT_SECTION_SUBJECT = 'a bright, elegantly furnished apartment interior';
	const DEFAULT_LOCATION        = 'Accra, Ghana';

	/** Default template libraries (one default per library; more can be added in the UI). */
	public static function default_templates() {
		return array(
			'cover' => array(
				array(
					'id'         => 'editorial-glass',
					'name'       => 'Editorial Glass',
					'prompt'     => self::COVER_SCENE,
					'is_default' => true,
				),
			),
			'extra' => array(
				array(
					'id'         => 'editorial-scene',
					'name'       => 'Editorial Scene',
					'prompt'     => self::EXTRA_SCENE,
					'is_default' => true,
				),
			),
		);
	}

	/**
	 * Fixed brand overlay spec, composited in code at P3 so the layout and title are identical
	 * on every cover and the text is always crisp and correctly spelled.
	 */
	public static function overlay_spec() {
		return array(
			'aspect'          => '16:9',
			'safe_margin_pct' => 10,
			'panel_a'         => array( 'side' => 'left', 'width_pct' => 44, 'height_pct' => 100, 'fill' => '#2A2422', 'opacity' => 0.68, 'radius' => 'subtle' ),
			'panel_b'         => array( 'corner' => 'top-left', 'width_pct' => 28, 'height_pct' => 28, 'opacity' => 0.50 ),
			'panel_c'         => array( 'corner' => 'bottom-left', 'height_pct' => 6, 'color' => '#C2A878' ),
			'frame_lines'     => array( 'color' => '#F5F1E8', 'opacity' => 0.25, 'right_vertical' => true, 'bottom_horizontal' => true ),
			'diagonal'        => array( 'from' => 'lower-left', 'to' => 'mid-upper', 'opacity' => 0.15 ),
			'title'           => array( 'inside' => 'panel_a', 'align' => 'left', 'width_pct_of_panel' => 76, 'valign' => 'center', 'font' => 'Jost', 'weight' => '300-400', 'color' => '#FBF7F0', 'letter_spacing' => 'slight' ),
		);
	}

	/** Placeholder variables the UI documents and the pipeline fills. */
	public static function variables() {
		return array(
			'{title}'           => 'The article headline (rendered in the cover title panel).',
			'{property_scene}'  => 'The architectural subject/scene shown on the right.',
			'{location}'        => 'Accra context, e.g. the Airport Residential Area.',
			'{section_subject}' => 'Subject for an in-article extra image.',
			'{site_name}'       => 'Brand name.',
			'{category}'        => 'Article category.',
		);
	}

	/** Replace {placeholders} in a template with their values. */
	public static function render( $template, array $vars ) {
		$pairs = array();
		foreach ( $vars as $key => $value ) {
			$pairs[ '{' . $key . '}' ] = $value;
		}
		return strtr( (string) $template, $pairs );
	}
}
