<?php
/***************************************************************************
*                                                                          *
*   (c) 2004 Vladimir V. Kalynyak, Alexey V. Vinokurov, Ilya M. Shalnev    *
*                                                                          *
* This  is  commercial  software,  only  users  who have purchased a valid *
* license  and  accept  to the terms of the  License Agreement can install *
* and use this program.                                                    *
*                                                                          *
****************************************************************************
* PLEASE READ THE FULL TEXT  OF THE SOFTWARE  LICENSE   AGREEMENT  IN  THE *
* "copyright.txt" FILE PROVIDED WITH THIS DISTRIBUTION PACKAGE.            *
****************************************************************************/

if (!defined('BOOTSTRAP')) { die('Access denied'); }

use Tygh\Registry;
use Tygh\Languages\Languages;


class ShipStationSimpleXMLElement extends SimpleXMLElement {
  /**
   * Adds a child with $value inside CDATA
   * @param mixed $name
   * @param mixed $value
   */
  public function addChildWithCDATA($name, $value = NULL) {
    $new_child = $this->addChild($name);

    if ($new_child !== NULL) {
      $node = dom_import_simplexml($new_child);
      $no = $node->ownerDocument;
      $node->appendChild($no->createCDATASection($value));
    }

    return $new_child;
  }
}


function fn_shipstation_create_order(&$order) {
  $order['last_modify'] = TIME;
}


function fn_shipstation_update_order(&$order, $order_id) {
  $order['last_modify'] = TIME;
}


function fn_shipstation_update_order_details_post(&$params, &$order_info, &$edp_data, &$force_notification) {
  $time = TIME;
  $order_id = (!empty($params['order_id'])) ? $params['order_id'] : 0;
  $order_info['last_modify'] = $time;
  fn_shipstation_update_value_last_modify($order_id);
}


function fn_shipstation_change_order_status_post($order_id, &$status_to, &$status_from, &$force_notification, &$place_order, &$order_info, &$edp_data) {
  fn_shipstation_update_value_last_modify($order_id);
}


function fn_shipstation_pre_update_order(&$cart, $order_id) {
  fn_shipstation_update_value_last_modify($order_id);
}


/**
 * Updates the orders.last_modify column in the database.
 */
function fn_shipstation_update_value_last_modify($order_id, $time = NULL) {
  if (is_null($time)) {
    $time = TIME;
  }
  $order_id = (int) $order_id;
  if ($order_id) {
    db_query("UPDATE ?:orders SET last_modify = ?i WHERE order_id = ?i", $time, $order_id);
  }
}


/**
 * @return string Properly translated string of the ShipStation postback URL.
 */
function fn_shipstation_get_store_url() {
  // Fetch config value first -- hard-coded
  $https_host_config = Registry::get('config.https_host');
  // Use runtime storefront info if possible -- fall back to config value
  $https_host = Registry::ifGet('runtime.company_data.secure_storefront', $https_host_config);
  // Should be the same for all stores running in the same setup
  $https_path = Registry::get('config.https_path');
  // Should be the same for all stores running in the same setup
  $customer_index = Registry::get('config.customer_index');
  // Company ID
  $company_id = Registry::ifGet('runtime.company_id', '0');

  $translation_id = 'shipstation.text_store_url';
  $translation_placeholders = array(
    '[https_host]' => $https_host,
    '[https_path]' => $https_path,
    '[customer_index]' => $customer_index,
    '[company_id]' => $company_id,
  );

  return __($translation_id, $translation_placeholders);
}


/**
 * Attempts to extract supplied username data for API calls.
 *
 * @return string The supplied username, if found.
 */
function fn_shipstation_auth_retrieve_remote_username() {
  $authname = '';
  // No reason this should not be a string that I can think of
  if (isset($_REQUEST['SS-UserName']) && !empty($_REQUEST['SS-UserName']) && is_string($_REQUEST['SS-UserName'])) {
    $authname = urldecode($_REQUEST['SS-UserName']);
  }
  // Try to get the username from the $_SERVER superglobal
  // HTTP_* headers can be faked -- check type status here just in case
  elseif (isset($_SERVER['HTTP_SS_AUTH_USER']) && !empty($_SERVER['HTTP_SS_AUTH_USER']) && is_string($_SERVER['HTTP_SS_AUTH_USER'])) {
    $authname = urldecode($_SERVER['HTTP_SS_AUTH_USER']);
  }
  elseif ($auth_info_extracted = fn_shipstation_extract_http_auth_creds()) {
    $authname = $auth_info_extracted['username'];
  }
  elseif (isset($_SERVER['REDIRECT_REMOTE_USER'])) {
    $authname = $_SERVER['REDIRECT_REMOTE_USER'];
  }
  elseif (isset($_SERVER['REMOTE_USER'])) {
    $authname = $_SERVER['REMOTE_USER'];
  }
  elseif (isset($_SERVER['REDIRECT_PHP_AUTH_USER'])) {
    $authname = $_SERVER['REDIRECT_PHP_AUTH_USER'];
  }
  elseif (isset($_SERVER['PHP_AUTH_USER'])) {
    $authname = $_SERVER['PHP_AUTH_USER'];
  }
  return $authname;
}


/**
 * Attempts to extract supplied password data for API calls.
 *
 * @return string The supplied password, if found.
 */
function fn_shipstation_auth_retrieve_remote_password() {
  $authpass = '';
  // No reason this should not be a string that I can think of
  if (isset($_REQUEST['SS-Password']) && !empty($_REQUEST['SS-Password']) && is_string($_REQUEST['SS-Password'])) {
    $authpass = urldecode($_REQUEST['SS-Password']);
  }
  // Try to get the password from the $_SERVER superglobal
  // HTTP_* headers can be faked -- check type status here just in case
  elseif (isset($_SERVER['HTTP_SS_AUTH_PW']) && !empty($_SERVER['HTTP_SS_AUTH_PW']) && is_string($_SERVER['HTTP_SS_AUTH_PW'])) {
    $authpass = urldecode($_SERVER['HTTP_SS_AUTH_PW']);
  }
  elseif ($auth_info_extracted = fn_shipstation_extract_http_auth_creds()) {
    $authpass = $auth_info_extracted['password'];
  }
  elseif (isset($_SERVER['REDIRECT_PHP_AUTH_PW'])) {
    $authpass = $_SERVER['REDIRECT_PHP_AUTH_PW'];
  }
  elseif (isset($_SERVER['PHP_AUTH_PW'])) {
    $authpass = $_SERVER['PHP_AUTH_PW'];
  }
  return $authpass;
}


/**
 * Attempts to extract supplied username and password data for API calls based on
 *   provided HTTP_AUTHORIZATION header, if any.
 *
 * @return mixed An array of username and password values, if found. Otherwise, FALSE.
 */
function fn_shipstation_extract_http_auth_creds() {
  static $creds;
  if (!isset($creds)) {
    $creds = array();
    if (isset($_SERVER['HTTP_AUTHORIZATION']) || isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
      $authorization_header = '';
      if (isset($_SERVER['HTTP_AUTHORIZATION']) && !empty($_SERVER['HTTP_AUTHORIZATION'])) {
        $authorization_header = $_SERVER['HTTP_AUTHORIZATION'];
      }
      // If using CGI on Apache with mod_rewrite, the forwarded HTTP header appears
      // in the redirected HTTP headers. See
      // https://github.com/symfony/symfony/blob/master/src/Symfony/Component/HttpFoundation/ServerBag.php#L61
      elseif (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION']) && !empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
        $authorization_header = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
      }
      // Resemble PHP_AUTH_USER and PHP_AUTH_PW for a Basic authentication from
      // the HTTP_AUTHORIZATION header. See
      // http://www.php.net/manual/features.http-auth.php
      if (!empty($authorization_header)) {
        $val_substring = substr($authorization_header, 6);
        $val_decoded = base64_decode($val_substring);
        list($username, $password) = explode(':', $val_decoded);
        $creds['username'] = $username;
        $creds['password'] = $password;
      }
    }
  }
  return !empty($creds) ? $creds : FALSE;
}


/**
 * Determines whether or not to proceed with an API call based on provided auth credentials.
 *
 * @return bool TRUE if call should proceed. Otherwise, FALSE.
 */
function fn_shipstation_api_call_proceed() {
  $proceed = FALSE;
  $ip_allowed = fn_shipstation_current_ip_allowed();
  if ($ip_allowed) {
    // Get authorization info
    $username_provided = fn_shipstation_auth_retrieve_remote_username();
    $password_provided = fn_shipstation_auth_retrieve_remote_password();
    if (strlen($username_provided) && strlen($password_provided)) {
      $username_needed = Registry::ifGet('addons.shipstation.username', '');
      $password_needed = Registry::ifGet('addons.shipstation.password', '');
      if (strlen($username_needed) && strlen($password_needed)) {
        if ($username_provided == $username_needed && $password_provided == $password_needed) {
          $proceed = TRUE;
        }
      }
    }
  }
  return $proceed;
}


function fn_shipstation_force_empty_data_export() {
  $xml = new ShipStationSimpleXMLElement('<?xml version="1.0" standalone="yes" ?><Orders/>');

  $total_pages = 0;

  $xml->addAttribute('pages', $total_pages);

  return $xml->asXML();
}


/**
 * Determines whether or not the IP address attempting to access the ShipStation info
 *   is allowed.
 *
 * @return bool TRUE if the IP address is allowed. Otherwise, FALSE.
 */
function fn_shipstation_current_ip_allowed() {
  $allowed = FALSE;
  $force_restricted_ips = Registry::ifGet('addons.shipstation.force_restricted_ips', 'no');
  if ($force_restricted_ips == 'no') {
    $allowed = TRUE;
  }
  else {
    $ip_addresses_allowed_raw = Registry::ifGet('addons.shipstation.ip_addresses_allowed', '');
    $ip_addresses_allowed = explode(PHP_EOL, $ip_addresses_allowed_raw);
    $ip_addresses_allowed = array_filter(array_map('trim', $ip_addresses_allowed));
    if (!empty($ip_addresses_allowed)) {
      $ip_current = $_SERVER['REMOTE_ADDR'];
      foreach ($ip_addresses_allowed as $k => $ip_allowed) {
        if ($ip_allowed == $ip_current) {
          $allowed = TRUE;
          break;
        }
      }
    }
  }
  return $allowed;
}


function fn_shipstation_orders_export() {
  $start_time = TIME;
  $end_time = TIME;
  // Possibly overkill.
  $date_pattern_regex = '~^(([0][123456789])|([1][012]))(/)(([012][0-9]{1,1})|([3][01]))(/)([12][0-9]{3,3})((\s+)([012][0-9][:][012345][0-9]))?$~';
  //$date_pattern_regex = '~^(.+)$~';

  if (isset($_REQUEST['start_date']) && !empty($_REQUEST['start_date']) && is_string($_REQUEST['start_date'])) {
    $start_date = urldecode($_REQUEST['start_date']);
    if (preg_match($date_pattern_regex, $start_date)) {
      $start_time = strtotime($start_date);
    }
  }

  if (isset($_REQUEST['end_date']) && !empty($_REQUEST['end_date']) && is_string($_REQUEST['end_date'])) {
    $end_date = urldecode($_REQUEST['end_date']);
    if (preg_match($date_pattern_regex, $end_date)) {
      $end_time = strtotime($end_date);
    }
  }

  $page = (isset($_REQUEST['page']) && !empty($_REQUEST['page']) && is_numeric($_REQUEST['page'])) ? $_REQUEST['page'] : 1;
  $page = (int) $page;

  $items_per_page = Registry::ifGet('addons.shipstation.export_num_orders_per_page', 20);

  $limit = db_paginate($page, $items_per_page);

  $y = 'Y';
  $condition = db_quote(" AND o.is_parent_order != ?s AND ((o.timestamp >= ?i AND o.timestamp < ?i) OR (o.last_modify != 0 AND o.last_modify >= ?i AND o.last_modify < ?i))", $y, $start_time, $end_time, $start_time, $end_time);
  if (fn_allowed_for('MULTIVENDOR') && isset($_REQUEST['vendor']) && !empty($_REQUEST['vendor']) && is_numeric($_REQUEST['vendor'])) {
    $vendor = (int) $_REQUEST['vendor'];
    $condition .= db_quote(" AND o.company_id = ?i ", $vendor);
  }

  $order_ids = db_get_fields("SELECT o.order_id FROM ?:orders o WHERE 1 ?p", $condition);

  $total_displayed = 0;
  $total_displayable = 0;
  $max_key_displayed = ($page * $items_per_page) - 1;
  $min_key_displayed = $max_key_displayed - $items_per_page + 1;

  $xml = new ShipStationSimpleXMLElement('<?xml version="1.0" standalone="yes" ?><Orders/>');

  if (!empty($order_ids)) {

    foreach ($order_ids as $order_key => $order_id) {

      $order_info = fn_get_order_info($order_id);

      if ($order_info && is_array($order_info)) {
        $total_displayable++;

        if ($total_displayed >= $min_key_displayed && $total_displayed <= $max_key_displayed) {

          $order_shipping = '';
          if ($order_info['shipping'] && is_array($order_info['shipping'])) {
            foreach ($order_info['shipping'] as $ship) {
              if (isset($ship['shipping'])) {
                $order_shipping = $ship['shipping'];
                break;
              }
            }
          }

          $order_statuses = fn_get_statuses(STATUSES_ORDER, array(), true, false, CART_LANGUAGE, $order_info['company_id']);

          $order_status = strtolower($order_statuses[$order_info['status']]['description']);

          $payment_method = (isset($order_info['payment_method']['payment'])) ? $order_info['payment_method']['payment'] : '';

          $weight_units = Registry::get('settings.General.weight_symbol');
          // |pound|pounds|lb|lbs|gram|grams|gm|oz|ounces|Pound|Pounds|Lb|Lbs|Gram|Grams|Gm|Oz|Ounces|POUND|POUNDS|LB|LBS|GRAM|GRAMS|GM|OZ|OUNCES
          if (!in_array(strtolower($weight_units), array('pound', 'pounds', 'lb', 'lbs', 'gram', 'gm', 'oz', 'ounces', 'grams'))) {
            $weight_units = 'grams';
          }

          $s_name_parts[$order_key] = array();
          if (isset($order_info['s_firstname']) && isset($order_info['s_lastname']) && ($s_firstname[$order_key] = trim($order_info['s_firstname'])) && ($s_lastname[$order_key] = trim($order_info['s_lastname']))) {
            $s_name_parts[$order_key][] = $s_firstname[$order_key];
            $s_name_parts[$order_key][] = $s_lastname[$order_key];
          }

          if (empty($s_name_parts[$order_key])) {
            if (isset($order_info['firstname']) && isset($order_info['lastname']) && ($firstname[$order_key] = trim($order_info['firstname'])) && ($lastname[$order_key] = trim($order_info['lastname']))) {
              $s_name_parts[$order_key][] = $firstname[$order_key];
              $s_name_parts[$order_key][] = $lastname[$order_key];
            }
          }

          $s_name_final[$order_key] = implode(' ', $s_name_parts[$order_key]);


          $b_name_parts[$order_key] = array();
          if (isset($order_info['b_firstname']) && isset($order_info['b_lastname']) && ($b_firstname[$order_key] = trim($order_info['b_firstname'])) && ($b_lastname[$order_key] = trim($order_info['b_lastname']))) {
            $b_name_parts[$order_key][] = $b_firstname[$order_key];
            $b_name_parts[$order_key][] = $b_lastname[$order_key];
          }

          $b_name_final[$order_key] = implode(' ', $b_name_parts[$order_key]);

          $s_city[$order_key] = (isset($order_info['s_city']) && !empty($order_info['s_city'])) ? $order_info['s_city'] : '';
          $s_state[$order_key] = (isset($order_info['s_state']) && !empty($order_info['s_state'])) ? $order_info['s_state'] : '';

          $s_zipcode[$order_key] = (isset($order_info['s_zipcode']) && !empty($order_info['s_zipcode'])) ? $order_info['s_zipcode'] : '';
          $s_country[$order_key] = (isset($order_info['s_country']) && !empty($order_info['s_country'])) ? $order_info['s_country'] : '';
          if ($s_zipcode[$order_key] && $s_country[$order_key]) {
            if (empty($s_city[$order_key]) || empty($s_state[$order_key])) {
              // Possible future API call to fetch city and/or state, if missing for some reason
            }
          }

          $order = $xml->addChild('Order');
          $order->addChildWithCDATA('OrderID', $order_info['order_id']);
          $order->addChildWithCDATA('OrderNumber', $order_info['order_id']);
          $order->addChildWithCDATA('OrderDate', date("n/j/Y H:i A", $order_info['timestamp']));
          $order->addChildWithCDATA('OrderStatus', $order_status);
          $order->addChildWithCDATA('LastModified', date("n/j/Y H:i A", empty($order_info['last_modify']) ? $order_info['timestamp'] : $order_info['last_modify']));

          $order->addChildWithCDATA('ShippingMethod', $order_shipping);
          $order->addChildWithCDATA('PaymentMethod', $payment_method);
          $order->addChildWithCDATA('OrderTotal', $order_info['total']);
          $order->addChildWithCDATA('TaxAmount', $order_info['tax_subtotal']);
          $order->addChildWithCDATA('ShippingAmount', $order_info['shipping_cost']);

          $order->addChildWithCDATA('CustomerNotes', $order_info['notes']);
          $order->addChildWithCDATA('InternalNotes', $order_info['details']);

          $customer = $order->addChild('Customer');
          $customer->addChildWithCDATA('CustomerCode', $order_info['email']);

          $billto = $customer->addChild('BillTo');
          $billto->addChildWithCDATA('Name', $b_name_final[$order_key]);
          $billto->addChildWithCDATA('Company', $order_info['company']);
          $billto->addChildWithCDATA('Phone', $order_info['b_phone']);
          $billto->addChildWithCDATA('Email', $order_info['email']);

          $shipto = $customer->addChild('ShipTo');
          $shipto->addChildWithCDATA('Name', $s_name_final[$order_key]);
          $shipto->addChildWithCDATA('Company', $order_info['company']);

          $shipto->addChildWithCDATA('Address1', $order_info['s_address']);
          $shipto->addChildWithCDATA('Address2', $order_info['s_address_2']);
          $shipto->addChildWithCDATA('City', $s_city[$order_key]);
          $shipto->addChildWithCDATA('State', $s_state[$order_key]);
          $shipto->addChildWithCDATA('PostalCode', $s_zipcode[$order_key]);
          $shipto->addChildWithCDATA('Country', $s_country[$order_key]);
          $shipto->addChildWithCDATA('Phone', $order_info['s_phone']);

          $items = $order->addChild('Items');

          foreach ($order_info['products'] as $pkey => $pval) {
            $product_image[$pkey] = fn_shipstation_get_image_url($pval['product_id'], 'product', 'M', true, true);
            $product_weight[$pkey] = db_get_field("SELECT p.weight FROM ?:products p WHERE p.product_id = ?i", $pval['product_id']);
            $product_weight[$pkey] = (strlen($product_weight[$pkey])) ? $product_weight[$pkey] : 0;
            $product_weight[$pkey] = (float) $product_weight[$pkey];

            $item[$pkey] = $items->addChild('Item');
            $item[$pkey]->addChildWithCDATA('SKU', $pval['product_code']);
            $item[$pkey]->addChildWithCDATA('Name', $pval['product']);
            $item[$pkey]->addChildWithCDATA('ImageUrl', $product_image[$pkey]);
            $item[$pkey]->addChildWithCDATA('Weight', $product_weight[$pkey]);
            $item[$pkey]->addChildWithCDATA('WeightUnits', $weight_units);
            $item[$pkey]->addChildWithCDATA('Quantity', $pval['amount']);
            $item[$pkey]->addChildWithCDATA('UnitPrice', $pval['price']);
            $item[$pkey]->addChildWithCDATA('Location', '');

            if (isset($pval['product_options']) && !empty($pval['product_options'])) {

              $options[$pkey] = $item[$pkey]->addChild('Options');

              foreach ($pval['product_options'] as $okey => $oval) {

                $option[$pkey][$okey] = $options[$pkey]->addChild('Option');
                $option[$pkey][$okey]->addChildWithCDATA('Name', $oval['option_name']);
                $option[$pkey][$okey]->addChildWithCDATA('Value', $oval['variant_name']);

                $weight[$pkey][$okey] = db_get_row("SELECT pov.weight_modifier, pov.weight_modifier_type FROM ?:product_option_variants pov WHERE pov.option_id = ?i AND pov.variant_id = ?i", $oval['option_id'], $oval['value']);

                if (isset($weight[$pkey][$okey]['weight_modifier_type']) && ($weight[$pkey][$okey]['weight_modifier_type'] == 'A')) {
                  $option_weight[$pkey][$okey] = $weight[$pkey][$okey]['weight_modifier'];
                }
                elseif (isset($weight[$pkey][$okey]['weight_modifier'])) {
                  $option_weight[$pkey][$okey] = $product_weight[$pkey] + ((floatval($weight[$pkey][$okey]['weight_modifier']) * $product_weight[$pkey]) / 100);
                }
                else {
                  $option_weight[$pkey][$okey] = '';
                }
                $option_weight[$pkey][$okey] = (float) $option_weight[$pkey][$okey];
                $option[$pkey][$okey]->addChildWithCDATA('Weight', $option_weight[$pkey][$okey]);

              }
            }
          }
        }
        $total_displayed++;
      }
    }
  }

  $total_pages = ceil(($total_displayable * 1.0)/ $items_per_page);

  $xml->addAttribute('pages', $total_pages);

  return $xml->asXML();
}


/**
 * Function from original plugin w/ fixes for undefined indexes applied.
 */
function fn_shipstation_get_image_url($product_id, $object_type, $pair_type, $get_icon, $get_detailed) {
  $image_pair = fn_get_image_pairs($product_id, $object_type, $pair_type, $get_icon, $get_detailed);
  $final = (isset($image_pair['detailed']) && isset($image_pair['detailed']['http_image_path'])) ? $image_pair['detailed']['http_image_path'] : '';
  return $final;
}


/**
 * @param $prepend_status_string bool
 *   Whether or not to prepend the string "status_" to each key of the return value.
 *
 * @return array
 *   An assoc array whose keys are order status codes and whose values are their descriptions.
 */
function fn_shipstation_render_statuses_assoc($prepend_status_string = false) {
  $type = STATUSES_ORDER;
  $status_to_select = array();
  $additional_statuses = false;
  $exclude_parent = false;
  // Undefined constant warning: DESCR_SL
  $lang_code = (!defined('DESCR_SL')) ? CART_LANGUAGE : DESCR_SL;
  $company_id = 0;
  $statuses = fn_get_statuses($type, $status_to_select, $additional_statuses, $exclude_parent, $lang_code, $company_id);
  $assoc = array();
  if (!empty($statuses)) {

    // Legacy stuff. Not sure what the point of this was originally.
    $status_string_prepended = '';
    if ($prepend_status_string) {
      $status_string_prepended = 'status_';
    }

    foreach ($statuses as $k => $a) {
      $status[$k] = $status_string_prepended . $a['status'];
      $description[$k] = $a['description'];
      $assoc[$status[$k]] = $description[$k];
    }
  }
  return $assoc;
}


/**
 * Deprecated.
 */
function fn_shipstation_delete_status_post($status, $type, $can_delete)
{
    if ($type == 'O') { // order status
        $section_id = db_get_field("SELECT section_id FROM ?:settings_sections WHERE name='shipstation' AND type = 'ADDON'");
        $tab_id = db_get_field("SELECT section_id FROM ?:settings_sections WHERE parent_id = ?i AND type = 'TAB'", $section_id);

        $shipped_id = db_get_field("SELECT object_id FROM ?:settings_objects WHERE section_id = ?i AND name = 'shipped_statuses'", $section_id);
        $variant_id = db_get_field("SELECT variant_id FROM ?:settings_variants WHERE object_id = ?i AND name = ?s", $shipped_id, 'status_' . $status);
        if (!empty($variant_id)) {
            db_query("DELETE FROM ?:settings_descriptions WHERE object_id = ?i AND object_type = 'V'", $variant_id);
            db_query("DELETE FROM ?:settings_variants WHERE object_id = ?i AND name = ?s", $shipped_id, 'status_' . $status);
        }
    }
}

/**
 * Deprecated.
 */
function fn_shipstation_update_status_post($status, $status_data, $type, $lang_code)
{
    if ($type == 'O') { // order status
        $section_id = db_get_field("SELECT section_id FROM ?:settings_sections WHERE name='shipstation' AND type = 'ADDON'");
        $tab_id = db_get_field("SELECT section_id FROM ?:settings_sections WHERE parent_id = ?i AND type = 'TAB'", $section_id);
        $shipped_id = db_get_field("SELECT object_id FROM ?:settings_objects WHERE section_id = ?i AND name = 'shipped_statuses'", $section_id);

        $variant_id = db_get_field("SELECT variant_id FROM ?:settings_variants WHERE object_id = ?i AND name = ?s", $shipped_id, 'status_' . $status);
        if (empty($variant_id)) {
            $variant = array(
                'object_id' => $shipped_id,
                'name' => 'status_' . $status_data['status'],
            );
            $variant_id = db_query("INSERT INTO ?:settings_variants ?e", $variant);

            $variant_description = array(
                'object_id' => $variant_id,
                'object_type' => 'V',
                'lang_code' => $lang_code,
                'value' => $status_data['description'],
                'original_value' => '',
                'tooltip' => ''
            );
            db_query("INSERT INTO ?:settings_descriptions ?e", $variant_description);
        } else {
            db_query("UPDATE ?:settings_descriptions SET value = ?s WHERE object_id = ?i AND object_type = 'V'", $status_data['description'], $variant_id);
        }
    }

}

/**
 * Deprecated.
 */
function fn_shipstation_add_settings()
{
    if (fn_allowed_for('MULTIVENDOR')) {
        db_query("ALTER TABLE ?:companies ADD shipstation_username varchar(255) DEFAULT '', ADD shipstation_password varchar(255) DEFAULT ''");
    }

}


/**
 * Deprecated.
 */
function fn_shipstation_remove_settings()
{
    if (fn_allowed_for('MULTIVENDOR')) {
        db_query("ALTER TABLE ?:companies DROP COLUMN shipstation_username, DROP COLUMN shipstation_password");
    }
}


/**
 * Deprecated.
 */
function fn_shipstation_xml_header()
{
    return "<?xml version=\"1.0\" standalone=\"yes\" ?>";
}


/**
 * Deprecated.
 */
function fn_shipstation_convert_to_utf($string)
{
    return @iconv("ISO-8859-1", "UTF-8//TRANSLIT", $string);
}


/**
 * Deprecated.
 */
function fn_shipstation_add_tag($tag, $params = array())
{
    $result = fn_shipstation_convert_to_utf('<' . $tag);

    if (!empty($params) && is_array($params)) {
        $result .= ' ';
        foreach ($params as $name => $value) {
            $result .= fn_shipstation_convert_to_utf($name . '="'. htmlspecialchars($value). '" ');
        }
    }

    $result .= ">";
    return $result;
}


/**
 * Deprecated.
 */
function fn_shipstation_close_tag($tag)
{
    return fn_shipstation_convert_to_utf('</' . $tag . ">");
}


/**
 * Deprecated.
 */
function fn_shipstation_add_element($tag, $value, $is_special = false)
{
    $result = fn_shipstation_add_tag($tag);
    $value = '<![CDATA[' . $value . ']]>';
    $result .= $value;
    $result .= (($value) ? "" : "") . fn_shipstation_close_tag($tag);
    return $result;
}


/**
 * Deprecated.
 */
function fn_shipstation_add_element_attr($tag, $value, $params)
{
    $result = fn_shipstation_convert_to_utf('<'. $tag. ' ');

    if (!empty($params) && is_array($params)) { 
        foreach ($params as $name => $param) {
            $result .= fn_shipstation_convert_to_utf($name. '="'. htmlspecialchars($param). '" ');
        }
    }
    $result .= ">";
    $result .= fn_shipstation_convert_to_utf(htmlspecialchars($value));
    $result .= (($value) ? "" : ""). fn_shipstation_close_tag($tag);
    return $result;
}


/**
 * Deprecated.
 */
function fn_shipstation_error_tag($code, $error)
{
    $result = fn_shipstation_add_tag("Error");
    $result .= fn_shipstation_add_element("Code", $code);
    $result .= fn_shipstation_add_element("Desciption", $error);
    $result .= fn_shipstation_close_tag("Error");
    return $result; 
}


