/**
 * SM Redirect Manager – admin interactions (vanilla JS, no dependencies).
 *
 * Every request carries the AJAX nonce localised as smRedirectManager.nonce;
 * capability and nonce are re-checked server-side.
 */
( function () {
	'use strict';

	const cfg = window.smRedirectManager;
	if ( ! cfg ) {
		return;
	}
	const i18n = cfg.i18n || {};

	/* ------------------------------------------------------------------ */
	/* Helpers                                                             */
	/* ------------------------------------------------------------------ */

	function notify( message, type = 'error' ) {
		const wrap = document.querySelector( '.sm-rm-wrap' );
		if ( ! wrap ) {
			return;
		}
		wrap.querySelectorAll( '.sm-rm-js-notice' ).forEach( ( n ) => n.remove() );

		const notice = document.createElement( 'div' );
		notice.className = `notice notice-${ type } sm-rm-js-notice`;
		notice.setAttribute( 'role', type === 'error' ? 'alert' : 'status' );
		const p = document.createElement( 'p' );
		p.textContent = message; // textContent: never inject HTML.
		notice.appendChild( p );

		const anchor = wrap.querySelector( '.wp-header-end' );
		if ( anchor ) {
			anchor.after( notice );
		} else {
			wrap.prepend( notice );
		}
	}

	async function request( action, data ) {
		const body = new URLSearchParams( { action, nonce: cfg.nonce, ...data } );
		const response = await fetch( cfg.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body,
		} );

		let json;
		try {
			json = await response.json();
		} catch ( e ) {
			throw new Error( i18n.requestFailed );
		}

		if ( ! json || ! json.success ) {
			const error = new Error( ( json && json.data && json.data.message ) || i18n.requestFailed );
			error.field = json && json.data ? json.data.field : undefined;
			throw error;
		}
		return json.data || {};
	}

	function removeRow( element ) {
		const row = element.closest( 'tr' );
		if ( ! row ) {
			return;
		}
		const next = row.nextElementSibling;
		if ( next && next.classList.contains( 'sm-rm-convert-row' ) ) {
			next.remove();
		}
		row.classList.add( 'sm-rm-removing' );
		window.setTimeout( () => row.remove(), 250 );
	}

	/* ------------------------------------------------------------------ */
	/* Row actions                                                         */
	/* ------------------------------------------------------------------ */

	async function toggleStatus( link ) {
		link.setAttribute( 'aria-busy', 'true' );
		try {
			const data = await request( 'sm_redirect_manager_toggle_status', { id: link.dataset.id } );
			const row = link.closest( 'tr' );
			const badge = row && row.querySelector( '.sm-rm-status' );
			if ( badge ) {
				badge.textContent = data.label;
				badge.className = `sm-rm-status sm-rm-status--${ data.status }`;
			}
			link.textContent = data.status === 'active' ? i18n.deactivate : i18n.activate;
		} catch ( err ) {
			notify( err.message );
		} finally {
			link.removeAttribute( 'aria-busy' );
		}
	}

	async function deleteItem( link, action ) {
		// eslint-disable-next-line no-alert
		if ( ! window.confirm( i18n.confirmDelete ) ) {
			return;
		}
		try {
			await request( action, { id: link.dataset.id } );
			removeRow( link );
		} catch ( err ) {
			notify( err.message );
		}
	}

	function openConvertForm( link ) {
		const row = link.closest( 'tr' );
		const template = document.getElementById( 'sm-rm-convert-template' );
		if ( ! row || ! template ) {
			return false;
		}

		const existing = row.nextElementSibling;
		if ( existing && existing.classList.contains( 'sm-rm-convert-row' ) ) {
			existing.remove();
			return true;
		}

		const fragment = template.content.cloneNode( true );
		const convertRow = fragment.querySelector( 'tr' );
		convertRow.dataset.logId = link.dataset.id;
		convertRow.querySelector( 'td' ).colSpan = row.children.length;
		convertRow.querySelector( '.sm-rm-convert-source' ).textContent = link.dataset.url;

		// Unique IDs per inline form so labels stay associated.
		convertRow.querySelectorAll( '[id]' ).forEach( ( el ) => {
			const newId = `${ el.id }-${ link.dataset.id }`;
			const label = convertRow.querySelector( `label[for="${ el.id }"]` );
			if ( label ) {
				label.htmlFor = newId;
			}
			el.id = newId;
		} );

		row.after( convertRow );
		row.nextElementSibling.querySelector( 'input[name="url_to"]' ).focus();
		return true;
	}

	async function submitConvertForm( form ) {
		const row = form.closest( 'tr' );
		const button = form.querySelector( 'button[type="submit"]' );
		button.disabled = true;

		try {
			const data = await request( 'sm_redirect_manager_convert_404', {
				log_id: row.dataset.logId,
				url_to: form.elements.url_to.value,
				action_code: form.elements.action_code.value,
			} );
			notify( data.message, 'success' );
			const sourceRow = row.previousElementSibling;
			row.remove();
			if ( sourceRow ) {
				removeRow( sourceRow.firstElementChild || sourceRow );
			}
		} catch ( err ) {
			notify( err.message );
			button.disabled = false;
		}
	}

	/* ------------------------------------------------------------------ */
	/* Quick Add                                                           */
	/* ------------------------------------------------------------------ */

	// Mirrors RedirectValidator::detectMatchType().
	function detectMatchType( source ) {
		const s = source.trim();
		if ( s.startsWith( '^' ) || ( s.endsWith( '$' ) && ! s.endsWith( '\\$' ) ) ) {
			return 'regex';
		}
		return s.includes( '*' ) ? 'wildcard' : 'exact';
	}

	function initQuickAdd( form ) {
		const source = form.elements.url_from;
		const target = form.elements.url_to;
		const code = form.elements.action_code;
		const hint = form.querySelector( '.sm-rm-detected' );
		const targetField = form.querySelector( '.sm-rm-field--target' );

		const syncHint = () => {
			const type = detectMatchType( source.value );
			const show = source.value.trim() !== '' && type !== 'exact';
			hint.textContent = show ? ( i18n.detected || '%s' ).replace( '%s', ( cfg.matchTypes || {} )[ type ] || type ) : '';
			hint.dataset.type = show ? type : '';
		};

		const syncTarget = () => {
			const noTarget = [ '410', '451' ].includes( code.value );
			targetField.classList.toggle( 'is-disabled', noTarget );
			target.disabled = noTarget;
		};

		source.addEventListener( 'input', syncHint );
		code.addEventListener( 'change', syncTarget );
		syncHint();
		syncTarget();
	}

	function clearFieldErrors( form ) {
		form.querySelectorAll( '[aria-invalid]' ).forEach( ( el ) => el.removeAttribute( 'aria-invalid' ) );
	}

	async function submitQuickAdd( form ) {
		const button = form.querySelector( '.sm-rm-quick-submit' );
		const label = button.textContent;
		clearFieldErrors( form );

		if ( form.elements.url_from.value.trim() === '' ) {
			form.elements.url_from.setAttribute( 'aria-invalid', 'true' );
			form.elements.url_from.focus();
			return;
		}

		button.disabled = true;
		button.textContent = i18n.saving || label;

		try {
			const data = await request( 'sm_redirect_manager_quick_add', {
				url_from: form.elements.url_from.value,
				url_to: form.elements.url_to.disabled ? '' : form.elements.url_to.value,
				action_code: form.elements.action_code.value,
			} );

			const tbody = document.querySelector( '.sm-rm-list-form #the-list' );
			if ( tbody && data.rowHtml ) {
				const placeholder = tbody.querySelector( 'tr.no-items' );
				if ( placeholder ) {
					placeholder.remove();
				}
				const tpl = document.createElement( 'template' );
				tpl.innerHTML = data.rowHtml.trim(); // Server-rendered, fully escaped list-table row.
				const row = tpl.content.firstElementChild;
				if ( row ) {
					row.classList.add( 'sm-rm-row-new' );
					tbody.prepend( row );
				}
			}

			notify( data.message, 'success' );
			form.elements.url_from.value = '';
			form.elements.url_to.value = '';
			form.elements.url_from.dispatchEvent( new Event( 'input' ) );
			form.elements.url_from.focus(); // Ready for the next one.
		} catch ( err ) {
			notify( err.message );
			if ( err.field && form.elements[ err.field ] ) {
				form.elements[ err.field ].setAttribute( 'aria-invalid', 'true' );
				form.elements[ err.field ].focus();
			}
		} finally {
			button.disabled = false;
			button.textContent = label;
		}
	}

	const quickForm = document.querySelector( '.sm-rm-quick-form' );
	if ( quickForm ) {
		initQuickAdd( quickForm );
	}

	/* ------------------------------------------------------------------ */
	/* Event delegation                                                    */
	/* ------------------------------------------------------------------ */

	document.addEventListener( 'click', ( event ) => {
		const target = event.target instanceof Element ? event.target : null;
		if ( ! target ) {
			return;
		}

		const toggle = target.closest( '.sm-rm-toggle' );
		if ( toggle ) {
			event.preventDefault();
			toggleStatus( toggle );
			return;
		}

		const del = target.closest( '.sm-rm-delete' );
		if ( del ) {
			event.preventDefault();
			deleteItem( del, 'sm_redirect_manager_delete_redirect' );
			return;
		}

		const delLog = target.closest( '.sm-rm-delete-log' );
		if ( delLog ) {
			event.preventDefault();
			deleteItem( delLog, 'sm_redirect_manager_delete_log' );
			return;
		}

		const convert = target.closest( '.sm-rm-convert' );
		if ( convert && openConvertForm( convert ) ) {
			event.preventDefault(); // Falls back to the full form link when JS rendering fails.
			return;
		}

		const cancel = target.closest( '.sm-rm-convert-cancel' );
		if ( cancel ) {
			event.preventDefault();
			cancel.closest( 'tr' ).remove();
		}
	} );

	document.addEventListener( 'submit', ( event ) => {
		const form = event.target;

		if ( form.matches( '.sm-rm-quick-form' ) ) {
			event.preventDefault(); // Without JS the form posts to admin-post.php instead.
			submitQuickAdd( form );
			return;
		}

		if ( form.matches( '.sm-rm-convert-form' ) ) {
			event.preventDefault();
			submitConvertForm( form );
			return;
		}

		// eslint-disable-next-line no-alert
		if ( form.matches( '.sm-rm-confirm' ) && ! window.confirm( form.dataset.confirm ) ) {
			event.preventDefault();
		}
	}, true ); // Capture phase: the inline convert form sits inside the list-table form, where core scripts stop bubbling.

	/* ------------------------------------------------------------------ */
	/* Edit form: hide the target for 410 / 451                            */
	/* ------------------------------------------------------------------ */

	const codeSelect = document.getElementById( 'sm-rm-action-code' );
	const targetRow = document.getElementById( 'sm-rm-target-row' );
	if ( codeSelect && targetRow ) {
		const input = targetRow.querySelector( 'input' );
		const sync = () => {
			const noTarget = [ '410', '451' ].includes( codeSelect.value );
			targetRow.hidden = noTarget;
			input.required = ! noTarget;
		};
		codeSelect.addEventListener( 'change', sync );
		sync();
	}
}() );
