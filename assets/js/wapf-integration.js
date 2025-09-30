/**
 * WAPF Integration za ETA Plugin - FIXED VERSION
 * Baziran na debug podacima koji pokazuju da events rade
 */

(function($) {
    'use strict';

    var WAPFETAIntegration = {

        fieldKey: 'field_656c7a89bbca8',
        ajaxUrl: '',
        nonce: '',

        init: function() {
            if (typeof WC_ETA_WAPF === 'undefined') {
                console.log('WC_ETA_WAPF object not found - WAPF integration disabled');
                return;
            }

            this.ajaxUrl = WC_ETA_WAPF.ajax_url;
            this.nonce = WC_ETA_WAPF.nonce;
            this.fieldKey = WC_ETA_WAPF.field_key || 'field_656c7a89bbca8';

            console.log('WAPF ETA Integration initialized with field key: ' + this.fieldKey);

            this.bindEvents();
        },

        bindEvents: function() {
            // Na osnovu debug-a znam da je field name format: wapf[field_656c7a89bbca8]
            var fieldSelector = 'input[name="wapf[' + this.fieldKey + ']"]';

            console.log('Binding to WAPF field selector: ' + fieldSelector);

            // Event delegation za dinamički sadržaj
            $(document).on('change input blur', fieldSelector, (e) => {
                this.handleDateChange(e);
            });

            // Initial check kad se strana učita
            setTimeout(() => {
                this.checkInitialValue();
            }, 2000);

            console.log('WAPF events bound successfully');
        },

        checkInitialValue: function() {
            var fieldSelector = 'input[name="wapf[' + this.fieldKey + ']"]';
            var $field = $(fieldSelector);

            if ($field.length > 0 && $field.val()) {
                console.log('Initial WAPF value found: ' + $field.val());
                this.updateETA($field.val());
            }
        },

        handleDateChange: function(e) {
            var $field = $(e.target);
            var dateValue = $field.val();

            console.log('WAPF date changed: ' + dateValue);

            if (dateValue) {
                this.updateETA(dateValue);
            } else {
                this.clearWAPFETA();
            }
        },

        updateETA: function(dateValue) {
            console.log('Updating ETA for WAPF date: ' + dateValue);

            // Convert date format iz dd.mm.yyyy u yyyy-mm-dd za server
            var convertedDate = this.convertDateFormat(dateValue);

            $.ajax({
                url: this.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'wc_eta_update_wapf_date',
                    nonce: this.nonce,
                    wapf_date: convertedDate,
                    product_id: this.getProductId()
                },
                success: (response) => {
                    if (response.success) {
                        console.log('WAPF ETA update successful:', response.data);
                        this.displayETAUpdate(response.data);
                    } else {
                        console.error('WAPF ETA update failed:', response.data);
                    }
                },
                error: (xhr, status, error) => {
                    console.error('WAPF ETA AJAX error:', status, error);
                }
            });
        },

        convertDateFormat: function(dateStr) {
            // Input format iz debug-a: "30.09.2025." ili "02.10.2025."
            // Potreban format: "2025-09-30"

            if (!dateStr) return '';

            // Ukloni tačku na kraju ako postoji
            dateStr = dateStr.replace(/\.+$/, '');

            // Podeli po tačkama
            var parts = dateStr.split('.');

            if (parts.length === 3) {
                var day = parts[0].padStart(2, '0');
                var month = parts[1].padStart(2, '0');
                var year = parts[2];

                return year + '-' + month + '-' + day;
            }

            return dateStr; // Vrati original ako ne može da parsira
        },

        displayETAUpdate: function(etaData) {
            // Pronađi ETA kontejner
            var $etaContainer = $('.wc-eta-rs-container');

            if ($etaContainer.length === 0) {
                console.log('ETA container not found, creating new one');
                $etaContainer = $('<div class="wc-eta-rs-container wapf-eta"></div>');
                $('.summary').append($etaContainer);
            }

            // Dodaj WAPF specifičnu klasu
            $etaContainer.addClass('wapf-active');

            // Update sadržaja
            if (etaData.eta_html) {
                $etaContainer.html(etaData.eta_html);
            }

            // Trigger event za ostale komponente
            $(document).trigger('wceta.wapf.updated', [etaData]);
        },

        clearWAPFETA: function() {
            $('.wc-eta-rs-container').removeClass('wapf-active');
            console.log('WAPF ETA cleared');

            // Vrati na standardnu ETA
            this.refreshStandardETA();
        },

        refreshStandardETA: function() {
            $.ajax({
                url: this.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'wc_eta_refresh_standard',
                    nonce: this.nonce,
                    product_id: this.getProductId()
                },
                success: (response) => {
                    if (response.success && response.data.eta_html) {
                        $('.wc-eta-rs-container').html(response.data.eta_html);
                    }
                }
            });
        },

        getProductId: function() {
            // Pokušaj da pronađe product ID
            var productId = $('input[name="product_id"]').val() || 
                           $('button[name="add-to-cart"]').val() ||
                           $('.product').data('product-id') ||
                           0;

            return parseInt(productId);
        }
    };

    // Initialize when DOM is ready
    $(document).ready(() => {
        WAPFETAIntegration.init();
    });

    // Re-initialize on WAPF events
    $(document).on('wapf_field_updated', () => {
        setTimeout(() => {
            WAPFETAIntegration.init();
        }, 500);
    });

})(jQuery);
