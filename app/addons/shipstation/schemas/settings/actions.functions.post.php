<?php

/**
 * @return string The URL to which ShipStation will make postback calls.
 */
function fn_shipstation_get_store_url_handler() {
    return fn_shipstation_get_store_url();
}


/**
 * @return string Translatable explanation of how the IP address restrictions work.
 */
function fn_shipstation_get_ip_restriction_info_handler() {
    return __('shipstation.text_settings_ip_restriction_info');
}

