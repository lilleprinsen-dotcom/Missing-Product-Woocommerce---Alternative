/**
 * Missing Product WooCommerce Alternative: order screen box.
 *
 * - Caps the alternatives select at its data-max.
 * - Confirms links that change the order (apply, revoke).
 * - Refreshes the alternatives price/stock preview when the selection or the missing quantity changes (not saved).
 * - "Same product, other variant": adds in-stock sibling variations to the alternatives select.
 * - "Copy customer link", with a text field fallback when the clipboard cannot be used.
 * - Simple case editing: «Missing» opens the case fields, the quantity stepper, one-click customer messages,
 *   «Cancel the case», and a save bar that saves the order.
 */
/* global jQuery */
( function ( $, config ) {
    'use strict';

    config = config || {};
    var i18n = config.i18n || {};
    var ajaxUrl = config.ajaxUrl || window.ajaxurl;
    var timers = {};
    var sequence = {};

    function request( $el, action, data ) {
        var $box = $el.closest( '.lp-missing-metabox' );
        return $.ajax( {
            url: ajaxUrl,
            method: 'POST',
            dataType: 'json',
            data: $.extend( {
                action: action,
                nonce: $box.data( 'nonce' ),
                order_id: $box.data( 'order-id' )
            }, data )
        } );
    }

    function errorMessage( xhrOrResponse ) {
        var response = xhrOrResponse && xhrOrResponse.responseJSON ? xhrOrResponse.responseJSON : xhrOrResponse;
        return response && response.data && response.data.message ? response.data.message : i18n.error;
    }

    function maxOf( $select ) {
        return parseInt( $select.data( 'max' ), 10 ) || 3;
    }

    function missingQty( $item ) {
        return parseInt( $item.find( '.lp-missing-qty' ).val(), 10 ) || 0;
    }

    /**
     * Select suggestions ({id, text}) in the alternatives select, up to its maximum. Existing options are
     * re-selected, new ones appended as selected options (the way selectWoo expects programmatic additions),
     * then "change" is triggered once. Returns the number added.
     */
    function addAlternatives( $select, suggestions ) {
        var max = maxOf( $select );
        var values = ( $select.val() || [] ).map( String );
        var added = 0;

        $.each( suggestions || [], function ( index, suggestion ) {
            var id = String( suggestion.id );
            if ( values.length >= max ) {
                return false;
            }
            if ( values.indexOf( id ) !== -1 ) {
                return;
            }
            var $existing = $select.find( 'option' ).filter( function () {
                return this.value === id;
            } );
            if ( $existing.length ) {
                $existing.prop( 'selected', true );
            } else {
                $select.append( new Option( suggestion.text, id, true, true ) );
            }
            values.push( id );
            added++;
        } );

        if ( added ) {
            $select.trigger( 'change' );
        }
        return added;
    }

    function refreshPreview( $item ) {
        var itemId = $item.data( 'item-id' );
        var $select = $item.find( '.lp-alt-select' );
        var $list = $item.find( '.lp-alt-list' );
        var ids = $select.val() || [];
        var mine = sequence[ itemId ] = ( sequence[ itemId ] || 0 ) + 1;

        if ( ! ids.length ) {
            $list.removeClass( 'is-loading' ).empty();
            return $.Deferred().resolve().promise();
        }

        $list.addClass( 'is-loading' );
        return request( $item, 'lp_missing_preview_alternatives', {
            item_id: itemId,
            alt_ids: ids,
            qty: missingQty( $item )
        } ).done( function ( response ) {
            // A newer request replaced this one.
            if ( mine === sequence[ itemId ] && response && response.success ) {
                $list.html( response.data.html );
            }
        } ).always( function () {
            if ( mine === sequence[ itemId ] ) {
                $list.removeClass( 'is-loading' );
            }
        } );
    }

    function schedulePreview( $item ) {
        var itemId = $item.data( 'item-id' );
        clearTimeout( timers[ itemId ] );
        timers[ itemId ] = setTimeout( function () {
            refreshPreview( $item );
        }, 250 );
    }

    // Keep the product search at its maximum (selectWoo has no limit for AJAX multi-selects).
    $( document.body ).on( 'select2:selecting', '.lp-alt-select', function ( e ) {
        if ( ( $( this ).val() || [] ).length >= maxOf( $( this ) ) ) {
            e.preventDefault();
        }
    } );

    $( document ).on( 'click', '.lp-missing-confirm', function () {
        return window.confirm( $( this ).data( 'confirm' ) );
    } );

    $( document ).on( 'change', '.lp-missing-metabox .lp-alt-select', function () {
        schedulePreview( $( this ).closest( '.lp-missing-item' ) );
    } );

    $( document ).on( 'input change', '.lp-missing-metabox .lp-missing-qty', function () {
        var $item = $( this ).closest( '.lp-missing-item' );
        if ( ( $item.find( '.lp-alt-select' ).val() || [] ).length ) {
            schedulePreview( $item );
        }
    } );

    $( document ).on( 'click', '.lp-missing-variants', function ( e ) {
        var $button = $( this );
        var $item = $button.closest( '.lp-missing-item' );
        var $select = $item.find( '.lp-alt-select' );
        var $status = $item.find( '.lp-missing-variants-status' );
        var current = $select.val() || [];

        e.preventDefault();
        if ( current.length >= maxOf( $select ) ) {
            $status.text( i18n.listFull );
            return;
        }

        $button.prop( 'disabled', true );
        $status.text( i18n.searching );
        request( $button, 'lp_missing_variant_suggestions', {
            item_id: $item.data( 'item-id' ),
            exclude: current,
            qty: missingQty( $item )
        } ).done( function ( response ) {
            if ( ! response || ! response.success ) {
                $status.text( errorMessage( response ) );
                return;
            }
            var added = addAlternatives( $select, response.data.suggestions );
            $status.text( added ? String( i18n.added ).replace( '%d', added ) : ( response.data.message || i18n.noVariants ) );
        } ).fail( function ( xhr ) {
            $status.text( errorMessage( xhr ) );
        } ).always( function () {
            $button.prop( 'disabled', false );
        } );
    } );

    $( document ).on( 'click', '.lp-missing-copy-link', function ( e ) {
        var $button = $( this );
        var $wrap = $button.closest( '.lp-missing-admin-actions' );
        var $field = $wrap.find( '.lp-missing-link-field' );
        var $status = $wrap.find( '.lp-missing-copy-status' );
        var link = String( $button.data( 'link' ) || '' );

        e.preventDefault();

        function fallback() {
            var copied = false;
            $field.val( link ).prop( 'hidden', false ).trigger( 'focus' );
            if ( $field.length ) {
                $field[ 0 ].select();
                try {
                    copied = document.execCommand( 'copy' );
                } catch ( err ) {
                    copied = false;
                }
            }
            $status.text( copied ? i18n.copied : i18n.copyManual );
        }

        if ( navigator.clipboard && navigator.clipboard.writeText ) {
            navigator.clipboard.writeText( link ).then( function () {
                $status.text( i18n.copied );
            }, fallback );
        } else {
            fallback();
        }
    } );

    // ---------------------------------------------------------------------------------------------------------
    // Case editing.
    // ---------------------------------------------------------------------------------------------------------

    function markDirty( $box ) {
        var $bar = $box.find( '.lp-savebar' );
        var $text = $bar.find( '.lp-savebar__text' );
        var isNew = $box.find( '.lp-mark .lp-missing-toggle:checked' ).length > 0;
        $text.text( isNew ? $text.data( 'new' ) : $text.data( 'changed' ) );
        $bar.prop( 'hidden', false );
    }

    $( document ).on( 'change input', '.lp-missing-metabox :input', function () {
        if ( ! $( this ).is( '.lp-missing-link-field' ) ) {
            markDirty( $( this ).closest( '.lp-missing-metabox' ) );
        }
    } );

    // «Missing» on a line: show the case fields (and look for other variants of the product right away).
    $( document ).on( 'change', '.lp-mark .lp-missing-toggle', function () {
        var $line = $( this ).closest( '.lp-line' );
        var on = this.checked;
        $line.toggleClass( 'is-marked', on );
        $line.find( '.lp-new-case' ).prop( 'hidden', ! on );
        if ( on ) {
            var $variants = $line.find( '.lp-missing-variants' );
            if ( $variants.length && ! ( $line.find( '.lp-alt-select' ).val() || [] ).length ) {
                $variants.trigger( 'click' );
            }
            $line.find( '.lp-missing-qty' ).first().trigger( 'focus' );
        }
    } );

    $( document ).on( 'click', '.lp-step', function ( e ) {
        var $input = $( this ).siblings( '.lp-missing-qty' );
        var value = parseInt( $input.val(), 10 ) || 0;
        var min = parseInt( $input.attr( 'min' ), 10 ) || 1;
        var max = parseInt( $input.attr( 'max' ), 10 ) || value + 1;
        e.preventDefault();
        $input.val( Math.min( max, Math.max( min, value + ( parseInt( $( this ).data( 'step' ), 10 ) || 0 ) ) ) ).trigger( 'change' );
    } );

    $( document ).on( 'click', '.lp-preset', function ( e ) {
        var $area = $( this ).closest( '.lp-editor__row' ).find( 'textarea' );
        var text = String( $( this ).data( 'text' ) || '' );
        var current = $.trim( $area.val() );
        e.preventDefault();
        $area.val( current ? current + ' ' + text : text ).trigger( 'change' ).trigger( 'focus' );
    } );

    function setCancelled( $line, cancelled ) {
        $line.find( '.lp-line__side .lp-missing-toggle' ).prop( 'checked', ! cancelled ).trigger( 'change' );
        $line.toggleClass( 'is-cancelling', cancelled );
        $line.find( '.lp-cancel-case' ).prop( 'hidden', cancelled );
        $line.find( '.lp-cancel-note' ).prop( 'hidden', ! cancelled );
    }

    $( document ).on( 'click', '.lp-cancel-case', function ( e ) {
        e.preventDefault();
        setCancelled( $( this ).closest( '.lp-line' ), true );
    } );

    $( document ).on( 'click', '.lp-undo-cancel', function ( e ) {
        e.preventDefault();
        setCancelled( $( this ).closest( '.lp-line' ), false );
    } );

    $( document ).on( 'click', '.lp-open-edit', function ( e ) {
        var $details = $( this ).closest( '.lp-line' ).find( 'details.lp-edit' );
        e.preventDefault();
        $details.prop( 'open', true );
        $details.find( '.lp-alt-select' ).first().trigger( 'focus' );
    } );

    // «Save» in the box saves the order, like the Update/Create button.
    $( document ).on( 'click', '.lp-save', function ( e ) {
        var $buttons = $( 'button.save_order, #publish' );
        var $button = $buttons.filter( ':visible' ).first();
        e.preventDefault();
        if ( ! $button.length ) {
            $button = $buttons.first();
        }
        if ( $button.length ) {
            $button.trigger( 'click' );
        } else {
            $( this ).closest( 'form' ).trigger( 'submit' );
        }
    } );

    // For integrations and tests.
    window.lpMissingAdminApi = {
        addAlternatives: addAlternatives,
        refreshPreview: refreshPreview
    };
}( jQuery, window.lpMissingAdmin ) );
