<?php
/**
 * Shared default policy page text.
 *
 * Used by both the public policy pages (privacy-policy.php, terms-of-service.php,
 * refund-policy.php, shipping-policy.php, cookie-policy.php) to render the built-in
 * wording, and by policy_pages_api.php to pre-fill the admin's editor textarea so an
 * owner can tweak specific parts of the default text instead of starting from scratch.
 */

/**
 * Turns freeform admin-entered text into safe HTML for display.
 * If the content already contains block-level HTML tags, it's trusted as-is
 * (the admin is writing markup on purpose). Otherwise it's treated as plain
 * text: blank-line-separated paragraphs become <p> tags and single newlines
 * become <br>, so simply typing text (no HTML knowledge required) still comes
 * out readable instead of one unstyled run-on block.
 */
function formatPolicyContent($content) {
    $content = trim((string)$content);
    if ($content === '') {
        return '';
    }
    if (preg_match('/<(p|h[1-6]|ul|ol|li|div|table|br)\b/i', $content)) {
        return $content;
    }
    $paragraphs = preg_split('/\n\s*\n/', $content);
    $html = '';
    foreach ($paragraphs as $para) {
        $para = trim($para);
        if ($para === '') continue;
        $html .= '<p>' . nl2br(htmlspecialchars($para, ENT_QUOTES, 'UTF-8')) . '</p>';
    }
    return $html;
}

function getDefaultPrivacyPolicyHtml($restaurant_name, $restaurant_owner, $restaurant_email, $restaurant_phone, $cookie_policy_url = 'cookie-policy') {
    $name = htmlspecialchars($restaurant_name, ENT_QUOTES, 'UTF-8');
    $owner = htmlspecialchars($restaurant_owner ?: 'Not specified', ENT_QUOTES, 'UTF-8');
    $email = htmlspecialchars($restaurant_email ?: 'restrogrow@gmail.com', ENT_QUOTES, 'UTF-8');
    $phone = htmlspecialchars($restaurant_phone ?: '+91 6377568749', ENT_QUOTES, 'UTF-8');
    $cookieUrl = htmlspecialchars($cookie_policy_url, ENT_QUOTES, 'UTF-8');
    return <<<HTML
<p>At {$name}, we are committed to protecting your privacy. This Privacy Policy explains how we collect, use, disclose, and safeguard your information when you use our restaurant management and ordering platform.</p>

<h2>1. Information We Collect</h2>
<h3>1.1 Personal Information</h3>
<p>We may collect personal information that you provide to us, including:</p>
<ul>
    <li>Name and contact information (email address, phone number)</li>
    <li>Delivery address and location data</li>
    <li>Payment information (processed securely through third-party payment processors)</li>
    <li>Order history and preferences</li>
    <li>Account credentials (username, password)</li>
</ul>

<h3>1.2 Automatically Collected Information</h3>
<p>When you use our platform, we may automatically collect:</p>
<ul>
    <li>Device information (IP address, browser type, operating system)</li>
    <li>Usage data (pages visited, time spent, features used)</li>
    <li>Cookies and similar tracking technologies</li>
    <li>Location data (if you enable location services)</li>
</ul>

<h2>2. How We Use Your Information</h2>
<p>We use the collected information for the following purposes:</p>
<ul>
    <li>To process and fulfill your orders</li>
    <li>To communicate with you about your orders and account</li>
    <li>To improve our services and user experience</li>
    <li>To send promotional offers and updates (with your consent)</li>
    <li>To detect and prevent fraud or abuse</li>
    <li>To comply with legal obligations</li>
</ul>

<h2>3. Information Sharing and Disclosure</h2>
<p>We do not sell your personal information. We may share your information in the following circumstances:</p>
<ul>
    <li><strong>Service Providers:</strong> With third-party service providers who assist in operating our platform (payment processors, delivery services, analytics providers)</li>
    <li><strong>Legal Requirements:</strong> When required by law or to protect our rights and safety</li>
    <li><strong>Business Transfers:</strong> In connection with a merger, acquisition, or sale of assets</li>
    <li><strong>With Your Consent:</strong> When you explicitly authorize us to share your information</li>
</ul>

<h2>4. Data Security</h2>
<p>We implement appropriate technical and organizational measures to protect your personal information against unauthorized access, alteration, disclosure, or destruction. However, no method of transmission over the internet is 100% secure.</p>

<h2>5. Your Rights</h2>
<p>Depending on your location, you may have the following rights regarding your personal information:</p>
<ul>
    <li>Right to access your personal data</li>
    <li>Right to rectify inaccurate data</li>
    <li>Right to erasure ("right to be forgotten")</li>
    <li>Right to restrict processing</li>
    <li>Right to data portability</li>
    <li>Right to object to processing</li>
    <li>Right to withdraw consent</li>
</ul>

<h2>6. Cookies and Tracking Technologies</h2>
<p>We use cookies and similar technologies to enhance your experience, analyze usage, and assist with marketing efforts. For more information, please see our <a href="{$cookieUrl}" style="color: var(--primary-red);">Cookie Policy</a>.</p>

<h2>7. Data Retention</h2>
<p>We retain your personal information for as long as necessary to fulfill the purposes outlined in this Privacy Policy, unless a longer retention period is required or permitted by law.</p>

<h2>8. Children's Privacy</h2>
<p>Our platform is not intended for children under the age of 13. We do not knowingly collect personal information from children under 13. If you believe we have collected information from a child under 13, please contact us immediately.</p>

<h2>9. Changes to This Privacy Policy</h2>
<p>We may update this Privacy Policy from time to time. We will notify you of any changes by posting the new Privacy Policy on this page and updating the "Last updated" date.</p>

<h2>10. Contact Us</h2>
<p>If you have any questions about this Privacy Policy or our data practices, please contact us at:</p>
<p>
    <strong>Owner:</strong> {$owner}<br>
    <strong>Email:</strong> {$email}<br>
    <strong>Phone:</strong> {$phone}<br>
    <strong>Address:</strong> {$name}, Customer Support
</p>
HTML;
}

function getDefaultTermsOfServiceHtml($restaurant_name, $restaurant_owner, $restaurant_email, $restaurant_phone) {
    $name = htmlspecialchars($restaurant_name, ENT_QUOTES, 'UTF-8');
    $owner = htmlspecialchars($restaurant_owner ?: 'Not specified', ENT_QUOTES, 'UTF-8');
    $email = htmlspecialchars($restaurant_email ?: 'restrogrow@gmail.com', ENT_QUOTES, 'UTF-8');
    $phone = htmlspecialchars($restaurant_phone ?: '+91 6377568749', ENT_QUOTES, 'UTF-8');
    $ownedByLine = $restaurant_owner ? ' (Owned by ' . htmlspecialchars($restaurant_owner, ENT_QUOTES, 'UTF-8') . ')' : '';
    return <<<HTML
<p>Welcome to {$name}{$ownedByLine}. These Terms of Service ("Terms") govern your access to and use of our restaurant management and ordering platform. By accessing or using our services, you agree to be bound by these Terms.</p>

<h2>1. Acceptance of Terms</h2>
<p>By accessing or using {$name}, you acknowledge that you have read, understood, and agree to be bound by these Terms and our Privacy Policy. If you do not agree to these Terms, you may not use our services.</p>

<h2>2. Description of Service</h2>
<p>{$name} provides a platform for restaurants to manage their operations and for customers to place orders, make reservations, and interact with restaurants. We reserve the right to modify, suspend, or discontinue any aspect of the service at any time.</p>

<h2>3. User Accounts</h2>
<h3>3.1 Account Creation</h3>
<p>To use certain features of our platform, you may be required to create an account. You agree to:</p>
<ul>
    <li>Provide accurate, current, and complete information</li>
    <li>Maintain and update your account information</li>
    <li>Maintain the security of your account credentials</li>
    <li>Accept responsibility for all activities under your account</li>
</ul>

<h3>3.2 Account Termination</h3>
<p>We reserve the right to suspend or terminate your account if you violate these Terms or engage in fraudulent, abusive, or illegal activity.</p>

<h2>4. Orders and Payments</h2>
<h3>4.1 Order Placement</h3>
<p>When you place an order through our platform:</p>
<ul>
    <li>You agree to pay the prices displayed for the items you order</li>
    <li>All prices are subject to change without notice</li>
    <li>Orders are subject to availability</li>
    <li>The restaurant reserves the right to refuse or cancel any order</li>
</ul>

<h3>4.2 Payment</h3>
<p>Payment must be made at the time of order placement or as otherwise specified. We accept various payment methods as displayed on the platform. All payments are processed securely through third-party payment processors.</p>

<h3>4.3 Refunds and Cancellations</h3>
<p>Refund and cancellation policies are determined by the individual restaurant. Please contact the restaurant directly for refund or cancellation requests.</p>

<h2>5. User Conduct</h2>
<p>You agree not to:</p>
<ul>
    <li>Use the service for any illegal purpose</li>
    <li>Violate any applicable laws or regulations</li>
    <li>Infringe upon the rights of others</li>
    <li>Transmit any harmful, offensive, or inappropriate content</li>
    <li>Interfere with or disrupt the service</li>
    <li>Attempt to gain unauthorized access to any part of the service</li>
    <li>Use automated systems to access the service without permission</li>
</ul>

<h2>6. Intellectual Property</h2>
<p>All content, features, and functionality of {$name}, including but not limited to text, graphics, logos, images, and software, are owned by {$name} or its licensors and are protected by copyright, trademark, and other intellectual property laws.</p>

<h2>7. Disclaimers</h2>
<p>THE SERVICE IS PROVIDED "AS IS" AND "AS AVAILABLE" WITHOUT WARRANTIES OF ANY KIND, EITHER EXPRESS OR IMPLIED. WE DO NOT WARRANT THAT THE SERVICE WILL BE UNINTERRUPTED, ERROR-FREE, OR SECURE.</p>

<h2>8. Limitation of Liability</h2>
<p>TO THE MAXIMUM EXTENT PERMITTED BY LAW, {$name} SHALL NOT BE LIABLE FOR ANY INDIRECT, INCIDENTAL, SPECIAL, CONSEQUENTIAL, OR PUNITIVE DAMAGES, OR ANY LOSS OF PROFITS OR REVENUES, WHETHER INCURRED DIRECTLY OR INDIRECTLY.</p>

<h2>9. Indemnification</h2>
<p>You agree to indemnify and hold harmless {$name}, its affiliates, and their respective officers, directors, employees, and agents from any claims, damages, losses, liabilities, and expenses arising out of your use of the service or violation of these Terms.</p>

<h2>10. Modifications to Terms</h2>
<p>We reserve the right to modify these Terms at any time. We will notify users of any material changes by posting the updated Terms on this page and updating the "Last updated" date. Your continued use of the service after such modifications constitutes acceptance of the updated Terms.</p>

<h2>11. Governing Law</h2>
<p>These Terms shall be governed by and construed in accordance with the laws of the jurisdiction in which {$name} operates, without regard to its conflict of law provisions.</p>

<h2>12. Contact Information</h2>
<p>If you have any questions about these Terms of Service, please contact us at:</p>
<p>
    <strong>Owner:</strong> {$owner}<br>
    <strong>Email:</strong> {$email}<br>
    <strong>Phone:</strong> {$phone}<br>
    <strong>Address:</strong> {$name}, Customer Support
</p>
HTML;
}

function getDefaultRefundPolicyHtml($restaurant_name, $restaurant_owner, $restaurant_email, $restaurant_phone) {
    $name = htmlspecialchars($restaurant_name, ENT_QUOTES, 'UTF-8');
    $owner = htmlspecialchars($restaurant_owner ?: 'Not specified', ENT_QUOTES, 'UTF-8');
    $email = htmlspecialchars($restaurant_email ?: 'restrogrow@gmail.com', ENT_QUOTES, 'UTF-8');
    $phone = htmlspecialchars($restaurant_phone ?: '+91 6377568749', ENT_QUOTES, 'UTF-8');
    return <<<HTML
<p>At {$name}, all orders are made to order. Please read this Refund Policy carefully before placing an order.</p>

<h2>1. No Refunds</h2>
<p><strong>We do not offer refunds.</strong> Once an order has been placed and payment has been completed, it is final and cannot be refunded, cancelled, or exchanged for cash, regardless of the reason.</p>

<h2>2. Order Cancellations</h2>
<p>If you wish to cancel an order, please contact the restaurant immediately. Cancellation may only be possible before the order has entered the preparation stage, and is at the sole discretion of the restaurant. No monetary refund will be issued even if a cancellation is accepted.</p>

<h2>3. Order Issues</h2>
<p>If you received an incorrect order, missing items, or have a quality concern, please contact the restaurant directly within 24 hours of receiving your order. While we do not issue refunds, the restaurant may, at its sole discretion, offer a replacement or other resolution for genuine issues.</p>

<h2>4. Non-Refundable Items</h2>
<p>All orders, including but not limited to the following, are non-refundable:</p>
<ul>
    <li>Items consumed or partially consumed</li>
    <li>Orders where the issue is due to customer preferences (e.g., taste, spice level)</li>
    <li>Promotional or discounted items</li>
    <li>Orders cancelled after preparation has begun</li>
</ul>

<h2>5. Chargebacks</h2>
<p>If you believe a charge is incorrect, please contact us before initiating a chargeback with your bank. We are committed to resolving any billing issues promptly and fairly. Unnecessary chargebacks may result in account suspension.</p>

<h2>6. Dispute Resolution</h2>
<p>If you are unsatisfied with the resolution provided by the restaurant, please contact our support team at the details below. We will mediate between you and the restaurant to find a fair resolution.</p>

<h2>7. Contact Us</h2>
<p>If you have any questions about this Refund Policy, please contact us:</p>
<p>
    <strong>Owner:</strong> {$owner}<br>
    <strong>Email:</strong> {$email}<br>
    <strong>Phone:</strong> {$phone}
</p>
HTML;
}

function getDefaultShippingPolicyHtml($restaurant_name, $restaurant_owner, $restaurant_email, $restaurant_phone) {
    $name = htmlspecialchars($restaurant_name, ENT_QUOTES, 'UTF-8');
    $owner = htmlspecialchars($restaurant_owner ?: 'Not specified', ENT_QUOTES, 'UTF-8');
    $email = htmlspecialchars($restaurant_email ?: 'restrogrow@gmail.com', ENT_QUOTES, 'UTF-8');
    $phone = htmlspecialchars($restaurant_phone ?: '+91 6377568749', ENT_QUOTES, 'UTF-8');
    $ownedByLine = $restaurant_owner ? ' (Owned by ' . htmlspecialchars($restaurant_owner, ENT_QUOTES, 'UTF-8') . ')' : '';
    return <<<HTML
<p>At {$name}{$ownedByLine}, we are committed to delivering your orders promptly and efficiently. This Shipping & Delivery Policy explains our delivery practices and what you can expect when you order from us.</p>

<h2>1. Delivery Areas</h2>
<p>We currently deliver to select areas based on pincode availability. Please enter your pincode during checkout to check if we deliver to your location. We continuously expand our delivery zones to serve more customers.</p>

<h2>2. Delivery Timeframes</h2>
<h3>2.1 Estimated Delivery Time</h3>
<p>Our estimated delivery time is displayed at checkout and depends on your location, order size, and current demand. Typical delivery times range from 30-60 minutes. Please note that these are estimates and actual delivery times may vary.</p>

<h3>2.2 Peak Hours</h3>
<p>During peak hours (typically lunch 12:00 PM - 2:00 PM and dinner 7:00 PM - 9:00 PM), delivery times may be longer than usual. We appreciate your patience during these busy periods.</p>

<h2>3. Delivery Charges</h2>
<p>Delivery charges are calculated based on your delivery location and displayed at checkout before you place your order. Some orders may qualify for free delivery based on promotional offers or order value thresholds.</p>

<h2>4. Order Tracking</h2>
<p>You can track your order status through your profile page on our website. We provide real-time updates on order preparation, dispatch, and estimated arrival time.</p>

<h2>5. Delivery Attempts</h2>
<p>Our delivery partners will make reasonable attempts to deliver your order to the address provided. If delivery is not possible due to incorrect address or unavailability, we will contact you using the phone number provided with your order.</p>

<h2>6. Self-Pickup / Takeaway</h2>
<p>If you prefer, you can choose the Takeaway option at checkout and pick up your order directly from {$name}. Please arrive within the specified pickup time to ensure your food is fresh.</p>

<h2>7. Delivery Restrictions</h2>
<p>We reserve the right to refuse or cancel delivery in certain circumstances, including but not limited to: incorrect or incomplete addresses, adverse weather conditions, or areas we cannot safely access with our delivery partners.</p>

<h2>8. Contact Us</h2>
<p>If you have any questions about our Shipping & Delivery Policy, please contact us at:</p>
<p>
    <strong>Owner:</strong> {$owner}<br>
    <strong>Email:</strong> {$email}<br>
    <strong>Phone:</strong> {$phone}<br>
    <strong>Address:</strong> {$name}, Delivery Support
</p>
HTML;
}

function getDefaultCookiePolicyHtml($restaurant_name, $restaurant_owner, $restaurant_email, $restaurant_phone) {
    $name = htmlspecialchars($restaurant_name, ENT_QUOTES, 'UTF-8');
    $owner = htmlspecialchars($restaurant_owner ?: 'Not specified', ENT_QUOTES, 'UTF-8');
    $email = htmlspecialchars($restaurant_email ?: 'restrogrow@gmail.com', ENT_QUOTES, 'UTF-8');
    $phone = htmlspecialchars($restaurant_phone ?: '+91 6377568749', ENT_QUOTES, 'UTF-8');
    return <<<HTML
<p>This Cookie Policy explains how {$name} uses cookies and similar tracking technologies when you visit our website and use our platform. It explains what these technologies are and why we use them, as well as your rights to control our use of them.</p>

<h2>1. What Are Cookies?</h2>
<p>Cookies are small text files that are placed on your device (computer, tablet, or mobile) when you visit a website. They are widely used to make websites work more efficiently and provide information to the website owners.</p>

<h2>2. How We Use Cookies</h2>
<p>We use cookies for several purposes:</p>
<ul>
    <li><strong>Essential Cookies:</strong> These cookies are necessary for the website to function properly. They enable core functionality such as security, network management, and accessibility.</li>
    <li><strong>Performance Cookies:</strong> These cookies help us understand how visitors interact with our website by collecting and reporting information anonymously.</li>
    <li><strong>Functionality Cookies:</strong> These cookies allow the website to remember choices you make (such as your username, language, or region) and provide enhanced, personalized features.</li>
    <li><strong>Targeting/Advertising Cookies:</strong> These cookies may be set through our site by our advertising partners to build a profile of your interests and show you relevant content on other sites.</li>
</ul>

<h2>3. Types of Cookies We Use</h2>
<table class="cookie-table">
    <thead>
        <tr>
            <th>Cookie Name</th>
            <th>Purpose</th>
            <th>Duration</th>
        </tr>
    </thead>
    <tbody>
        <tr>
            <td>session_id</td>
            <td>Maintains your session while using the platform</td>
            <td>Session</td>
        </tr>
        <tr>
            <td>cart_data</td>
            <td>Stores your shopping cart items</td>
            <td>30 days</td>
        </tr>
        <tr>
            <td>user_preferences</td>
            <td>Remembers your preferences (language, currency, etc.)</td>
            <td>1 year</td>
        </tr>
        <tr>
            <td>cookie_consent</td>
            <td>Stores your cookie consent preferences</td>
            <td>1 year</td>
        </tr>
        <tr>
            <td>analytics_id</td>
            <td>Helps us analyze website usage and improve our services</td>
            <td>2 years</td>
        </tr>
    </tbody>
</table>

<h2>4. Third-Party Cookies</h2>
<p>In addition to our own cookies, we may also use various third-party cookies to report usage statistics of the service, deliver advertisements, and so on. These third-party cookies include:</p>
<ul>
    <li><strong>Google Analytics:</strong> Helps us understand how visitors use our website</li>
    <li><strong>Payment Processors:</strong> Cookies used by payment providers to process transactions securely</li>
    <li><strong>Social Media Platforms:</strong> Cookies from social media platforms if you interact with social features</li>
</ul>

<h2>5. Managing Cookies</h2>
<p>You have the right to decide whether to accept or reject cookies. You can exercise your cookie rights by setting your preferences in our cookie consent banner or by configuring your browser settings.</p>

<h3>5.1 Browser Settings</h3>
<p>Most web browsers allow you to control cookies through their settings preferences. However, limiting cookies may impact your ability to use our website. Here are links to cookie settings for popular browsers:</p>
<ul>
    <li><a href="https://support.google.com/chrome/answer/95647" target="_blank" style="color: var(--primary-red);">Google Chrome</a></li>
    <li><a href="https://support.mozilla.org/en-US/kb/enable-and-disable-cookies-website-preferences" target="_blank" style="color: var(--primary-red);">Mozilla Firefox</a></li>
    <li><a href="https://support.apple.com/guide/safari/manage-cookies-and-website-data-sfri11471/mac" target="_blank" style="color: var(--primary-red);">Safari</a></li>
    <li><a href="https://support.microsoft.com/en-us/microsoft-edge/delete-cookies-in-microsoft-edge-63947406-40ac-c3b8-57b9-2a946a29ae09" target="_blank" style="color: var(--primary-red);">Microsoft Edge</a></li>
</ul>

<h3>5.2 Cookie Consent Banner</h3>
<p>When you first visit our website, you will see a cookie consent banner. You can accept all cookies, reject non-essential cookies, or customize your preferences. You can change your preferences at any time by clicking the cookie settings link in the footer.</p>

<h2>6. Do Not Track Signals</h2>
<p>Some browsers incorporate a "Do Not Track" (DNT) feature that signals to websites you visit that you do not want to have your online activity tracked. Currently, there is no standard for how DNT signals should be interpreted. As a result, our website does not currently respond to DNT signals.</p>

<h2>7. Updates to This Cookie Policy</h2>
<p>We may update this Cookie Policy from time to time to reflect changes in the cookies we use or for other operational, legal, or regulatory reasons. Please revisit this Cookie Policy regularly to stay informed about our use of cookies.</p>

<h2>8. Contact Us</h2>
<p>If you have any questions about our use of cookies or this Cookie Policy, please contact us at:</p>
<p>
    <strong>Owner:</strong> {$owner}<br>
    <strong>Email:</strong> {$email}<br>
    <strong>Phone:</strong> {$phone}<br>
    <strong>Address:</strong> {$name}, Privacy Team
</p>
HTML;
}
