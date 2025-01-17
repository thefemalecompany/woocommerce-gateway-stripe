/* global wc_stripe_params */

/**
 * External dependencies
 */
import { loadStripe } from '@stripe/stripe-js';

/**
 * Internal dependencies
 */
import { getApiKey } from './utils';

const stripePromise = () =>
	new Promise( ( resolve ) => {
		try {
			// Default to the 'auto' locale so Stripe chooses the browser's locale
			// if the store's locale is not available.
			// eslint-disable-next-line camelcase
			const { stripe_locale: locale = 'auto' } = wc_stripe_params;
			resolve( loadStripe( getApiKey(), { locale } ) );
		} catch ( error ) {
			// In order to avoid showing console error publicly to users,
			// we resolve instead of rejecting when there is an error.
			resolve( { error } );
		}
	} );

export { stripePromise as loadStripe };
