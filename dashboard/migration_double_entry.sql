-- ═══════════════════════════════════════════════════════════════
-- Double-entry ledger + budgets — run once in phpMyAdmin
-- ═══════════════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS ledger_entries (
    id INT AUTO_INCREMENT PRIMARY KEY,
    txn_id INT NULL,                    -- links back to transactions.id (NULL for none)
    entry_date DATE NOT NULL,
    account_id INT NULL,                -- a real account, or NULL if bucket is used
    bucket VARCHAR(20) NULL,            -- 'EQUITY' or 'PORTFOLIO' when account_id is NULL
    debit_bhd DECIMAL(15,4) NOT NULL DEFAULT 0,
    credit_bhd DECIMAL(15,4) NOT NULL DEFAULT 0,
    description VARCHAR(255),
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_txn (txn_id),
    INDEX idx_account (account_id),
    INDEX idx_date (entry_date),
    INDEX idx_bucket (bucket)
) ENGINE=InnoDB;

-- Every entry is one leg of a debit/credit pair. Two rows per economic
-- event (one debit, one credit), always in BHD so they can be summed
-- across currencies. account_id points at a real accounts.id row for
-- real accounts; account_id is NULL with bucket='EQUITY' for the
-- virtual equity account (income/expense/adjustments/realized gains),
-- or bucket='PORTFOLIO' for stock cost basis (buys/sells at cost).

CREATE TABLE IF NOT EXISTS budgets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    category VARCHAR(100) NOT NULL,
    monthly_limit_bhd DECIMAL(15,4) NOT NULL DEFAULT 0,
    is_active TINYINT(1) DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_category (category)
) ENGINE=InnoDB;

-- ═══════════════════════════════════════════════════════════════
-- Balance checkpoints — replaces the hardcoded 18-June array with a
-- real, re-usable checkpoint you can reset any time you've manually
-- verified every balance is correct (like after the July 2026 fix-up).
-- ═══════════════════════════════════════════════════════════════
CREATE TABLE IF NOT EXISTS balance_checkpoints (
    id INT AUTO_INCREMENT PRIMARY KEY,
    account_id INT NOT NULL,
    checkpoint_date DATE NOT NULL,
    balance DECIMAL(15,4) NOT NULL,
    currency VARCHAR(10) NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_account (account_id)
) ENGINE=InnoDB;
-- One row per account — setting a new checkpoint replaces the old one
-- for that account (ON DUPLICATE KEY UPDATE), so there's always exactly
-- one "current" checkpoint per account, not a growing history table.

-- ═══════════════════════════════════════════════════════════════
-- FIX — checkpoint_date needs time precision, not just the date.
-- Run this if you already created balance_checkpoints as DATE —
-- date-only precision double-counted same-day transactions that
-- happened BEFORE the checkpoint was clicked (their effect was
-- already baked into the checkpoint balance, then counted again as
-- "since checkpoint" because both fell on the same calendar day).
-- Safe to run even if the column is already DATETIME.
-- ═══════════════════════════════════════════════════════════════
ALTER TABLE balance_checkpoints MODIFY COLUMN checkpoint_date DATETIME NOT NULL;

-- ═══════════════════════════════════════════════════════════════
-- Reverse instead of delete — transactions are never physically
-- deleted anymore. "Deleting" now marks the original as reversed and
-- inserts a new offsetting transaction, keeping full history.
-- ═══════════════════════════════════════════════════════════════
ALTER TABLE transactions ADD COLUMN reversed_at DATETIME NULL;
ALTER TABLE transactions ADD COLUMN reversal_of INT NULL;
-- reversed_at is set on the ORIGINAL transaction the moment it's reversed.
-- reversal_of is set on the NEW offsetting transaction, pointing back to
-- the original's id.

-- ═══════════════════════════════════════════════════════════════
-- Link fixed_assets to a funding account, same pattern as portfolio.
-- ═══════════════════════════════════════════════════════════════
ALTER TABLE fixed_assets ADD COLUMN account_id INT NULL;
ALTER TABLE fixed_assets ADD COLUMN status VARCHAR(10) NOT NULL DEFAULT 'owned';
ALTER TABLE fixed_assets ADD COLUMN sold_date DATE NULL;
ALTER TABLE fixed_assets ADD COLUMN sold_price DECIMAL(20,2) NULL;
-- status: 'owned' or 'sold'. Selling doesn't delete the row — it's kept
-- for history, same reasoning as reversing instead of deleting transactions.
