<?php
use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;
use Automattic\WooCommerce\Blocks\Payments\PaymentResult;
use Automattic\WooCommerce\Blocks\Payments\PaymentContext;

defined( 'ABSPATH' ) || exit;

/**
 * WC_Stripe_Blocks_Support class.
 *
 * @extends AbstractPaymentMethodType
 */
final class WC_Stripe_Blocks_Support extends AbstractPaymentMethodType {
	/**
	 * Payment method name defined by payment methods extending this class.
	 *
	 * @var string
	 */
	protected $name = 'stripe';

	/**
	 * Constructor
	 */
	public function __construct() {
		add_action( 'woocommerce_rest_checkout_process_payment_with_context', [ $this, 'add_payment_request_order_meta' ], 8, 2 );
		add_action( 'woocommerce_rest_checkout_process_payment_with_context', [ $this, 'add_stripe_intents' ], 9999, 2 );
	}

	/**
	 * Initializes the payment method type.
	 */
	public function initialize() {
		$this->settings = get_option( 'woocommerce_stripe_settings', [] );
	}

	/**
	 * Returns if this payment method should be active. If false, the scripts will not be enqueued.
	 *
	 * @return boolean
	 */
	public function is_active() {
		return ! empty( $this->settings['enabled'] ) && 'yes' === $this->settings['enabled'];
	}

	/**
	 * Returns an array of scripts/handles to be registered for this payment method.
	 *
	 * @return array
	 */
	public function get_payment_method_script_handles() {
		$asset_path   = WC_STRIPE_PLUGIN_PATH . '/build/index.asset.php';
		$version      = WC_STRIPE_VERSION;
		$dependencies = [];
		if ( file_exists( $asset_path ) ) {
			$asset        = require $asset_path;
			$version      = is_array( $asset ) && isset( $asset['version'] )
				? $asset['version']
				: $version;
			$dependencies = is_array( $asset ) && isset( $asset['dependencies'] )
				? $asset['dependencies']
				: $dependencies;
		}
		wp_register_script(
			'wc-stripe-blocks-integration',
			WC_STRIPE_PLUGIN_URL . '/build/index.js',
			$dependencies,
			$version,
			true
		);
		wp_set_script_translations(
			'wc-stripe-blocks-integration',
			'woocommerce-gateway-stripe'
		);

		return [ 'wc-stripe-blocks-integration' ];
	}

	/**
	 * Returns an array of key=>value pairs of data made available to the payment methods script.
	 *
	 * @return array
	 */
	public function get_payment_method_data() {
		return [
			'stripeTotalLabel'    => $this->get_total_label(),
			'publicKey'           => $this->get_publishable_key(),
			'allowPrepaidCard'    => $this->get_allow_prepaid_card(),
			'title'               => $this->get_title(),
			'button'              => [
				'type'        => $this->get_button_type(),
				'theme'       => $this->get_button_theme(),
				'height'      => $this->get_button_height(),
				'locale'      => $this->get_button_locale(),
				'customLabel' => isset( $this->settings['payment_request_button_label'] ) ? $this->settings['payment_request_button_label'] : 'Buy now',
			],
			'inline_cc_form'      => $this->get_inline_cc_form(),
			'icons'               => $this->get_icons(),
			'showSavedCards'      => $this->get_show_saved_cards(),
			'showSaveOption'      => $this->get_show_save_option(),
			'supports'            => $this->get_supported_features(),
		];
	}

	/**
	 * Determine if store allows cards to be saved during checkout.
	 *
	 * @return bool True if merchant allows shopper to save card (payment method) during checkout.
	 */
	private function get_show_saved_cards() {
		return isset( $this->settings['saved_cards'] ) ? 'yes' === $this->settings['saved_cards'] : false;
	}

	/**
	 * Determine if the checkbox to enable the user to save their payment method should be shown.
	 *
	 * @return bool True if the save payment checkbox should be displayed to the user.
	 */
	private function get_show_save_option() {
		$saved_cards = $this->get_show_saved_cards();
		// This assumes that Stripe supports `tokenization` - currently this is true, based on
		// https://github.com/woocommerce/woocommerce-gateway-stripe/blob/master/includes/class-wc-gateway-stripe.php#L95 .
		// See https://github.com/woocommerce/woocommerce-gateway-stripe/blob/ad19168b63df86176cbe35c3e95203a245687640/includes/class-wc-gateway-stripe.php#L271 and
		// https://github.com/woocommerce/woocommerce/wiki/Payment-Token-API .
		return apply_filters( 'wc_stripe_display_save_payment_method_checkbox', filter_var( $saved_cards, FILTER_VALIDATE_BOOLEAN ) );
	}

	/**
	 * Returns the label to use accompanying the total in the stripe statement.
	 *
	 * @return string Statement descriptor.
	 */
	private function get_total_label() {
		return ! empty( $this->settings['statement_descriptor'] ) ? WC_Stripe_Helper::clean_statement_descriptor( $this->settings['statement_descriptor'] ) : '';
	}

	/**
	 * Returns the publishable api key for the Stripe service.
	 *
	 * @return string Public api key.
	 */
	private function get_publishable_key() {
		$test_mode   = ( ! empty( $this->settings['testmode'] ) && 'yes' === $this->settings['testmode'] );
		$setting_key = $test_mode ? 'test_publishable_key' : 'publishable_key';
		return ! empty( $this->settings[ $setting_key ] ) ? $this->settings[ $setting_key ] : '';
	}

	/**
	 * Returns whether to allow prepaid cards for payments.
	 *
	 * @return bool True means to allow prepaid card (default).
	 */
	private function get_allow_prepaid_card() {
		return apply_filters( 'wc_stripe_allow_prepaid_card', true );
	}

	/**
	 * Returns the title string to use in the UI (customisable via admin settings screen).
	 *
	 * @return string Title / label string
	 */
	private function get_title() {
		return isset( $this->settings['title'] ) ? $this->settings['title'] : __( 'Credit / Debit Card', 'woocommerce-gateway-stripe' );
	}

	/**
	 * Return the button type for the payment button.
	 *
	 * @return string Defaults to 'default'.
	 */
	private function get_button_type() {
		return isset( $this->settings['payment_request_button_type'] ) ? $this->settings['payment_request_button_type'] : 'default';
	}

	/**
	 * Return the theme to use for the payment button.
	 *
	 * @return string Defaults to 'dark'.
	 */
	private function get_button_theme() {
		return isset( $this->settings['payment_request_button_theme'] ) ? $this->settings['payment_request_button_theme'] : 'dark';
	}

	/**
	 * Return the height for the payment button.
	 *
	 * @return string A pixel value for the height (defaults to '64').
	 */
	private function get_button_height() {
		return isset( $this->settings['payment_request_button_height'] ) ? str_replace( 'px', '', $this->settings['payment_request_button_height'] ) : '64';
	}

	/**
	 * Return the inline cc option.
	 *
	 * @return boolean True if the inline CC form option is enabled.
	 */
	private function get_inline_cc_form() {
		return isset( $this->settings['inline_cc_form'] ) && 'yes' === $this->settings['inline_cc_form'];
	}

	/**
	 * Return the locale for the payment button.
	 *
	 * @return string Defaults to en_US.
	 */
	private function get_button_locale() {
		return apply_filters( 'wc_stripe_payment_request_button_locale', substr( get_locale(), 0, 2 ) );
	}

	/**
	 * Return the icons urls.
	 *
	 * @return array Arrays of icons metadata.
	 */
	private function get_icons() {
		$icons_src = [
			'visa'       => [
				'src' => WC_STRIPE_PLUGIN_URL . '/assets/images/visa.svg',
				'alt' => __( 'Visa', 'woocommerce-gateway-stripe' ),
			],
			'amex'       => [
				'src' => WC_STRIPE_PLUGIN_URL . '/assets/images/amex.svg',
				'alt' => __( 'American Express', 'woocommerce-gateway-stripe' ),
			],
			'mastercard' => [
				'src' => WC_STRIPE_PLUGIN_URL . '/assets/images/mastercard.svg',
				'alt' => __( 'Mastercard', 'woocommerce-gateway-stripe' ),
			],
		];

		if ( 'USD' === get_woocommerce_currency() ) {
			$icons_src['discover'] = [
				'src' => WC_STRIPE_PLUGIN_URL . '/assets/images/discover.svg',
				'alt' => __( 'Discover', 'woocommerce-gateway-stripe' ),
			];
			$icons_src['jcb']      = [
				'src' => WC_STRIPE_PLUGIN_URL . '/assets/images/jcb.svg',
				'alt' => __( 'JCB', 'woocommerce-gateway-stripe' ),
			];
			$icons_src['diners']   = [
				'src' => WC_STRIPE_PLUGIN_URL . '/assets/images/diners.svg',
				'alt' => __( 'Diners', 'woocommerce-gateway-stripe' ),
			];
		}
		return $icons_src;
	}

	/**
	 * Add payment request data to the order meta as hooked on the
	 * woocommerce_rest_checkout_process_payment_with_context action.
	 *
	 * @param PaymentContext $context Holds context for the payment.
	 * @param PaymentResult  $result  Result object for the payment.
	 */
	public function add_payment_request_order_meta( PaymentContext $context, PaymentResult &$result ) {
		$data = $context->payment_data;
		if ( ! empty( $data['payment_request_type'] ) && 'stripe' === $context->payment_method ) {
			$this->add_order_meta( $context->order, $data['payment_request_type'] );
		}

		// hook into stripe error processing so that we can capture the error to
		// payment details (which is added to notices and thus not helpful for
		// this context).
		if ( 'stripe' === $context->payment_method ) {
			add_action(
				'wc_gateway_stripe_process_payment_error',
				function( $error ) use ( &$result ) {
					$payment_details                 = $result->payment_details;
					$payment_details['errorMessage'] = wp_strip_all_tags( $error->getLocalizedMessage() );
					$result->set_payment_details( $payment_details );
				}
			);
		}
	}

	/**
	 * Handles any potential stripe intents on the order that need handled.
	 *
	 * This is configured to execute after legacy payment processing has
	 * happened on the woocommerce_rest_checkout_process_payment_with_context
	 * action hook.
	 *
	 * @param PaymentContext $context Holds context for the payment.
	 * @param PaymentResult  $result  Result object for the payment.
	 */
	public function add_stripe_intents( PaymentContext $context, PaymentResult &$result ) {
		if ( 'stripe' === $context->payment_method
			&& (
				! empty( $result->payment_details['payment_intent_secret'] )
				|| ! empty( $result->payment_details['setup_intent_secret'] )
			)
		) {
			$payment_details                          = $result->payment_details;
			$payment_details['verification_endpoint'] = add_query_arg(
				[
					'order'       => $context->order->get_id(),
					'nonce'       => wp_create_nonce( 'wc_stripe_confirm_pi' ),
					'redirect_to' => rawurlencode( $result->redirect_url ),
				],
				home_url() . \WC_Ajax::get_endpoint( 'wc_stripe_verify_intent' )
			);
			$result->set_payment_details( $payment_details );
			$result->set_status( 'success' );
		}
	}

	/**
	 * Handles adding information about the payment request type used to the order meta.
	 *
	 * @param \WC_Order $order The order being processed.
	 * @param string    $payment_request_type The payment request type used for payment.
	 */
	private function add_order_meta( \WC_Order $order, $payment_request_type ) {
		if ( 'apple_pay' === $payment_request_type ) {
			$order->set_payment_method_title( 'Apple Pay (Stripe)' );
			$order->save();
		} elseif ( 'google_pay' === $payment_request_type ) {
			$order->set_payment_method_title( 'Google Pay (Stripe)' );
			$order->save();
		} elseif ( 'payment_request_api' === $payment_request_type ) {
			$order->set_payment_method_title( 'Payment Request (Stripe)' );
			$order->save();
		}
	}

	/**
	 * Returns an array of supported features.
	 *
	 * @return string[]
	 */
	public function get_supported_features() {
		$gateway = new WC_Gateway_Stripe();
		return array_filter( $gateway->supports, [ $gateway, 'supports' ] );
	}
}
