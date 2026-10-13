<?php
/**
 * Why an order was rejected or cancelled, as the customer should hear it.
 *
 * The dashboard (assets/js/new-order-popup.js) sends a reason code with each reject / cancel;
 * api/update_order_status.php stores it in the order notes as "[Rejected (out_of_stock): …]".
 * Only these preset codes are shown to the customer, in friendly words. A custom reason the
 * owner typed stays private (the customer just sees that the order wasn't taken).
 */
if (!function_exists('orderStopMessage')) {
    function orderStopMessage(?string $notes): string
    {
        if (!$notes || !preg_match_all('/\[(Rejected|Cancelled) \(([a-z_]+)\):/', $notes, $m)) return '';
        $code = end($m[2]);
        $say = [
            'out_of_stock'   => 'Some items in your order aren’t available right now.',
            'too_busy'       => 'The kitchen is very busy at the moment and can’t take more orders.',
            'closed'         => 'The restaurant has closed for now.',
            'too_far'        => 'Your address is outside the restaurant’s delivery area.',
            'no_answer'      => 'The restaurant couldn’t reach you to confirm the order.',
            'customer_asked' => 'Cancelled as you asked.',
            'kitchen_issue'  => 'The kitchen ran into a problem with your order.',
            'delivery_issue' => 'The restaurant couldn’t deliver to your address this time.',
        ];
        return $say[$code] ?? '';
    }
}
