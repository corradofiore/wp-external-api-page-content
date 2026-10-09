/**
 * HiveKit External API Page Content - settings screen behaviour.
 *
 * @package HiveKit_EAPC
 */

( function () {
	'use strict';

	var config = window.hivekitEapcAdmin || {};
	var i18n = config.i18n || {};

	function init() {
		var container = document.getElementById( 'hivekit-eapc-mappings' );
		var addButton = document.getElementById( 'hivekit-eapc-add-mapping' );
		var template = document.getElementById( 'hivekit-eapc-mapping-template' );

		if ( ! container || ! addButton || ! template ) {
			return;
		}

		var nextIndex = parseInt( config.nextIndex, 10 ) || 0;
		var ajaxUrl = config.ajaxUrl;
		var testNonce = config.nonce;

		function mappingField( card, fieldName ) {
			return card.querySelector( '[name$="[' + fieldName + ']"]' );
		}

		function formatBytes( bytes ) {
			if ( bytes < 1024 ) {
				return bytes + ' B';
			}
			if ( bytes < 1024 * 1024 ) {
				return ( bytes / 1024 ).toFixed( 1 ) + ' KB';
			}
			return ( bytes / ( 1024 * 1024 ) ).toFixed( 2 ) + ' MB';
		}

		function showTestError( card, message ) {
			var result = card.querySelector( '.hivekit-eapc-test-result' );
			var meta = card.querySelector( '.hivekit-eapc-test-result__meta' );
			var payload = card.querySelector( '.hivekit-eapc-test-payload' );

			result.hidden = false;
			result.classList.add( 'hivekit-eapc-test-result--error' );
			meta.textContent = message;
			payload.value = '';
		}

		function testMapping( card, button ) {
			var apiUrl = mappingField( card, 'api_url' );
			var format = mappingField( card, 'format' );
			var pageId = mappingField( card, 'page_id' );
			var mappingId = mappingField( card, 'id' );
			var label = mappingField( card, 'label' );
			var cacheTtl = mappingField( card, 'cache_ttl' );
			var requestHeaders = mappingField( card, 'headers' );
			var bearerConstant = mappingField( card, 'bearer_constant' );
			var spinner = card.querySelector( '.hivekit-eapc-test-spinner' );
			var result = card.querySelector( '.hivekit-eapc-test-result' );
			var meta = card.querySelector( '.hivekit-eapc-test-result__meta' );
			var payload = card.querySelector( '.hivekit-eapc-test-payload' );
			var params = new URLSearchParams();

			if ( ! apiUrl || ! apiUrl.value.trim() ) {
				showTestError( card, i18n.enterUrl );
				return;
			}

			params.append( 'action', 'hivekit_eapc_test_mapping' );
			params.append( 'nonce', testNonce );
			params.append( 'api_url', apiUrl.value );
			params.append( 'format', format ? format.value : 'auto' );
			params.append( 'page_id', pageId ? pageId.value : '0' );
			params.append( 'mapping_id', mappingId ? mappingId.value : '' );
			params.append( 'label', label ? label.value : '' );
			params.append( 'cache_ttl', cacheTtl ? cacheTtl.value : '3600' );
			params.append( 'headers', requestHeaders ? requestHeaders.value : '' );
			params.append( 'bearer_constant', bearerConstant ? bearerConstant.value : '' );

			button.disabled = true;
			spinner.classList.add( 'is-active' );
			result.hidden = true;

			fetch( ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
				body: params.toString(),
			} )
				.then( function ( response ) {
					return response.json();
				} )
				.then( function ( response ) {
					if ( ! response.success ) {
						var message =
							response.data && response.data.message
								? response.data.message
								: i18n.testFailed;

						showTestError( card, message );
						return;
					}

					var data = response.data;
					var parts = [
						'HTTP ' + data.status,
						data.content_type || i18n.noContentType,
						formatBytes( data.bytes || 0 ),
					];

					if ( data.resolved_format ) {
						parts.push( i18n.interpretedAs + ' ' + data.resolved_format.toUpperCase() );
					}

					result.hidden = false;
					result.classList.toggle( 'hivekit-eapc-test-result--error', ! data.http_ok );
					meta.textContent = parts.join( ' \u00b7 ' );
					payload.value = data.payload || '';
				} )
				.catch( function () {
					showTestError( card, i18n.requestFailed );
				} )
				.finally( function () {
					button.disabled = false;
					spinner.classList.remove( 'is-active' );
				} );
		}

		function renumber() {
			var cards = container.querySelectorAll( '.hivekit-eapc-mapping' );

			cards.forEach( function ( card, index ) {
				var title = card.querySelector( '.hivekit-eapc-mapping-number' );

				if ( title ) {
					title.textContent = String( index + 1 );
				}
			} );
		}

		addButton.addEventListener( 'click', function () {
			var html = template.innerHTML.replace( /__INDEX__/g, String( nextIndex++ ) );

			container.insertAdjacentHTML( 'beforeend', html );
			renumber();
		} );

		container.addEventListener( 'click', function ( event ) {
			if ( event.target.classList.contains( 'hivekit-eapc-test-mapping' ) ) {
				var testCard = event.target.closest( '.hivekit-eapc-mapping' );

				if ( testCard ) {
					testMapping( testCard, event.target );
				}

				return;
			}

			if ( ! event.target.classList.contains( 'hivekit-eapc-remove-mapping' ) ) {
				return;
			}

			var card = event.target.closest( '.hivekit-eapc-mapping' );

			if ( card ) {
				card.remove();
				renumber();
			}
		} );

		renumber();
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );
