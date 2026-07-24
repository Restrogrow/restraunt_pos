<?php
/**
 * Subscription Middleware
 * Include this file at the top of dashboard.php to check subscription status.
 * If expired, shows renewal modal and optionally blocks access.
 */

function checkSubscriptionAccess($conn, $user_id, $restaurant_id) {
    try {
        $stmt = $conn->prepare("SELECT id, subscription_status, trial_end_date, renewal_date, is_active FROM users WHERE id = ? LIMIT 1");
        $stmt->execute([$user_id]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            return ['blocked' => true, 'reason' => 'User not found'];
        }

        if ((int)$user['is_active'] === 0) {
            // If user was disabled by superadmin, block
            if ($user['subscription_status'] === 'disabled') {
                return ['blocked' => true, 'reason' => 'disabled'];
            }
        }

        $currentDate = date('Y-m-d');
        $subscriptionStatus = $user['subscription_status'];
        $renewalDate = $user['renewal_date'] ?? null;
        $trialEndDate = $user['trial_end_date'] ?? null;

        // Determine expiry date
        $expiryDate = null;
        if ($subscriptionStatus === 'active' && $renewalDate) {
            $expiryDate = $renewalDate;
        } elseif ($subscriptionStatus === 'trial' && $trialEndDate) {
            $expiryDate = $trialEndDate;
        }

        // Calculate days remaining
        $daysLeft = null;
        if ($expiryDate) {
            $expiry = new DateTime($expiryDate);
            $now = new DateTime();
            $daysLeft = (int)$now->diff($expiry)->days;
            if ($expiry < $now) {
                $daysLeft = -1; // Expired
            }
        }

        // Auto-update expired trials
        if ($subscriptionStatus === 'trial' && $trialEndDate && $trialEndDate < $currentDate) {
            try {
                $updateStmt = $conn->prepare("UPDATE users SET subscription_status = 'expired' WHERE id = ? AND subscription_status = 'trial'");
                $updateStmt->execute([$user_id]);
                $subscriptionStatus = 'expired';
            } catch (PDOException $e) {
                error_log("Subscription auto-expire error: " . $e->getMessage());
            }
        }

        if ($subscriptionStatus === 'active' && $renewalDate && $renewalDate < $currentDate) {
            // Subscription expired - mark as expired
            try {
                $updateStmt = $conn->prepare("UPDATE users SET subscription_status = 'expired' WHERE id = ? AND subscription_status = 'active'");
                $updateStmt->execute([$user_id]);
                $subscriptionStatus = 'expired';
            } catch (PDOException $e) {
                error_log("Subscription auto-expire error: " . $e->getMessage());
            }
        }

        // Block expired or disabled
        if ($subscriptionStatus === 'disabled' || $subscriptionStatus === 'expired') {
            return [
                'blocked' => true,
                'reason' => $subscriptionStatus,
                'status' => $subscriptionStatus,
                'days_left' => $daysLeft,
                'expiry_date' => $expiryDate
            ];
        }

        // Warn if trial is ending soon (within 7 days)
        $isExpiringSoon = ($daysLeft !== null && $daysLeft >= 0 && $daysLeft <= 7);

        return [
            'blocked' => false,
            'status' => $subscriptionStatus,
            'days_left' => $daysLeft,
            'expiry_date' => $expiryDate,
            'expiring_soon' => $isExpiringSoon,
            'trial_end_date' => $trialEndDate,
            'renewal_date' => $renewalDate
        ];

    } catch (Exception $e) {
        error_log("Subscription check error: " . $e->getMessage());
        return ['blocked' => false, 'status' => 'unknown', 'error' => $e->getMessage()];
    }
}
