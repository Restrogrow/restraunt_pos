<?php
/**
 * UPI payment links — `upi://pay?pa=…`, the kind a customer taps to open GPay, PhonePe, Paytm or
 * BHIM with the restaurant's UPI ID and the amount already filled in. All they do is enter their PIN.
 *
 *   $link = rgUpiLink([
 *       'upi'    => 'spicegarden@okhdfcbank',
 *       'name'   => 'Spice Garden',
 *       'amount' => 450.00,
 *       'note'   => 'Order ORD-20261109-0042',
 *       'ref'    => 'ORD202611090042',
 *   ]);
 *
 * No gateway, no API keys, no per-transaction fee. Two things follow from that, and both have to be
 * designed around rather than discovered later:
 *
 *  - **Nothing comes back.** There is no callback and no webhook: the restaurant's bank tells the
 *    restaurant, not us. So an order paid this way stays Pending until somebody marks it paid. Put
 *    the reference in the link (`ref`) — it reaches the bank statement, which is what makes the two
 *    sides matchable at all.
 *  - **Only on the phone that has the app.** A `upi://` link does nothing on a laptop, and nothing
 *    in a browser with no UPI app behind it. Always show the QR code and the UPI ID as text beside
 *    the link, so the customer can scan from their own phone or pay by hand.
 *
 * Some apps also let the payer change the amount before paying, so the amount in the link is a
 * convenience, not a promise. Check what actually arrived.
 *
 * The scheme is NPCI's (https://www.npci.org.in/what-we-do/upi/product-overview): `pa` payee
 * address, `pn` payee name, `am` amount, `cu` currency, `tn` note, `tr` reference.
 */

if (!function_exists('rgUpiLink')) {

    /**
     * A UPI ID, cleaned up, or null if it is not one.
     *
     * The shape is `handle@bank` — `spicegarden@okhdfcbank`, `9876543210@paytm`. Nothing here can
     * tell whether it exists or belongs to the restaurant; it only rejects what no UPI app would
     * accept, so a typo is caught at the point it is typed instead of at the counter.
     */
    function rgUpiId($raw): ?string
    {
        // Trimmed, but never rewritten in the middle. Stripping the spaces out of "has space@ybl"
        // would turn it into a different, possibly real, UPI ID and send someone's money there
        // without a word - so anything with a space inside it is refused instead.
        $v = trim((string)$raw);
        if ($v === '') return null;
        // handle: letters, digits, dot, dash, underscore. bank: starts with a letter.
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}@[A-Za-z][A-Za-z0-9.-]{1,32}$/', $v)) return null;
        return strtolower($v);
    }

    /**
     * A reference the restaurant can find again in its bank statement: letters and digits only,
     * which is all `tr` reliably carries, and 35 characters at most.
     */
    function rgUpiRef($raw): string
    {
        $v = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string)$raw));
        return substr($v, 0, 35);
    }

    /**
     * Builds the link. Returns '' when there is no usable UPI ID, so a caller can simply not show
     * the button rather than having to check first.
     *
     * Keys: upi (required), name, amount, note, ref, currency (default INR).
     */
    function rgUpiLink(array $o): string
    {
        $upi = rgUpiId($o['upi'] ?? '');
        if ($upi === null) return '';

        $q = ['pa' => $upi];

        // The payee name is what the customer sees in their UPI app before they pay, so it is worth
        // sending even though it is optional - "Spice Garden" reads very differently from a bare
        // UPI handle. UPI apps show their own registered name for the account where they have one.
        $name = trim((string)($o['name'] ?? ''));
        if ($name !== '') $q['pn'] = mb_substr($name, 0, 50);

        // Amount: two decimal places, and only when it is actually positive. Sending am=0 makes
        // some apps open with a zero the customer then has to clear.
        $amount = isset($o['amount']) ? round((float)$o['amount'], 2) : 0.0;
        if ($amount > 0) $q['am'] = number_format($amount, 2, '.', '');

        $q['cu'] = strtoupper(trim((string)($o['currency'] ?? 'INR'))) ?: 'INR';

        $note = trim((string)($o['note'] ?? ''));
        if ($note !== '') $q['tn'] = mb_substr($note, 0, 50);   // NPCI's limit for the note

        $ref = rgUpiRef($o['ref'] ?? '');
        if ($ref !== '') $q['tr'] = $ref;

        $parts = [];
        foreach ($q as $k => $v) $parts[] = $k . '=' . rawurlencode((string)$v);
        return 'upi://pay?' . implode('&', $parts);
    }
}
