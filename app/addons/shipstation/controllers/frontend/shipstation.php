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

header('Content-Type: text/xml');
header('Plugin-Version: 1.0.10');

if (!defined('BOOTSTRAP')) {
    die('Access denied');
}

use Tygh\Registry;

$action = strtolower($_REQUEST['action']);

$post_data = '';
if ($action == 'export') {

    $username = empty($_SERVER['PHP_AUTH_USER']) ? $_SERVER['HTTP_SS_AUTH_USER'] : $_SERVER['PHP_AUTH_USER'];
    $password = empty($_SERVER['PHP_AUTH_PW']) ? $_SERVER['HTTP_SS_AUTH_PW'] : $_SERVER['PHP_AUTH_PW'];

    //if (empty($_REQUEST['vendor']) || !fn_allowed_for('MULTIVENDOR')) {
    $addon_username = Registry::get('addons.shipstation.username');
    $addon_password = Registry::get('addons.shipstations.password');
    //} elseif (fn_allowed_for('MULTIVENDOR')) {
    //list($addon_username, $addon_password) = db_get_row("SELECT shipstation_username, shipstation_password FROM ?:companies WHERE company_id = ?i", $_REQUEST['vendor']);
    //}

    if ($username == null ||
        $password == null ||
        $username != $addon_username ||
        $password != $addon_password) {
        die('Access denied - Wrong username or password');
    }

    if (isset($_REQUEST['start_date'])) {
        $start_date = $_REQUEST['start_date'];
    }

    if (isset($_REQUEST['end_date'])) {
        $end_date = $_REQUEST['end_date'];
    }

    $page = empty($_REQUEST['page']) ? 1 : $_REQUEST['page'];
    $items_per_page = Registry::get('settings.Appearance.admin_orders_per_page');
    $limit = db_paginate($page, $items_per_page);
    $condition = " AND is_parent_order != 'Y'";
    $condition .= db_quote(" AND ((timestamp >= ?i AND timestamp <= ?i) OR (last_modify != 0 AND last_modify >= ?i AND last_modify <= ?i))", strtotime($start_date), strtotime($end_date), strtotime($start_date), strtotime($end_date));
    if (fn_allowed_for('MULTIVENDOR') && !empty($_REQUEST['vendor'])) {
        $condition .= db_quote(" AND company_id = ?i ", $_REQUEST['vendor']);
    }

    $order_ids = db_get_fields("SELECT order_id FROM ?:orders "
        . " WHERE 1 $condition  $limit");

    $total = db_get_field("SELECT COUNT(DISTINCT(order_id)) FROM ?:orders WHERE 1 $condition");
    $total_pages = ceil(($total * 1.0) / $items_per_page);

    $post_data = fn_shipstation_xml_header();

    $post_data .= fn_shipstation_add_tag("Orders", ($total_pages > 1 && $page == 1 ? array('pages' => $total_pages) : array())); // TODO split to pages

    foreach ($order_ids as $order_id) {
        $post_data .= fn_shipstation_add_order($order_id);
    }
    $post_data .= fn_shipstation_close_tag("Orders");

} elseif ($action == 'shipnotify') {
    $body = '';
    $fh = @fopen('php://input', 'r');
    if ($fh) {
        while (!feof($fh)) {
            $s = fread($fh, 1024);
            if (is_string($s)) {
                $body .= $s;
            }
        }
        fclose($fh);
    }
    $order_id = $_REQUEST['order_number'];

    $tracking_number = $_REQUEST['tracking_number'];

    if (empty($order_id) || empty($tracking_number)) {
        header("HTTP/1.0 404", true, 404);
        exit;
    } else {
        $order_info = fn_get_order_info($order_id);
        if (!empty($order_info)) {
            $products = $order_info['products'];

            $order_shipments = db_get_hash_array("SELECT sum(amount) as amount, item_id FROM ?:shipment_items WHERE order_id = ?i GROUP BY item_id", 'item_id', $order_id);
            $all_shipped = true;

            foreach ($products as $item_id => $product) {
                if (isset($order_shipments[$item_id])) {
                    $order_amount = $product['amount'];
                    $shipped_amount = $order_shipments[$item_id]['amount'];

                    if (($order_amount > $shipped_amount) || ($order_amount == $shipped_amount)) {
                        $all_shipped = false;
                        break;
                    }
                } else {
                    $all_shipped = false;
                    break;
                }
            }

            if ($all_shipped) {
                header("HTTP/1.0 404", true, 404);
                die('All shipped');
            }

            $carriers = fn_get_carriers();
            $carrier = '';
            foreach ($carriers as $key => $s_carrier) {
                if (strtolower($key) == strtolower($_REQUEST['carrier'])) {
                    $carrier = $key;
                    break;
                }
            }

            if (empty($carrier) && !empty($_carrier)) {
                $carrier = $_carrier;
            }
            $comments = '';
            $doc = new DomDocument('1.0', 'utf-8');
            $doc->loadXML($body);
            $xp = new DomXPath($doc);

            foreach ($xp->query('//NotesToCustomer') as $node) {
                $comments = $node->nodeValue;
            }

            foreach ($xp->query('//ShipDate') as $node) {
                $shipdate = $node->nodeValue;
            }


            $_products = array();
            foreach ($xp->query('//Items') as $node) {
                foreach ($node->childNodes as $item) {
                    $amount = 0;
                    $item_id = 0;
                    foreach ($item->childNodes as $subnode) {
                        if ($subnode->nodeName == 'Quantity') {
                            $amount = $subnode->nodeValue;
                        }
                        if ($subnode->nodeName == 'SKU') {
                            $order_details = db_get_row("SELECT item_id, amount FROM ?:order_details WHERE order_id = ?i AND product_code = ?s", $order_id, $subnode->nodeValue);
                            if (!empty($order_details['item_id'])) {
                                $item_id = $order_details['item_id'];
                            }
                        }
                    }
                    if (!empty($item_id)) {
                        $_products[$item_id] = $amount;
                    }
                }
            }

            $ship_data = array(
                'shipping_id' => $order_info['shipping_ids'],
                'tracking_number' => $tracking_number,
                'carrier' => $carrier,
                'comments' => $comments,
                'timestamp' => !empty($shipdate) ? strtotime($shipdate) : time()
            );

            $shipment_id = db_query("INSERT INTO ?:shipments ?e", $ship_data);

            foreach ($_products as $key => $amount) {
                if (isset($order_info['products'][$key])) {
                    $amount = intval($amount);
                }

                if ($amount == 0) {
                    continue;
                }

                $_data = array(
                    'item_id' => $key,
                    'shipment_id' => $shipment_id,
                    'order_id' => $order_id,
                    'product_id' => $order_info['products'][$key]['product_id'],
                    'amount' => $amount,
                );

                db_query("INSERT INTO ?:shipment_items ?e", $_data);
            }
            $order_shipments = db_get_hash_array("SELECT sum(amount) as amount, item_id FROM ?:shipment_items WHERE order_id = ?i GROUP BY item_id", 'item_id', $order_id);
            $all_shipped = true;

            foreach ($products as $item_id => $product) {
                if (isset($order_shipments[$item_id])) {
                    $order_amount = $product['amount'];
                    $shipped_amount = $order_shipments[$item_id]['amount'];

                    if ($order_amount > $shipped_amount) {
                        $all_shipped = false;
                        break;
                    }
                } else {
                    $all_shipped = false;
                    break;
                }
            }
            if ($all_shipped) {
                $shipped_status = Registry::get('addons.shipstation.shipped_statuses');
                if (is_array($shipped_status)) {
                    $shipped_status = reset(array_keys($shipped_status));
                    $shipped_status = str_replace('status_', '', $shipped_status);
                }
                if (empty($shipped_status)) {
                    $shipped_status = 'C';
                }
                fn_change_order_status($order_id, $shipped_status, '', true);
            }
            header("HTTP/1.0 200", true, 200);
            exit;
        } else {
            header("HTTP/1.0 404", true, 404);
            exit;
        }

    }
} else {
    header("HTTP/1.0 200", true, 200);
    exit;
}

echo $post_data;

exit;
