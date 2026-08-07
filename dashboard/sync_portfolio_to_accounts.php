<?php
/**
 * ⚠️ DEPRECATED / DEAD CODE — DO NOT WIRE THIS UP ⚠️
 *
 * This was a duplicate of sync_portfolio_accounts.php that wrote market
 * value directly into accounts.balance, corrupting cost basis and causing
 * Total Wealth to double-count portfolio value. Nothing in the codebase
 * currently includes this file. It's left in place, neutered, so that if
 * anything references it later it fails loudly instead of silently
 * corrupting balances again.
 *
 * Use dashboard/sync_portfolio_accounts.php's getPortfolioMarketValue()
 * for display purposes instead.
 */

function syncPortfolioToAccounts(PDO $pdo): array {
    throw new Exception('syncPortfolioToAccounts() in sync_portfolio_to_accounts.php is deprecated — it wrote market value into accounts.balance. Use sync_portfolio_accounts.php::getPortfolioMarketValue() for display instead.');
}

