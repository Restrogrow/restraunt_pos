-- Set all existing users to cash_only payment mode
UPDATE users SET payment_gateway_type = 'cash_only' WHERE payment_gateway_type IS NULL OR payment_gateway_type != 'cash_only';
