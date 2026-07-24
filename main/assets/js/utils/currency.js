/**
 * Currency formatting utilities
 * Extracted from script.js for better code organization
 */

/**
 * Format a number as currency with 2 decimal places
 * @param {number|string} amount - The amount to format
 * @returns {string} Formatted currency string (e.g., "₹1,250.00")
 */
function formatCurrency(amount) {
  const symbol = globalCurrencySymbol || window.globalCurrencySymbol || '₹';
  return `${symbol}${parseFloat(amount).toFixed(2)}`;
}

/**
 * Format a number as currency without decimal places
 * @param {number|string} amount - The amount to format
 * @returns {string} Formatted currency string (e.g., "₹1,250")
 */
function formatCurrencyNoDecimals(amount) {
  const symbol = globalCurrencySymbol || window.globalCurrencySymbol || '₹';
  return `${symbol}${parseFloat(amount).toLocaleString('en-IN', {maximumFractionDigits: 0})}`;
}
