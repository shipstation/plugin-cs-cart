<?php

/**
 * Provides Shipstation options for variants settings/options
 *
 * @return array
 */
function fn_settings_variants_addons_shipstation_shipped_status_final() {
    return fn_shipstation_render_statuses_assoc();
}

/**
 * Provides Shipstation options for variants settings/options
 *
 * @return array
 */
function fn_settings_variants_addons_shipstation_shipped_statuses() {
    return fn_shipstation_render_statuses_assoc(true);
}

