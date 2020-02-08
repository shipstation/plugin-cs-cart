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

function fn_shipstation_create_order(&$order)
{
    $order['last_modify'] = time();
}

function fn_shipstation_update_order(&$order, $order_id)
{
    $order['last_modify'] = time();
}
function fn_shipstation_change_order_status(&$status_to, &$status_from, &$order_info, &$force_notification, &$order_statuses)
{
    $time = time();
    db_query("UPDATE ?:orders SET last_modify = ?s WHERE order_id = ?i", $time, $order_info['order_id']);
}
function fn_shipstation_add_settings()
{
    $section_id = db_get_field("SELECT section_id FROM ?:settings_sections WHERE name='shipstation' AND type = 'ADDON'");
    $tab_id = db_get_field("SELECT section_id FROM ?:settings_sections WHERE parent_id = ?i AND type = 'TAB'", $section_id);
    $shipped_id = db_get_field("SELECT object_id FROM ?:settings_objects WHERE section_id = ?i AND name = 'shipped_statuses'", $section_id);
    
    $statuses = fn_get_statuses();
    
    $n = 0;
    foreach ($statuses as $status) {
        $variant = array(
            'object_id' => $shipped_id,
            'name' => 'status_' . $status['status'],
            'position' => $n++ * 10,
        );
        $variant_id = db_query("INSERT INTO ?:settings_variants ?e", $variant);
        $variant_description = array(
            'object_id' => $variant_id,
            'object_type' => 'V',
            'lang_code' => DESCR_SL,
            'value' => $status['description'],
            'original_value' => '',
            'tooltip' => ''
        );
        db_query("INSERT INTO ?:settings_descriptions ?e", $variant_description);
    }
    
    if (fn_allowed_for('MULTIVENDOR')) {
        db_query("ALTER TABLE ?:companies ADD shipstation_username varchar(255) DEFAULT '', ADD shipstation_password varchar(255) DEFAULT ''");
    }
    
    //$object_id = db_get_field("SELECT object_id FROM ?:settings_objects WHERE section_id = ?i AND name = 'username'", $section_id);
    //if (!empty($object_id)) {
        //$username = db_get_field("SELECT value FROM ?:settings_descriptions WHERE object_id = ?i AND object_type = 'O' AND lang_code = ?s", $object_id, DESCR_SL);
        //$username .= "<br/>(Enter this Store URL when configure your ShipStation: https://" . Registry::get('config.https_host') . Registry::get('config.https_path') . "/?dispatch=shipstation)";
        //db_query("UPDATE ?:settings_descriptions SET value = ?s WHERE object_id = ?i AND object_type = 'O' AND lang_code = ?s", $username, $object_id, DESCR_SL);
    //}
}

function fn_shipstation_get_store_url()
{
    return "Store URL: https://" . Registry::get('config.https_host') . Registry::get('config.https_path') . "/?dispatch=shipstation";
}

function fn_shipstation_remove_settings()
{
    if (fn_allowed_for('MULTIVENDOR')) {
        db_query("ALTER TABLE ?:companies DROP COLUMN shipstation_username, DROP COLUMN shipstation_password");
    }
}


function fn_shipstation_xml_header()
{
    return "<?xml version=\"1.0\" standalone=\"yes\" ?>";
}

function fn_shipstation_convert_to_utf($string)
{
    return @iconv("ISO-8859-1", "UTF-8//TRANSLIT", $string);
}

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

function fn_shipstation_close_tag($tag)
{
    return fn_shipstation_convert_to_utf('</' . $tag . ">");
}

function fn_shipstation_add_element($tag, $value, $is_special = false)
{
    $result = fn_shipstation_add_tag($tag);
    $value = '<![CDATA[' . $value . ']]>';
    $result .= $value;
    $result .= (($value) ? "" : "") . fn_shipstation_close_tag($tag);
    return $result;
}

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

function fn_shipstation_error_tag($code, $error)
{
    $result = fn_shipstation_add_tag("Error");
    $result .= fn_shipstation_add_element("Code", $code);
    $result .= fn_shipstation_add_element("Desciption", $error);
    $result .= fn_shipstation_close_tag("Error");
    return $result; 
}

function fn_shipstation_add_order($order_id)
{
    $order_info = fn_get_order_info($order_id);
    if (!$order_info) {
        return '';
    }

    $order_shipping = '';
    if ($order_info['shipping'] && is_array($order_info['shipping'])) {
        foreach ($order_info['shipping'] as $ship) {
            if ($ship['shipping']) {
                $order_shipping = $ship['shipping'];
                break;
            }
        }
    }
    
    $order_statuses = fn_get_statuses(STATUSES_ORDER, array(), true, false, CART_LANGUAGE, $order_info['company_id']);

    $result  = fn_shipstation_add_tag("Order");
    
    $result .= fn_shipstation_add_element("OrderID", $order_info['order_id']);
    $result .= fn_shipstation_add_element("OrderNumber", $order_info['order_id']);
    $result .= fn_shipstation_add_element("OrderDate", date("n/j/Y H:i A", $order_info['timestamp']));
    $order_status = $order_statuses[$order_info['status']];
    $result .= fn_shipstation_add_element("OrderStatus", strtolower($order_status['description']));
    
        
    $result .= fn_shipstation_add_element("LastModified", date("n/j/Y H:i A", empty($order_info['last_modify']) ? $order_info['timestamp'] : $order_info['last_modify']));
    
    $result .= fn_shipstation_add_element("ShippingMethod", $order_shipping);
    
    $payment_method = reset($order_info['payment_method']);
    $payment_method = $payment_method['payment'];
    
    $result .= fn_shipstation_add_element("PaymentMethod", $payment_method);
    
    $result .= fn_shipstation_add_element("OrderTotal", $order_info['total']);
    
    $result .= fn_shipstation_add_element("TaxAmount", $order_info['tax_subtotal']);
    $result .= fn_shipstation_add_element("ShippingAmount", $order_info['shipping_cost']);
    $result .= fn_shipstation_add_element("CustomerNotes", $order_info['notes']);
    $result .= fn_shipstation_add_element("InternalNotes", $order_info['details']);
    
    $result .= fn_shipstation_add_tag('Customer');

    $result .= fn_shipstation_add_element('CustomerCode', $order_info['email']);
    
    $result .= fn_shipstation_add_tag('BillTo');
    $result .= fn_shipstation_add_element('Name', $order_info['b_firstname'] . ' ' . $order_info['b_lastname']);
    $result .= fn_shipstation_add_element('Company', $order_info['company']);

    $b_phone = ''; $s_phone = ''; $phone = '';
    if(isset($order_info['b_phone']) && $order_info['b_phone']) {
        $b_phone = $order_info['b_phone'];
    } 
    
    if(isset($order_info['s_phone']) && $order_info['s_phone']) {
        $s_phone = $order_info['s_phone'];
    }

    // check for other phone fields if s_phone or b_phone null
    if(!$b_phone || !$s_phone) {
        if(isset($order_info['b_phone_bs']) && $order_info['b_phone_bs']) {
            $phone = $order_info['b_phone_bs'];
        } elseif(isset($order_info['phone']) && $order_info['phone']) {
            $phone = $order_info['phone'];
        }
        $b_phone = ($b_phone) ? $b_phone : $phone;
        $s_phone = ($s_phone) ? $s_phone : $phone;

    }

    $result .= fn_shipstation_add_element('Phone', $b_phone);
    $result .= fn_shipstation_add_element('Email', $order_info['email']);
    $result .= fn_shipstation_close_tag('BillTo');
    
    $result .= fn_shipstation_add_tag('ShipTo');
    $result .= fn_shipstation_add_element('Name', $order_info['s_firstname'] . ' ' . $order_info['s_lastname']);
    $result .= fn_shipstation_add_element('Company', $order_info['company']);
    $result .= fn_shipstation_add_element('Address1', $order_info['s_address']);
    $result .= fn_shipstation_add_element('Address2', $order_info['s_address_2']);
    $result .= fn_shipstation_add_element('City', $order_info['s_city']);
    $result .= fn_shipstation_add_element('State', $order_info['s_state']);
    $result .= fn_shipstation_add_element('PostalCode', $order_info['s_zipcode']);
    $result .= fn_shipstation_add_element('Country', $order_info['s_country']);
    $result .= fn_shipstation_add_element('Phone', $s_phone);
    $result .= fn_shipstation_close_tag('ShipTo');
    
    $result .= fn_shipstation_close_tag('Customer');
    
    $result .= fn_shipstation_add_tag('Items');
    foreach ($order_info['products'] as $item) {
        $result .= fn_shipstation_add_tag('Item');
        
        $result .= fn_shipstation_add_element('SKU', $item['product_code']);
        $result .= fn_shipstation_add_element('Name', $item['product']);
        
        $product_image = fn_shipstation_get_image_url($item['product_id'], 'product', 'M', true, true);
        $result .= fn_shipstation_add_element('ImageUrl', $product_image); 
        
        //removed for null weigh and altered code on 10 apr 2019
        //$product_weight = db_get_field("SELECT weight FROM ?:products WHERE product_id=?i", $item['product_id']);

        $product_weight = db_get_field("SELECT MAX(weight) AS weight FROM (select weight from ?:products where product_id=?i UNION SELECT 0) w", $item['product_id']);
        //if ($item['extra']) {
            //$product_weight = fn_apply_options_modifiers($item['extra']['product_options'], $product_weight, "W", array(), array('product_data' => $item));
        //}
        if($product_weight == null || !$product_weight) {
            $product_weight = '0.000';
        }
        $result .= fn_shipstation_add_element('Weight', $product_weight); 
        $weight_units = Registry::get('settings.General.weight_symbol');
        // |pound|pounds|lb|lbs|gram|grams|gm|oz|ounces|Pound|Pounds|Lb|Lbs|Gram|Grams|Gm|Oz|Ounces|POUND|POUNDS|LB|LBS|GRAM|GRAMS|GM|OZ|OUNCES
        if (!in_array(strtolower($weight_units), array('pound', 'pounds', 'lb', 'lbs', 'gram', 'gm', 'oz', 'ounces', 'grams'))) {
            $weight_units = 'grams';
        }
        $result .= fn_shipstation_add_element('WeightUnits', $weight_units); 
        $result .= fn_shipstation_add_element('Quantity', $item['amount']); 
        $result .= fn_shipstation_add_element('UnitPrice', $item['price']); 
        $result .= fn_shipstation_add_element('Location', '');  //TODO fixme
        if (!empty($item['product_options'])) { 
            $result .= fn_shipstation_add_tag('Options');
            foreach ($item['product_options'] as $option) {
                $result .= fn_shipstation_add_tag('Option');
                $result .= fn_shipstation_add_element('Name', $option['option_name']);
                $result .= fn_shipstation_add_element('Value', $option['variant_name']);
                $weight = db_get_row("SELECT weight_modifier, weight_modifier_type FROM ?:product_option_variants WHERE option_id = ?i AND variant_id = ?i", $option['option_id'], $option['value']);
                if ($weight['weight_modifier_type'] == 'A') {
                    $option_weight = $weight['weight_modifier'];
                } else {
                    $option_weight = $product_weight + ((floatval($weight['weight_modifier']) * $product_weight) / 100);
                }
                if(!$option_weight || $option_weight == null) {
                    $option_weight = '0.000';   
                }
                $result .= fn_shipstation_add_element('Weight', $option_weight); 
                $result .= fn_shipstation_close_tag('Option');
            }
            $result .= fn_shipstation_close_tag('Options');
        
        }
        $result .= fn_shipstation_close_tag('Item');
    }
    $result .= fn_shipstation_close_tag('Items');
    $result .=  fn_shipstation_close_tag("Order");    
    return $result;
}

function fn_shipstation_get_image_url($product_id, $object_type, $pair_type, $get_icon, $get_detailed)
{
    $image_pair = fn_get_image_pairs($product_id, $object_type, $pair_type, $get_icon, $get_detailed);
    return $image_pair['detailed']['http_image_path'];
}