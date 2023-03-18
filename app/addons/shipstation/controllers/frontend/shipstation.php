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
use Tygh\Shippings\Shippings;

$continue_call = fn_shipstation_api_call_proceed();
$action = (isset($_REQUEST['action']) && !empty($_REQUEST['action']) && is_string($_REQUEST['action'])) ? trim(strtolower($_REQUEST['action'])) : 0;
$force_empty_data = (isset($_REQUEST['force_empty_data']) && !empty($_REQUEST['force_empty_data']));
if ($force_empty_data) {
  $action = 'force_empty_data';
}

$vendor = '0';

if (isset($_REQUEST['vendor']) && !empty($_REQUEST['vendor']) && is_numeric($_REQUEST['vendor'])) {
  $vendor = $_REQUEST['vendor'];
}
$vendor = (int) $vendor;

$page = (isset($_REQUEST['page']) && !empty($_REQUEST['page']) && is_numeric($_REQUEST['page'])) ? $_REQUEST['page'] : 1;
$page = (int) $page;

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


if ($continue_call) {

  $post_data = '';

  if ($action == 'force_empty_data') {
    $post_data = fn_shipstation_force_empty_data_export();    
  }
  elseif ($action == 'export') {
    $post_data = fn_shipstation_orders_export();    
  }
  // No vaildation here previously -- anyone could have technically POSTed any info and it
  // would have be accepted by CS-Cart.
  elseif ($action == 'shipnotify') {
    $body = '';
    $fh   = @fopen('php://input', 'r');
    if ($fh) {
      while (!feof($fh)) {
        $s = fread($fh, 1024);
        if (is_string($s)) {
          $body .= $s;
        }
      }
      fclose($fh);
    }
    $order_id = (isset($_REQUEST['order_number']) && !empty($_REQUEST['order_number'])) ? $_REQUEST['order_number'] : 0;

    $tracking_number = (isset($_REQUEST['tracking_number']) && !empty($_REQUEST['tracking_number'])) ? $_REQUEST['tracking_number'] : 0;

    if (empty($order_id) || empty($tracking_number)) {
      return array(CONTROLLER_STATUS_NO_PAGE);
    }
    else {
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
          }
          else {
            $all_shipped = false;
            break;
          }
        }

        if ($all_shipped) {
          return array(CONTROLLER_STATUS_NO_CONTENT);
        }

        $carriers = Shippings::getCarriers();
        $carrier = '';
        $carrier_requested = (isset($_REQUEST['carrier']) && !empty($_REQUEST['carrier']) && is_string($_REQUEST['carrier'])) ? $_REQUEST['carrier'] : '';
        if (!empty($carrier_requested)) {
          foreach ($carriers as $s_carrier) {
            $s_carrier_lower = strtolower($s_carrier);
            $carrier_requested_lower = strtolower($carrier_requested);
            if ($s_carrier_lower == $carrier_requested_lower) {
              $carrier = $s_carrier;
              break;
            }
          }
        }

        if (empty($carrier) && !empty($_carrier)) {
          $carrier = $_carrier;
        }

        $comments = '';

        $comments_parts = array();
        $_products = array();

        if (!strlen($body)) {

        }
        else {
          $doc = new DomDocument('1.0', 'utf-8');
          $doc->loadXML($body);
          $xp = new DomXPath($doc);

          foreach ($xp->query('//NotesToCustomer') as $node) {
            $comments_parts[] = $node->nodeValue;
          }
          $comments = implode(PHP_EOL, $comments_parts);

          foreach ($xp->query('//ShipDate') as $node) {
            $shipdate = $node->nodeValue;
          }

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

        }
        $ship_data = array(
          'shipping_id' => $order_info['shipping_ids'],
          'tracking_number' => $tracking_number,
          'carrier' => $carrier,
          'comments' => $comments,
          'timestamp' => !empty($shipdate) ? strtotime($shipdate) : TIME,
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

        $order_shipments = db_get_hash_array("SELECT SUM(amount) AS amount, item_id FROM ?:shipment_items WHERE order_id = ?i GROUP BY item_id", 'item_id', $order_id);
        $all_shipped = true;

        foreach ($products as $item_id => $product) {
          if (isset($order_shipments[$item_id])) {
            $order_amount = $product['amount'];
            $shipped_amount = $order_shipments[$item_id]['amount'];

            if ($order_amount > $shipped_amount) {
              $all_shipped = false;
              break;
            }
          }
          else {
            $all_shipped = false;
            break;
          }
        }
        if ($all_shipped) {
          $shipped_status_final = Registry::ifGet('addons.shipstation.shipped_status_final', 'C');
          fn_change_order_status($order_id, $shipped_status_final, '', true);
        }

        header("HTTP/1.0 200", true, 200);
        exit();
      }
      else {
        return array(CONTROLLER_STATUS_NO_PAGE);
      }
    }
  }

  if (!empty($post_data)) {
    header('Content-Type: text/xml');
    print $post_data;
    exit();
  }
  return array(CONTROLLER_STATUS_OK);
}

return array(CONTROLLER_STATUS_DENIED);


