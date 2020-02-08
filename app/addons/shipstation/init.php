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

fn_register_hooks(
    'create_order',
    'update_order',
    'change_order_status',
    'update_status_post',
    'delete_status_post'
);

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
	$disptch_method = isset($_REQUEST['dispatch']) ? $_REQUEST['dispatch'] : 0 ;
	if(is_array($disptch_method) && array_key_exists("orders.update_details", $disptch_method)) {
		$order_id = isset($_REQUEST['order_id']) ? $_REQUEST['order_id'] : 0 ;
		if($order_id){
	    	db_query("UPDATE ?:orders SET last_modify = ?s WHERE order_id = ?i", time(), $order_id);
		}
    }
}