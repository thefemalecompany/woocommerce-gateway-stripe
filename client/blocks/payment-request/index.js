/* global wc_stripe_payment_request_params */

/**
 * External dependencies
 */
import { getSetting } from '@woocommerce/settings';

/**
 * Internal dependencies
 */
import { PAYMENT_METHOD_NAME } from './constants';
import { PaymentRequestExpress } from './payment-request-express';
import { applePayImage } from './apple-pay-preview';
import { getStripeServerData, loadStripe } from '../stripe-utils';

const ApplePayPreview = () => <img src={ applePayImage } alt="" />;

const componentStripePromise = loadStripe();

const paymentRequestPaymentMethod = {
	name: PAYMENT_METHOD_NAME,
	content: <PaymentRequestExpress stripe={ componentStripePromise } />,
	edit: <ApplePayPreview />,
	canMakePayment: ( cartData ) => {
		// If the `wc_stripe_payment_request_params` object is not available we don't support
		// payment requests.
		// eslint-disable-next-line camelcase
		if ( typeof wc_stripe_payment_request_params === 'undefined' ) {
			return false;
		}

		return loadStripe().then( ( stripe ) => {
			// Create a payment request and check if we can make a payment to determine whether to
			// show the Payment Request Button or not. This is necessary because a browser might be
			// able to load the Stripe JS object, but not support Payment Requests.
			const paymentRequest = stripe.paymentRequest( {
				total: {
					label: 'Total',
					amount: parseInt(
						cartData?.cartTotals?.total_price ?? 0,
						10
					),
					pending: true,
				},
				country: getSetting( 'baseLocation', {} )?.country,
				currency: cartData?.cartTotals?.currency_code?.toLowerCase(),
			} );

			return paymentRequest.canMakePayment();
		} );
	},
	paymentMethodId: 'stripe',
	supports: {
		features: getStripeServerData()?.supports ?? [],
	},
};

export default paymentRequestPaymentMethod;
