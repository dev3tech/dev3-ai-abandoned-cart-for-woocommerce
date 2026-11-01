<?php
/**
 * DEV3 AI prompts and calls (Google Gemini through the core).
 *
 * @package Dev3\AiAbandonedCart
 */

namespace Dev3\AiAbandonedCart\Ai;

use Dev3\AiAbandonedCart\Core3\Api\Ai;
use Dev3\AiAbandonedCart\Core3\Api\Prompts;
use Dev3\AiAbandonedCart\Data\Templates;
use Dev3\AiAbandonedCart\Engine\Renderer;
use Dev3\AiAbandonedCart\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Every result here is a draft: callers store it for review or show it in the admin, never apply it directly.
 */
class Copywriter {

	/**
	 * Registers the prompt library (init).
	 */
	public static function register_prompts() {
		$str = static function ( $max ) {
			return array(
				'type'      => 'STRING',
				'maxLength' => $max,
			);
		};
		Prompts::register(
			'cart_copy',
			array(
				'version'     => '1',
				'task'        => 'cart_copy',
				'temperature' => 0.7,
				'profile'     => 'fast',
				'system'      => 'You write short, warm, honest cart-recovery and win-back messages for an online store. Plain text only (no HTML, no markdown, no emoji unless the tone is playful). Never invent discounts, prices, deadlines, stock levels or coupon codes: a coupon box is added automatically when an offer exists. Never mention that you are an AI. Write in the language of locale {{locale}}. Tone: {{tone}}. Brand notes: {{brand_notes}}',
				'template'    => "Store: {{store}}\nMessage type: {{kind}} (step {{step_number}} of {{step_count}})\nShopper first name: {{first_name}}\nItems: {{items}}\nCart total: {{total}}\nOffer in this message: {{offer}}\nThe approved template copy (keep its intent and length, personalise it for these items):\n{{template}}\n\nReturn subject (max 70 chars), preheader (max 120), heading (max 70), intro (2-4 short sentences, may use line breaks), button (2-4 words) and sms (max 155 chars incl. the placeholder {recovery_url}).",
				'schema'      => array(
					'type'       => 'OBJECT',
					'properties' => array(
						'subject'   => $str( 90 ),
						'preheader' => $str( 140 ),
						'heading'   => $str( 80 ),
						'intro'     => $str( 600 ),
						'button'    => $str( 40 ),
						'sms'       => $str( 300 ),
					),
					'required'   => array( 'subject', 'preheader', 'heading', 'intro', 'button', 'sms' ),
				),
			)
		);
		Prompts::register(
			'template_writer',
			array(
				'version'     => '1',
				'task'        => 'template_writer',
				'temperature' => 0.8,
				'profile'     => 'fast',
				'system'      => 'You are an email copywriter for e-commerce recovery and win-back emails. Plain text only. You may use these merge tags exactly as written: {first_name}, {store_name}, {top_product}, {item_count}, {cart_total}, {coupon_code}, {coupon_amount}, {coupon_expiry}, {recovery_url}. Do not invent numbers that are not given. Tone: {{tone}}. Brand notes: {{brand_notes}}',
				'template'    => "Store: {{store}}\nChannel: {{channel}}\nGoal of this message: {{goal}}\nOffer: {{offer}}\nWrite: subject (max 70 chars), 5 alternative subject ideas, preheader (max 120), heading (max 70), body (2-3 short paragraphs separated by a blank line), button (2-4 words), sms (max 155 chars, must contain {recovery_url}).",
				'schema'      => array(
					'type'       => 'OBJECT',
					'properties' => array(
						'subject'       => $str( 90 ),
						'subject_ideas' => array(
							'type'  => 'ARRAY',
							'items' => $str( 90 ),
						),
						'preheader'     => $str( 140 ),
						'heading'       => $str( 80 ),
						'body'          => $str( 1200 ),
						'button'        => $str( 40 ),
						'sms'           => $str( 300 ),
					),
					'required'   => array( 'subject', 'preheader', 'heading', 'body', 'button', 'sms' ),
				),
			)
		);
		Prompts::register(
			'sequence_builder',
			array(
				'version'     => '1',
				'task'        => 'sequence_builder',
				'temperature' => 0.3,
				'profile'     => 'fast',
				'system'      => 'You are a WooCommerce retention expert. Design an effective, respectful abandoned-cart or win-back sequence (2-5 steps). Prefer no discount in the first step; never give more than 25% off. Use only the template ids provided and pick a template whose channel matches the step channel. Use SMS only if sms_available is true.',
				'template'    => "Sequence type: {{type}}\nStore stats: {{stats}}\nsms_available: {{sms}}\nTemplates: {{templates}}\nReturn steps with delay (from abandonment for step 1, then from the previous step), channel, template_id, coupon settings and a one-sentence reason, plus a short summary.",
				'schema'      => array(
					'type'       => 'OBJECT',
					'properties' => array(
						'summary' => $str( 500 ),
						'steps'   => array(
							'type'  => 'ARRAY',
							'items' => array(
								'type'       => 'OBJECT',
								'properties' => array(
									'delay_value'        => array( 'type' => 'INTEGER' ),
									'delay_unit'         => array(
										'type' => 'STRING',
										'enum' => array( 'minutes', 'hours', 'days' ),
									),
									'channel'            => array(
										'type' => 'STRING',
										'enum' => array( 'email', 'sms' ),
									),
									'template_id'        => $str( 40 ),
									'coupon_enabled'     => array( 'type' => 'BOOLEAN' ),
									'coupon_type'        => array(
										'type' => 'STRING',
										'enum' => array( 'percent', 'fixed_cart', 'free_shipping' ),
									),
									'coupon_amount'      => array( 'type' => 'NUMBER' ),
									'coupon_expiry_days' => array( 'type' => 'INTEGER' ),
									'reason'             => $str( 240 ),
								),
								'required'   => array( 'delay_value', 'delay_unit', 'channel', 'template_id', 'coupon_enabled', 'reason' ),
							),
						),
					),
					'required'   => array( 'summary', 'steps' ),
				),
			)
		);
		Prompts::register(
			'send_time',
			array(
				'version'     => '1',
				'task'        => 'send_time',
				'temperature' => 0.2,
				'profile'     => 'fast',
				'system'      => 'You analyse email engagement histograms for an online store and recommend send-time settings. Hours are shopper-local 0-23. Be conservative when data is sparse (fewer than 30 events): then recommend 10 and 19 as best hours and quiet hours 21-8.',
				'template'    => "Opens by hour: {{opens}}\nClicks by hour: {{clicks}}\nRecovered orders by hour: {{orders}}\nCurrent settings: {{current}}\nReturn best_hours (1-4 hours), quiet_start, quiet_end, max_shift_hours (0-12) and a plain-language explanation (max 3 sentences).",
				'schema'      => array(
					'type'       => 'OBJECT',
					'properties' => array(
						'best_hours'      => array(
							'type'  => 'ARRAY',
							'items' => array( 'type' => 'INTEGER' ),
						),
						'quiet_start'     => array( 'type' => 'INTEGER' ),
						'quiet_end'       => array( 'type' => 'INTEGER' ),
						'max_shift_hours' => array( 'type' => 'INTEGER' ),
						'explanation'     => $str( 500 ),
					),
					'required'   => array( 'best_hours', 'quiet_start', 'quiet_end', 'max_shift_hours', 'explanation' ),
				),
			)
		);
		Prompts::register(
			'insights',
			array(
				'version'     => '1',
				'task'        => 'insights',
				'temperature' => 0.3,
				'profile'     => 'fast',
				'cache'       => 6 * HOUR_IN_SECONDS,
				'system'      => 'You are a WooCommerce cart-recovery analyst. Explain the numbers in plain language for a store owner. Only use the numbers given; do not invent figures.',
				'template'    => "Stats for the last {{days}} days: {{stats}}\nStep funnel: {{funnel}}\nTop abandoned products: {{products}}\nReturn a one-sentence headline, up to 5 findings and up to 5 concrete actions.",
				'schema'      => array(
					'type'       => 'OBJECT',
					'properties' => array(
						'headline' => $str( 200 ),
						'findings' => array(
							'type'  => 'ARRAY',
							'items' => $str( 300 ),
						),
						'actions'  => array(
							'type'  => 'ARRAY',
							'items' => $str( 300 ),
						),
					),
					'required'   => array( 'headline', 'findings', 'actions' ),
				),
			)
		);
	}

	/**
	 * Whether AI copy can be generated now.
	 *
	 * @param string $context Context.
	 * @return bool
	 */
	public static function available( $context = 'cron' ) {
		return Ai::enabled( $context );
	}

	/**
	 * A1: personalised copy for one cart and step (minimised input: first name, items, total).
	 *
	 * @param array  $cart    Cart.
	 * @param array  $tpl     Template.
	 * @param array  $ctx     { step_number, step_count, coupon (array|null) }.
	 * @param string $context Usage context.
	 * @return array|\WP_Error { draft, model, tokens }
	 */
	public static function cart_copy( array $cart, array $tpl, array $ctx, $context = 'cron' ) {
		$items = array();
		foreach ( array_slice( (array) $cart['items'], 0, 8 ) as $item ) {
			$cats = array();
			if ( ! empty( $item['product_id'] ) ) {
				$terms = get_the_terms( (int) $item['product_id'], 'product_cat' );
				$cats  = is_array( $terms ) ? wp_list_pluck( $terms, 'name' ) : array();
			}
			$items[] = array(
				'name'       => (string) $item['name'],
				'quantity'   => (int) $item['quantity'],
				'categories' => array_slice( $cats, 0, 3 ),
			);
		}
		$coupon = isset( $ctx['coupon'] ) ? $ctx['coupon'] : null;
		$copy   = Renderer::template_copy( $tpl );
		$result = Prompts::run(
			'cart_copy',
			array(
				'store'       => html_entity_decode( get_bloginfo( 'name' ), ENT_QUOTES, 'UTF-8' ),
				'kind'        => 'winback' === $cart['kind'] ? 'win-back email to a past customer' : ( 'sms' === $tpl['channel'] ? 'abandoned-cart SMS' : 'abandoned-cart email' ),
				'step_number' => (int) $ctx['step_number'],
				'step_count'  => (int) $ctx['step_count'],
				'first_name'  => '' !== (string) $cart['first_name'] ? (string) $cart['first_name'] : 'unknown (do not use a name)',
				'items'       => $items,
				'total'       => html_entity_decode( wp_strip_all_tags( wc_price( (float) $cart['total'], array( 'currency' => $cart['currency'] ) ) ), ENT_QUOTES, 'UTF-8' ),
				'offer'       => $coupon ? sprintf( '%s off, valid until %s', $coupon['amount_label'], $coupon['expiry_label'] ) : 'none',
				'template'    => 'sms' === $tpl['channel'] ? $copy['sms'] : wp_strip_all_tags( "Subject: {$copy['subject']}\nHeading: {$copy['heading']}\n{$copy['body']}" ),
				'locale'      => '' !== (string) $cart['locale'] ? (string) $cart['locale'] : get_locale(),
				'tone'        => (string) Settings::get( 'brand_tone' ),
				'brand_notes' => '' !== (string) Settings::get( 'brand_notes' ) ? (string) Settings::get( 'brand_notes' ) : 'none',
			),
			$context
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return array(
			'draft'  => self::clean_copy( isset( $result['data'] ) ? (array) $result['data'] : array() ),
			'model'  => $result['model'],
			'tokens' => isset( $result['usage']['total'] ) ? (int) $result['usage']['total'] : 0,
		);
	}

	/**
	 * Sanitises an AI cart-copy draft.
	 *
	 * @param array $data Raw.
	 * @return array
	 */
	public static function clean_copy( array $data ) {
		$limits = array(
			'subject'   => 90,
			'preheader' => 140,
			'heading'   => 80,
			'intro'     => 600,
			'button'    => 40,
			'sms'       => 300,
		);
		$out    = array();
		foreach ( $limits as $key => $max ) {
			$value       = isset( $data[ $key ] ) ? (string) $data[ $key ] : '';
			$out[ $key ] = 'intro' === $key ? mb_substr( sanitize_textarea_field( $value ), 0, $max ) : mb_substr( sanitize_text_field( $value ), 0, $max );
		}
		return $out;
	}

	/**
	 * A2: template draft.
	 *
	 * @param array $brief { goal, tone, offer, channel }.
	 * @return array|\WP_Error
	 */
	public static function template( array $brief ) {
		$result = Prompts::run(
			'template_writer',
			array(
				'store'       => html_entity_decode( get_bloginfo( 'name' ), ENT_QUOTES, 'UTF-8' ),
				'channel'     => 'sms' === ( isset( $brief['channel'] ) ? $brief['channel'] : '' ) ? 'SMS' : 'email',
				'goal'        => mb_substr( (string) ( isset( $brief['goal'] ) ? $brief['goal'] : '' ), 0, 400 ),
				'offer'       => '' !== (string) ( isset( $brief['offer'] ) ? $brief['offer'] : '' ) ? mb_substr( (string) $brief['offer'], 0, 120 ) : 'none',
				'tone'        => ! empty( $brief['tone'] ) ? (string) $brief['tone'] : (string) Settings::get( 'brand_tone' ),
				'brand_notes' => '' !== (string) Settings::get( 'brand_notes' ) ? (string) Settings::get( 'brand_notes' ) : 'none',
			),
			'admin'
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$d     = isset( $result['data'] ) ? (array) $result['data'] : array();
		$ideas = array();
		foreach ( isset( $d['subject_ideas'] ) && is_array( $d['subject_ideas'] ) ? array_slice( $d['subject_ideas'], 0, 5 ) : array() as $idea ) {
			$ideas[] = mb_substr( sanitize_text_field( (string) $idea ), 0, 90 );
		}
		return array(
			'draft'  => array(
				'subject'   => mb_substr( sanitize_text_field( (string) ( isset( $d['subject'] ) ? $d['subject'] : '' ) ), 0, 150 ),
				'preheader' => mb_substr( sanitize_text_field( (string) ( isset( $d['preheader'] ) ? $d['preheader'] : '' ) ), 0, 200 ),
				'heading'   => mb_substr( sanitize_text_field( (string) ( isset( $d['heading'] ) ? $d['heading'] : '' ) ), 0, 120 ),
				'body'      => mb_substr( sanitize_textarea_field( (string) ( isset( $d['body'] ) ? $d['body'] : '' ) ), 0, 1200 ),
				'button'    => mb_substr( sanitize_text_field( (string) ( isset( $d['button'] ) ? $d['button'] : '' ) ), 0, 60 ),
				'sms'       => mb_substr( sanitize_textarea_field( (string) ( isset( $d['sms'] ) ? $d['sms'] : '' ) ), 0, 480 ),
			),
			'ideas'  => $ideas,
			'model'  => $result['model'],
			'tokens' => isset( $result['usage']['total'] ) ? (int) $result['usage']['total'] : 0,
			'cached' => ! empty( $result['cached'] ),
		);
	}

	/**
	 * A3: sequence draft. Unknown template ids are dropped; channels follow the template.
	 *
	 * @param string $type  cart|winback.
	 * @param array  $stats Aggregated stats.
	 * @param bool   $sms   SMS available.
	 * @return array|\WP_Error
	 */
	public static function sequence( $type, array $stats, $sms ) {
		$templates = array();
		foreach ( Templates::all() as $tpl ) {
			if ( 'approved' === $tpl['status'] && ( $sms || 'email' === $tpl['channel'] ) ) {
				$templates[] = array(
					'id'      => $tpl['id'],
					'name'    => $tpl['name'],
					'channel' => $tpl['channel'],
				);
			}
		}
		if ( ! $templates ) {
			return new \WP_Error( 'dev3ac_no_templates', __( 'Approve at least one template first.', 'dev3-ai-abandoned-cart-for-woocommerce' ), array( 'status' => 400 ) );
		}
		$result = Prompts::run(
			'sequence_builder',
			array(
				'type'      => 'winback' === $type ? 'win-back (past customers who have not ordered recently)' : 'abandoned cart recovery',
				'stats'     => $stats,
				'sms'       => $sms ? 'true' : 'false',
				'templates' => $templates,
			),
			'admin'
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$ids   = wp_list_pluck( $templates, 'channel', 'id' );
		$steps = array();
		foreach ( isset( $result['data']['steps'] ) && is_array( $result['data']['steps'] ) ? array_slice( $result['data']['steps'], 0, 6 ) : array() as $s ) {
			$tid = isset( $s['template_id'] ) ? sanitize_key( (string) $s['template_id'] ) : '';
			if ( ! isset( $ids[ $tid ] ) ) {
				continue;
			}
			$step           = \Dev3\AiAbandonedCart\Data\Sequences::sanitize_step(
				array(
					'delay_value' => isset( $s['delay_value'] ) ? $s['delay_value'] : 1,
					'delay_unit'  => isset( $s['delay_unit'] ) ? $s['delay_unit'] : 'hours',
					'channel'     => $ids[ $tid ],
					'template_id' => $tid,
					'coupon'      => array(
						'enabled'     => ! empty( $s['coupon_enabled'] ),
						'type'        => isset( $s['coupon_type'] ) ? $s['coupon_type'] : 'percent',
						'amount'      => isset( $s['coupon_amount'] ) ? min( 25, (float) $s['coupon_amount'] ) : 10,
						'expiry_days' => isset( $s['coupon_expiry_days'] ) ? $s['coupon_expiry_days'] : 3,
					),
				)
			);
			$step['reason'] = mb_substr( sanitize_text_field( (string) ( isset( $s['reason'] ) ? $s['reason'] : '' ) ), 0, 240 );
			$steps[]        = $step;
		}
		if ( ! $steps ) {
			return new \WP_Error( 'dev3ac_empty_draft', __( 'DEV3 AI did not return usable steps. Try again.', 'dev3-ai-abandoned-cart-for-woocommerce' ), array( 'status' => 502 ) );
		}
		return array(
			'steps'   => $steps,
			'summary' => mb_substr( sanitize_text_field( (string) ( isset( $result['data']['summary'] ) ? $result['data']['summary'] : '' ) ), 0, 500 ),
			'model'   => $result['model'],
			'tokens'  => isset( $result['usage']['total'] ) ? (int) $result['usage']['total'] : 0,
		);
	}

	/**
	 * A4: send-time settings draft.
	 *
	 * @param array $histogram { opens, clicks, orders }.
	 * @return array|\WP_Error
	 */
	public static function send_time( array $histogram ) {
		$result = Prompts::run(
			'send_time',
			array(
				'opens'   => array_values( $histogram['opens'] ),
				'clicks'  => array_values( $histogram['clicks'] ),
				'orders'  => array_values( $histogram['orders'] ),
				'current' => array(
					'best_hours'      => Settings::get( 'best_hours' ),
					'quiet_start'     => Settings::get( 'quiet_start' ),
					'quiet_end'       => Settings::get( 'quiet_end' ),
					'max_shift_hours' => Settings::get( 'max_shift_hours' ),
				),
			),
			'admin'
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$d     = (array) $result['data'];
		$hours = array();
		foreach ( isset( $d['best_hours'] ) && is_array( $d['best_hours'] ) ? $d['best_hours'] : array() as $h ) {
			if ( (int) $h >= 0 && (int) $h <= 23 ) {
				$hours[] = (int) $h;
			}
		}
		$hours = array_slice( array_values( array_unique( $hours ) ), 0, 4 );
		return array(
			'draft'       => array(
				'best_hours'      => $hours ? $hours : array( 10, 19 ),
				'quiet_start'     => min( 23, max( 0, (int) ( isset( $d['quiet_start'] ) ? $d['quiet_start'] : 21 ) ) ),
				'quiet_end'       => min( 23, max( 0, (int) ( isset( $d['quiet_end'] ) ? $d['quiet_end'] : 8 ) ) ),
				'max_shift_hours' => min( 12, max( 0, (int) ( isset( $d['max_shift_hours'] ) ? $d['max_shift_hours'] : 6 ) ) ),
			),
			'explanation' => mb_substr( sanitize_text_field( (string) ( isset( $d['explanation'] ) ? $d['explanation'] : '' ) ), 0, 500 ),
			'model'       => $result['model'],
			'tokens'      => isset( $result['usage']['total'] ) ? (int) $result['usage']['total'] : 0,
		);
	}

	/**
	 * A5: read-only recovery insights.
	 *
	 * @param array $dashboard Dashboard payload.
	 * @return array|\WP_Error
	 */
	public static function insights( array $dashboard ) {
		$result = Prompts::run(
			'insights',
			array(
				'days'     => (int) $dashboard['days'],
				'stats'    => $dashboard['kpis'],
				'funnel'   => $dashboard['funnel'],
				'products' => array_slice( $dashboard['top_products'], 0, 8 ),
			),
			'admin'
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$d    = (array) $result['data'];
		$list = static function ( $key ) use ( $d ) {
			$out = array();
			foreach ( isset( $d[ $key ] ) && is_array( $d[ $key ] ) ? array_slice( $d[ $key ], 0, 5 ) : array() as $line ) {
				$out[] = mb_substr( sanitize_text_field( (string) $line ), 0, 300 );
			}
			return $out;
		};
		return array(
			'headline' => mb_substr( sanitize_text_field( (string) ( isset( $d['headline'] ) ? $d['headline'] : '' ) ), 0, 200 ),
			'findings' => $list( 'findings' ),
			'actions'  => $list( 'actions' ),
			'model'    => $result['model'],
			'cached'   => ! empty( $result['cached'] ),
		);
	}
}
