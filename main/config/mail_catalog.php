<?php
/**
 * The catalogue of every email RestroGrow sends (Super Admin › Email › All emails):
 * who gets it, when, what starts it, and a preview made from sample data. Previews run the real
 * email functions in preview mode (config/mail_engine.php mailPreviewing()), so what you see is
 * exactly what goes out; nothing is read from or written to the database.
 */
require_once __DIR__ . '/mail_engine.php';

/** type => [when it is sent, what starts it / where it is set up] */
function mailSchedule(): array
{
    $hourly = 'Checked every hour (cron/email_dispatch.php, or any open dashboard)';
    return [
        'owner_welcome'   => ['Right after a restaurant signs up', 'Signup on the website or with Google'],
        'team_signup'     => ['Right after a restaurant signs up', 'Goes to MAIL_TEAM (or the sending address)'],
        'trial_ending'    => ['3 days before, 1 day before and on the day the trial ends', $hourly],
        'trial_ended'     => ['The day after the trial ended', $hourly],
        'renewal_due'     => ['7 days and 1 day before the plan renewal date', $hourly],
        'plan_expired'    => ['The day after the plan ended', $hourly],
        'payment_receipt' => ['Within an hour of a successful plan payment', $hourly],
        'invoice'         => ['When you press the mail button on an invoice', 'Super Admin › Invoice generator › list'],
        'new_device'      => ['When the owner or a staff member logs in from a new device or browser', 'Not in the account\'s first day; at most once a day per device'],
        'support_reply'   => ['When you reply to a support ticket', 'Super Admin › Support'],
        'daily_summary'   => ['Every morning at the time the owner picked', 'Owner turns on "Also send the report to my email" in Growth › AI manager; cron/owner_brief.php every 15 min'],
        'password_reset'  => ['At once, when someone uses "Forgot password"', 'Owner login, customer website login, Super Admin login'],
        'team_lead'       => ['When the contact form is sent or the chatbot gets a call-back request', 'Goes to MAIL_TEAM (or the sending address)'],
        'lead_autoreply'  => ['Right after someone sends the contact form', 'restrogrow.com contact form'],
        'order_confirmed' => ['When an order is confirmed', 'Website, WhatsApp and POS orders where the customer gave an email (or has one saved)'],
        'order_alert'     => ['When an order is confirmed', 'To the restaurant\'s email'],
        'order_status'    => ['When a takeaway / delivery order is accepted, ready or picked up by the rider, or any order is cancelled', 'Order status changes in Orders, KDS or the app; the rider taps Picked up'],
        'order_receipt'   => ['When the order is marked Completed', 'Any order with a customer email'],
        'booking'         => ['When a table is booked, confirmed or cancelled, and a reminder from 8 AM on the day', 'Website booking form and Dashboard › Reservations; reminder: ' . lcfirst($hourly)],
        'customer_welcome' => ['When a customer creates an account on the restaurant website', 'Customer signup on the ordering website'],
        'loyalty_gift'    => ['Within an hour of a gift being given (birthday, we miss you…)', 'Only customers with an email who don\'t get WhatsApp messages; ' . lcfirst($hourly)],
    ];
}

/** The sample restaurant, owner, order and booking the previews use. */
function mailSampleData(): array
{
    $site = mailAppUrl() . '/spice-garden-indiranagar';
    return [
        'restaurant' => ['id' => 0, 'restaurant_id' => 'SAMPLE', 'restaurant_name' => 'Spice Garden', 'owner_name' => 'Ravi Kumar', 'email' => 'ravi@spicegarden.in',
            'phone' => '98765 43210', 'address' => '12, 100 Feet Road, Indiranagar, Bengaluru', 'username' => 'spice-garden-indiranagar', 'custom_domain' => '',
            'logo_url' => '', 'created_at' => '2026-01-01 10:00:00', 'subscription_status' => 'trial'],
        'order' => ['id' => 0, 'restaurant_id' => 'SAMPLE', 'order_number' => 'ORD-20261017-4821', 'customer_name' => 'Priya Sharma', 'customer_phone' => '98110 22334',
            'order_type' => 'Delivery', 'table_number' => null, 'payment_method' => 'UPI', 'payment_status' => 'Paid', 'order_status' => 'Pending', 'source' => 'website',
            'subtotal' => 640, 'discount_amount' => 64, 'loyalty_discount_amount' => 0, 'vip_discount' => 0, 'tax' => 28.80, 'delivery_charge' => 40, 'total' => 664.80,
            'created_at' => date('Y-m-d H:i:s'), 'to' => 'priya@example.in',
            'items' => [['item_name' => 'Paneer Butter Masala', 'variation_name' => 'Full', 'quantity' => 1, 'total_price' => 320],
                ['item_name' => 'Butter Naan', 'variation_name' => null, 'quantity' => 4, 'total_price' => 200],
                ['item_name' => 'Gulab Jamun', 'variation_name' => null, 'quantity' => 2, 'total_price' => 120]]],
        'rider' => ['rider_name' => 'Imran Khan', 'rider_phone' => '98450 11223'],
        'booking' => ['id' => 0, 'restaurant_id' => 'SAMPLE', 'reservation_date' => date('Y-m-d', strtotime('+2 days')), 'time_slot' => '8:00 PM', 'no_of_guests' => 4,
            'customer_name' => 'Arjun Mehta', 'email' => 'arjun@example.in', 'status' => 'Pending'],
        'site' => $site,
    ];
}

/**
 * Every email, made from the sample data. Returns a list of
 * [key, type, variant, who, when, how, subject, from, html].
 */
function mailPreviewAll(PDO $conn): array
{
    $sample = mailSampleData();
    $owner = $sample['restaurant'];
    $ownerRow = ['restaurant_id' => 'SAMPLE', 'restaurant_name' => $owner['restaurant_name'], 'owner_name' => $owner['owner_name'], 'email' => $owner['email']];
    $inv = ['restaurant_id' => 'SAMPLE', 'restaurant_name' => 'Spice Garden', 'billed_to' => [], 'invoice_no' => 'RG/26-27/0012', 'invoice_date' => date('Y-m-d'),
        'due_date' => date('Y-m-d', strtotime('+7 days')), 'period_from' => date('Y-m-d'), 'period_to' => date('Y-m-d', strtotime('+1 year -1 day')), 'method' => 'UPI',
        'items' => [['desc' => 'RestroGrow Standard plan', 'qty' => 12, 'unit' => 'months', 'amount' => 11988]], 'discount' => 0, 'taxable' => 10159.32, 'igst' => 0, 'cgst' => 914.34, 'sgst' => 914.34, 'total' => 11988];
    $gift = ['id' => 0, 'restaurant_id' => 'SAMPLE', 'label' => '20% off', 'coupon_code' => 'BDAY-7Q2X', 'expires_on' => date('Y-m-d', strtotime('+7 days')), 'min_order' => 299, 'customer_name' => 'Priya Sharma', 'email' => 'priya@example.in'];
    $order = fn(string $status, string $type = 'Delivery') => function () use ($conn, $status, $type) {
        $GLOBALS['MAIL_PREVIEW']['order']['order_status'] = $status;
        $GLOBALS['MAIL_PREVIEW']['order']['order_type'] = $type;
        mailOrderStatus($conn, 0);
    };
    $scenarios = [
        'welcome'        => ['', fn() => mailOwnerWelcome($conn, 'SAMPLE')],
        'trial3'         => ['3 days before', fn() => mailTrialEmail($conn, $ownerRow + ['d' => 3])],
        'trial0'         => ['On the last day', fn() => mailTrialEmail($conn, $ownerRow + ['d' => 0])],
        'trialend'       => ['', fn() => mailTrialEmail($conn, $ownerRow + ['d' => -1])],
        'renew7'         => ['7 days before', fn() => mailRenewalEmail($conn, $ownerRow + ['d' => 7, 'renewal_date' => date('Y-m-d', strtotime('+7 days'))])],
        'expired'        => ['', fn() => mailRenewalEmail($conn, $ownerRow + ['d' => -1, 'renewal_date' => date('Y-m-d', strtotime('-1 day'))])],
        'payment'        => ['', fn() => mailPaymentEmail($conn, $ownerRow + ['id' => 0, 'amount' => 11988, 'subscription_type' => 'yearly', 'duration_months' => 12, 'payment_method' => 'upi', 'transaction_id' => 'T2610171234', 'created_at' => date('Y-m-d H:i:s')])],
        'invoice_due'    => ['Unpaid invoice', fn() => mailInvoice($conn, ['status' => 'due'] + $inv)],
        'invoice_paid'   => ['Paid (receipt)', fn() => mailInvoice($conn, ['status' => 'paid'] + $inv)],
        'device'         => ['', fn() => mailNewDevice($conn, 'SAMPLE', 'Staff member Rahul', '49.36.10.20', 'Mozilla/5.0 (Linux; Android 14)')],
        'support'        => ['', fn() => mailSupportReply($conn, 'SAMPLE', 'Printer not printing KOT', "Hi Ravi! Please open Settings › Printers and pick 58mm, then print a test KOT.\nIf it still doesn't print, reply here with a photo.")],
        'summary'        => ['', fn() => mailDailySummary($conn, 'SAMPLE', "Good morning Ravi! ☀️\n*Yesterday:* ₹18,450 from 42 orders, 12% more than last Thursday.\nTop dish: Paneer Butter Masala (31 sold).\n\nTwo things I can do for you:\n1. Turn on the \"we miss you\" gift for 38 customers who haven't come in a month\n2. Put Amritsari Fish back on the menu (off since Monday)", date('Y-m-d'))],
        'reset_owner'    => ['Owner', function () use ($conn) { $b = mailPlatformBrand(); mailQueue($conn, 'password_reset', 'ravi@spicegarden.in', 'Reset your RestroGrow password', mailResetHtml($b, 'We got a request to reset the password for <b>Spice Garden</b>. Tap the button to choose a new one.', mailAppUrl() . '/main/admin/reset_password.php?token=sample', 5), ['brand' => $b]); }],
        'reset_customer' => ['Customer', function () use ($conn) { $b = mailRestaurantBrand($conn, 'SAMPLE'); mailQueue($conn, 'password_reset', 'priya@example.in', 'Reset your password · ' . $b['name'], mailResetHtml($b, 'Hi Priya Sharma, tap the button to choose a new password for your <b>Spice Garden</b> account.', $b['site'] . '/reset?token=sample', 30), ['brand' => $b]); }],
        'lead_form'      => ['Contact form', fn() => mailNewLead($conn, 'contact form', ['name' => 'Anil Mehta', 'email' => 'anil@example.in', 'phone' => '98765 00000', 'message' => "Hi, I run a cafe in Pune with 2 outlets.\nCan we see a demo this week?"])],
        'lead_chat'      => ['Chatbot', fn() => mailNewLead($conn, 'website chatbot', ['name' => 'Sana', 'restaurant' => 'Sana Bakes', 'phone' => '98111 11111', 'message' => "• what is the price?\n• does it work without internet?"])],
        'order_new'      => ['', fn() => mailOrderConfirmed($conn, 0)],
        'order_accepted' => ['Accepted', $order('Accepted')],
        'order_ready'    => ['Ready (delivery)', $order('Ready')],
        'order_pickup'   => ['Ready (takeaway)', $order('Ready', 'Takeaway')],
        'order_onway'    => ['On the way (rider)', fn() => mailOrderOnTheWay($conn, 0)],
        'order_cancel'   => ['Cancelled', $order('Cancelled')],
        'order_bill'     => ['', $order('Completed')],
        'book_new'       => ['Received', fn() => mailBooking($conn, 0, 'received')],
        'book_ok'        => ['Confirmed', fn() => mailBooking($conn, 0, 'Confirmed')],
        'book_cancel'    => ['Cancelled', fn() => mailBooking($conn, 0, 'Cancelled')],
        'book_remind'    => ['Reminder on the day', fn() => mailBooking($conn, 0, 'reminder')],
        'cust_welcome'   => ['', fn() => mailCustomerWelcome($conn, 'SAMPLE', 'priya@example.in', 'Priya Sharma')],
        'gift_bday'      => ['Birthday', fn() => mailGiftEmail($conn, ['source' => 'birthday'] + $gift)],
        'gift_winback'   => ['We miss you', fn() => mailGiftEmail($conn, ['source' => 'winback', 'label' => '₹100 off', 'coupon_code' => 'MISSU-4K8P'] + $gift)],
    ];
    $types = mailTypes();
    $sched = mailSchedule();
    $out = [];
    $GLOBALS['MAIL_PREVIEW'] = $sample;
    try {
        foreach ($scenarios as $key => [$variant, $fn]) {
            $GLOBALS['MAIL_PREVIEW'] = $sample;
            $GLOBALS['MAIL_CAPTURED'] = [];
            try { $fn(); } catch (Throwable $e) { error_log("mail preview $key: " . $e->getMessage()); continue; }
            foreach ($GLOBALS['MAIL_CAPTURED'] as $i => $m) {
                $t = $m['type'];
                $out[] = ['key' => $key . ($i ? '_' . $i : ''), 'type' => $t, 'name' => $types[$t][0] ?? $t, 'variant' => $i ? '' : $variant, 'who' => $types[$t][1] ?? '',
                    'to' => $m['to'], 'when' => $sched[$t][0] ?? '', 'how' => $sched[$t][1] ?? '', 'subject' => $m['subject'], 'from' => $m['from_name'], 'html' => $m['html']];
            }
        }
    } finally {
        $GLOBALS['MAIL_PREVIEW'] = null;
        $GLOBALS['MAIL_CAPTURED'] = [];
    }
    return $out;
}
