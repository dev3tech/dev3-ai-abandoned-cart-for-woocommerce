<?php
/**
 * Merge tags and email/SMS rendering.
 *
 * @package Dev3\AiAbandonedCart
 */

namespace Dev3\AiAbandonedCart\Engine;

use Dev3\AiAbandonedCart\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Renders a template (or reviewed AI copy) for one cart.
 *
 * Copy array: { subject, preheader, heading, body, button, sms }. Template body is admin HTML (kses'd);
 * AI copy is plain text and is escaped before it is placed into the email.
 */
class Renderer {

	const TAGS = array( 'first_name', 'store_name', 'cart_items', 'cart_total', 'item_count', 'top_product', 'recovery_url', 'recovery_button', 'coupon_code', 'coupon_amount', 'coupon_expiry', 'unsubscribe_url' );

	/**
	 * Copy straight from a template.
	 *
	 * @param array $tpl Template.
	 * @return array
	 */
	public static function template_copy( array $tpl ) {
		return array(
			'subject'   => $tpl['subject'],
			'preheader' => $tpl['preheader'],
			'heading'   => $tpl['heading'],
			'body'      => $tpl['body'],
			'button'    => $tpl['button'],
			'sms'       => $tpl['sms'],
			'is_ai'     => false,
		);
	}

	/**
	 * Copy from an approved AI draft (fields the AI did not return fall back to the template).
	 *
	 * @param array $tpl   Template.
	 * @param array $draft AI draft { subject, preheader, heading, intro, button, sms }.
	 * @return array
	 */
	public static function ai_copy( array $tpl, array $draft ) {
		$base = self::template_copy( $tpl );
		foreach ( array( 'subject', 'preheader', 'heading', 'button', 'sms' ) as $key ) {
			if ( isset( $draft[ $key ] ) && '' !== trim( (string) $draft[ $key ] ) ) {
				$base[ $key ] = (string) $draft[ $key ];
			}
		}
		if ( isset( $draft['intro'] ) && '' !== trim( (string) $draft['intro'] ) ) {
			$base['body'] = (string) $draft['intro'];
		}
		$base['is_ai'] = true;
		return $base;
	}

	/**
	 * Merge-tag values.
	 *
	 * @param array      $cart       Cart.
	 * @param int        $message_id Message id.
	 * @param array|null $coupon     Coupon description.
	 * @param string     $format     html|text.
	 * @return array
	 */
	public static function tags( array $cart, $message_id, $coupon, $format = 'html' ) {
		$items    = (array) $cart['items'];
		$recovery = Links::recovery( $cart, $message_id );
		$total    = html_entity_decode( wp_strip_all_tags( wc_price( (float) $cart['total'], array( 'currency' => $cart['currency'] ? $cart['currency'] : get_woocommerce_currency() ) ) ), ENT_QUOTES, 'UTF-8' );
		$tags     = array(
			'first_name'      => '' !== (string) $cart['first_name'] ? (string) $cart['first_name'] : __( 'there', 'dev3-ai-abandoned-cart-for-woocommerce' ),
			'store_name'      => html_entity_decode( get_bloginfo( 'name' ), ENT_QUOTES, 'UTF-8' ),
			'cart_total'      => $total,
			'item_count'      => (string) (int) $cart['item_count'],
			'top_product'     => $items ? (string) $items[0]['name'] : __( 'your items', 'dev3-ai-abandoned-cart-for-woocommerce' ),
			'recovery_url'    => $recovery,
			'coupon_code'     => $coupon ? $coupon['code'] : '',
			'coupon_amount'   => $coupon ? $coupon['amount_label'] : '',
			'coupon_expiry'   => $coupon ? $coupon['expiry_label'] : '',
			'unsubscribe_url' => Links::unsubscribe( $cart ),
		);
		if ( 'html' === $format ) {
			$tags = array_map( 'esc_html', $tags );
			// URLs keep their raw form (attribute escaping happens in the markup that uses them).
			$tags['recovery_url']    = esc_url( $recovery );
			$tags['unsubscribe_url'] = esc_url( Links::unsubscribe( $cart ) );
			$tags['cart_items']      = self::items_table( $cart );
			$tags['recovery_button'] = self::button( $recovery, __( 'Return to my cart', 'dev3-ai-abandoned-cart-for-woocommerce' ) );
		} else {
			$names = array();
			foreach ( $items as $item ) {
				$names[] = (int) $item['quantity'] . '× ' . $item['name'];
			}
			$tags['cart_items']      = implode( ', ', $names );
			$tags['recovery_button'] = $recovery;
		}
		/**
		 * Filters merge-tag values.
		 *
		 * @param array  $tags       Tag => value.
		 * @param array  $cart       Cart.
		 * @param int    $message_id Message id.
		 * @param string $format     html|text.
		 */
		return apply_filters( 'dev3ac_merge_tags', $tags, $cart, $message_id, $format );
	}

	/**
	 * Replaces {tags}.
	 *
	 * @param string $text Text.
	 * @param array  $tags Values.
	 * @return string
	 */
	public static function merge( $text, array $tags ) {
		return (string) preg_replace_callback(
			'/\{([a-z_]+)\}/',
			static function ( $m ) use ( $tags ) {
				return array_key_exists( $m[1], $tags ) ? (string) $tags[ $m[1] ] : $m[0];
			},
			(string) $text
		);
	}

	/**
	 * Full email.
	 *
	 * @param array      $cart       Cart.
	 * @param array      $tpl        Template.
	 * @param array      $copy       Copy.
	 * @param int        $message_id Message id (0 = preview).
	 * @param array|null $coupon     Coupon.
	 * @return array { subject, html, preheader }
	 */
	public static function email( array $cart, array $tpl, array $copy, $message_id, $coupon ) {
		$text = self::tags( $cart, $message_id, $coupon, 'text' );
		$html = self::tags( $cart, $message_id, $coupon, 'html' );

		$subject   = wp_strip_all_tags( self::merge( $copy['subject'], $text ) );
		$preheader = wp_strip_all_tags( self::merge( $copy['preheader'], $text ) );
		$heading   = wp_strip_all_tags( self::merge( $copy['heading'], $text ) );
		$button    = wp_strip_all_tags( self::merge( '' !== $copy['button'] ? $copy['button'] : __( 'Return to my cart', 'dev3-ai-abandoned-cart-for-woocommerce' ), $text ) );
		if ( ! empty( $copy['is_ai'] ) ) {
			// AI copy is plain text: escape first, then merge the (already escaped) tag values.
			$body = wpautop( self::merge( esc_html( $copy['body'] ), $html ) );
		} else {
			$body = wpautop( self::merge( wp_kses_post( $copy['body'] ), $html ) );
		}

		$content  = '<div style="display:none;max-height:0;overflow:hidden;opacity:0;">' . esc_html( $preheader ) . '</div>';
		$content .= $body;
		if ( $coupon ) {
			$content .= self::coupon_box( $coupon );
		}
		if ( ! empty( $tpl['show_cart'] ) && ! empty( $cart['items'] ) ) {
			$content .= self::items_table( $cart );
		}
		$content .= self::button( Links::recovery( $cart, $message_id ), $button );
		$content .= '<p style="font-size:12px;color:#6b7280;margin-top:28px;">' . sprintf(
			/* translators: %s: unsubscribe link */
			esc_html__( 'You are receiving this because you started an order with us. %s', 'dev3-ai-abandoned-cart-for-woocommerce' ),
			'<a href="' . esc_url( Links::unsubscribe( $cart ) ) . '" style="color:#6b7280;">' . esc_html__( 'Unsubscribe', 'dev3-ai-abandoned-cart-for-woocommerce' ) . '</a>'
		) . '</p>';
		if ( $message_id ) {
			$content .= '<img src="' . esc_url( Links::pixel( $message_id ) ) . '" width="1" height="1" alt="" style="display:block;border:0;width:1px;height:1px;" />';
		}

		$wrapped = $content;
		if ( function_exists( 'WC' ) && WC()->mailer() ) {
			$wrapped = WC()->mailer()->wrap_message( $heading, $content );
			$email   = new \WC_Email();
			$wrapped = $email->style_inline( $wrapped );
		}
		return array(
			'subject'   => $subject,
			'preheader' => $preheader,
			'heading'   => $heading,
			'html'      => $wrapped,
		);
	}

	/**
	 * SMS text.
	 *
	 * @param array      $cart       Cart.
	 * @param array      $copy       Copy.
	 * @param int        $message_id Message id.
	 * @param array|null $coupon     Coupon.
	 * @return string
	 */
	public static function sms( array $cart, array $copy, $message_id, $coupon ) {
		$text = self::tags( $cart, $message_id, $coupon, 'text' );
		$body = trim( wp_strip_all_tags( self::merge( $copy['sms'], $text ) ) );
		if ( false === strpos( $body, $text['recovery_url'] ) ) {
			$body .= ' ' . $text['recovery_url'];
		}
		if ( false === stripos( $body, 'STOP' ) ) {
			$body .= ' ' . __( 'Reply STOP to opt out.', 'dev3-ai-abandoned-cart-for-woocommerce' );
		}
		return $body;
	}

	/**
	 * Cart items table.
	 *
	 * @param array $cart Cart.
	 * @return string
	 */
	public static function items_table( array $cart ) {
		$currency = $cart['currency'] ? $cart['currency'] : get_woocommerce_currency();
		$rows     = '';
		foreach ( (array) $cart['items'] as $item ) {
			$image = '';
			$pid   = ! empty( $item['variation_id'] ) ? (int) $item['variation_id'] : (int) $item['product_id'];
			$thumb = get_post_thumbnail_id( $pid );
			if ( ! $thumb && ! empty( $item['product_id'] ) ) {
				$thumb = get_post_thumbnail_id( (int) $item['product_id'] );
			}
			$src = $thumb ? wp_get_attachment_image_url( $thumb, 'thumbnail' ) : ( function_exists( 'wc_placeholder_img_src' ) ? wc_placeholder_img_src( 'thumbnail' ) : '' );
			if ( $src ) {
				$image = '<img src="' . esc_url( $src ) . '" width="56" height="56" alt="" style="display:block;width:56px;height:56px;object-fit:cover;border-radius:6px;" />';
			}
			$meta = '';
			if ( ! empty( $item['variation'] ) && is_array( $item['variation'] ) ) {
				$meta = '<br><span style="font-size:12px;color:#6b7280;">' . esc_html( implode( ', ', array_map( 'strval', $item['variation'] ) ) ) . '</span>';
			}
			$rows .= '<tr><td style="padding:8px 8px 8px 0;width:64px;vertical-align:top;">' . $image . '</td><td style="padding:8px 0;vertical-align:top;">' . esc_html( $item['name'] ) . $meta . '<br><span style="font-size:12px;color:#6b7280;">' . esc_html(
				/* translators: %d: quantity */
				sprintf( __( 'Qty %d', 'dev3-ai-abandoned-cart-for-woocommerce' ), (int) $item['quantity'] )
			) . '</span></td><td style="padding:8px 0;text-align:right;vertical-align:top;white-space:nowrap;">' . wp_kses_post( wc_price( (float) $item['line_total'], array( 'currency' => $currency ) ) ) . '</td></tr>';
		}
		$rows .= '<tr><td></td><td style="padding:10px 0;border-top:1px solid #e5e7eb;font-weight:bold;">' . esc_html__( 'Total', 'dev3-ai-abandoned-cart-for-woocommerce' ) . '</td><td style="padding:10px 0;border-top:1px solid #e5e7eb;text-align:right;font-weight:bold;white-space:nowrap;">' . wp_kses_post( wc_price( (float) $cart['total'], array( 'currency' => $currency ) ) ) . '</td></tr>';
		return '<table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="width:100%;margin:16px 0;border-collapse:collapse;">' . $rows . '</table>';
	}

	/**
	 * Bulletproof button.
	 *
	 * @param string $url   URL.
	 * @param string $label Label.
	 * @return string
	 */
	public static function button( $url, $label ) {
		$color = get_option( 'woocommerce_email_base_color', '#7f54b3' );
		$color = sanitize_hex_color( $color ) ? $color : '#7f54b3';
		return '<table role="presentation" cellspacing="0" cellpadding="0" border="0" style="margin:20px 0;"><tr><td style="border-radius:6px;background:' . esc_attr( $color ) . ';"><a href="' . esc_url( $url ) . '" style="display:inline-block;padding:12px 22px;color:#ffffff;font-weight:bold;text-decoration:none;border-radius:6px;">' . esc_html( $label ) . '</a></td></tr></table>';
	}

	/**
	 * Coupon box.
	 *
	 * @param array $coupon Coupon.
	 * @return string
	 */
	public static function coupon_box( array $coupon ) {
		$line = $coupon['expiry_label']
			/* translators: 1: discount, 2: expiry date */
			? sprintf( __( '%1$s off · valid until %2$s', 'dev3-ai-abandoned-cart-for-woocommerce' ), $coupon['amount_label'], $coupon['expiry_label'] )
			/* translators: %s: discount */
			: sprintf( __( '%s off', 'dev3-ai-abandoned-cart-for-woocommerce' ), $coupon['amount_label'] );
		return '<table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="margin:16px 0;border:2px dashed #d1d5db;border-radius:8px;"><tr><td style="padding:14px;text-align:center;"><span style="font-size:12px;color:#6b7280;text-transform:uppercase;letter-spacing:.06em;">' . esc_html__( 'Your code', 'dev3-ai-abandoned-cart-for-woocommerce' ) . '</span><br><strong style="font-size:20px;letter-spacing:.08em;">' . esc_html( $coupon['code'] ) . '</strong><br><span style="font-size:13px;color:#374151;">' . esc_html( $line ) . '</span></td></tr></table>';
	}

	/**
	 * Demo cart for previews.
	 *
	 * @return array
	 */
	public static function demo_cart() {
		$items    = array();
		$products = function_exists( 'wc_get_products' ) ? wc_get_products(
			array(
				'limit'  => 2,
				'status' => 'publish',
				'type'   => array( 'simple' ),
			)
		) : array();
		foreach ( $products as $product ) {
			$items[] = array(
				'product_id'   => $product->get_id(),
				'variation_id' => 0,
				'quantity'     => 1,
				'variation'    => array(),
				'name'         => $product->get_name(),
				'line_total'   => (float) $product->get_price(),
			);
		}
		if ( ! $items ) {
			$items[] = array(
				'product_id'   => 0,
				'variation_id' => 0,
				'quantity'     => 2,
				'variation'    => array(),
				'name'         => __( 'Sample product', 'dev3-ai-abandoned-cart-for-woocommerce' ),
				'line_total'   => 48,
			);
		}
		return array(
			'id'           => 0,
			'token'        => str_repeat( '0', 32 ),
			'first_name'   => __( 'Alex', 'dev3-ai-abandoned-cart-for-woocommerce' ),
			'email'        => 'alex@example.com',
			'items'        => $items,
			'item_count'   => array_sum( wp_list_pluck( $items, 'quantity' ) ),
			'total'        => array_sum( wp_list_pluck( $items, 'line_total' ) ),
			'currency'     => function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'USD',
			'coupon_codes' => array(),
		);
	}

	/**
	 * From name/address filters for our sends.
	 *
	 * @return array { name, email }
	 */
	public static function sender() {
		return array(
			'name'  => (string) Settings::get( 'from_name' ),
			'email' => (string) Settings::get( 'from_email' ),
		);
	}
}
