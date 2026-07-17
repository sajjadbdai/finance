-- ============================================================
--  SAJJAD FINANCE — database.sql
--  Run this once in Exonhost phpMyAdmin
-- ============================================================

CREATE TABLE IF NOT EXISTS accounts (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(100) NOT NULL,
    group_name  VARCHAR(100) DEFAULT '',
    currency    VARCHAR(10)  DEFAULT 'BHD',
    balance     DECIMAL(15,4) DEFAULT 0,
    type        ENUM('asset','liability') DEFAULT 'asset',
    is_active   TINYINT(1) DEFAULT 1,
    created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS transactions (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    txn_date      DATETIME NOT NULL,
    type          ENUM('income','expense','transfer') NOT NULL,
    amount        DECIMAL(15,4) NOT NULL,
    currency      VARCHAR(10) DEFAULT 'BHD',
    amount_bhd    DECIMAL(15,4) DEFAULT 0,
    account_id    INT,
    to_account_id INT DEFAULT NULL,
    category      VARCHAR(100) DEFAULT '',
    subcategory   VARCHAR(100) DEFAULT '',
    note          TEXT,
    source        ENUM('telegram','web','import','voice') DEFAULT 'web',
    raw_input     TEXT,
    created_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (account_id)    REFERENCES accounts(id) ON DELETE SET NULL,
    FOREIGN KEY (to_account_id) REFERENCES accounts(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS categories (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(100) NOT NULL,
    parent      VARCHAR(100) DEFAULT '',
    icon        VARCHAR(10)  DEFAULT '',
    type        ENUM('income','expense','transfer') DEFAULT 'expense'
);

CREATE TABLE IF NOT EXISTS exchange_rates (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    from_cur    VARCHAR(10) NOT NULL,
    to_cur      VARCHAR(10) NOT NULL,
    rate        DECIMAL(15,6) NOT NULL,
    updated_at  DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY pair (from_cur, to_cur)
);

CREATE TABLE IF NOT EXISTS bot_sessions (
    telegram_id BIGINT PRIMARY KEY,
    state       VARCHAR(50) DEFAULT 'idle',
    context     TEXT,
    updated_at  DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- ---- Seed: your accounts from screenshots ----
INSERT INTO accounts (name, group_name, currency, balance, type) VALUES
('Cash In Hand',              'Cash',                   'BHD',  94.52,     'asset'),
('AL Salam',                  'Bahrain Savings A/C',    'BHD',  792.35,    'asset'),
('BBK',                       'Bahrain Savings A/C',    'BHD',  2097.42,   'asset'),
('BisB',                      'Bahrain Savings A/C',    'BHD',  449.40,    'asset'),
('ILA',                       'Bahrain Savings A/C',    'BHD',  24.71,     'asset'),
('KFH',                       'Bahrain Savings A/C',    'BHD',  310.86,    'asset'),
('NBB',                       'Bahrain Savings A/C',    'BHD',  217.30,    'asset'),
('SC',                        'Bahrain Savings A/C',    'BHD',  170.00,    'asset'),
('STC and Beyon',             'Bahrain Savings A/C',    'BHD',  70.87,     'asset'),
('Al Salam Danat',            'Bahrain Deposit A/C',    'BHD',  100.43,    'asset'),
('ILA Kanz',                  'Bahrain Deposit A/C',    'BHD',  250.00,    'asset'),
('DBBL 13 months FD',         'TK Fixed Deposit A/C',  'BDT',  50000.00,  'asset'),
('MTB Credit card security',  'TK Fixed Deposit A/C',  'BDT',  400000.00, 'asset'),
('Pubali',                    'TK Fixed Deposit A/C',  'BDT',  53538.00,  'asset'),
('City Bank FDR 6 month',     'TK Fixed Deposit A/C',  'BDT',  50000.00,  'asset'),
('Brac probashi fixed deposit','TK Bond',               'BDT',  100000.00, 'asset'),
('Brac Wage earner Development','TK Bond',              'BDT',  100000.00, 'asset'),
('Padma Bank',                'TK Bond',                'BDT',  100000.00, 'asset'),
('Post Office Bonds 3 months','TK Bond',                'BDT',  500000.00, 'asset'),
('Brac EPL ODS622',           'Investments',            'BDT',  65000.00,  'asset'),
('Berich 179387',             'Investments',            'BDT',  60000.00,  'asset'),
('Binance FX',                'Investments',            'BHD',  20.00,     'asset'),
('Al Salam Platinum VISA 750','Credit Card',            'BHD',  -5.00,     'liability'),
('BisB Signature VISA 1000',  'Credit Card',            'BHD',  -38.81,    'liability'),
('Credimax Talabat MC 400',   'Credit Card',            'BHD',  -337.14,   'liability'),
('ILA Platinum Master 400',   'Credit Card',            'BHD',  0.00,      'liability'),
('Imtiaz Platinum Master 300','Credit Card',            'BHD',  0.00,      'liability'),
('MTB Signature Visa Tk 300000','Credit Card',          'BDT',  0.00,      'liability'),
('Brac Bank',                 'TK Savings A/C',         'BDT',  36119.95,  'asset'),
('City Bank',                 'TK Savings A/C',         'BDT',  13264.85,  'asset'),
('DBBL',                      'TK Savings A/C',         'BDT',  33906.26,  'asset'),
('IBBL With Cellfin',         'TK Savings A/C',         'BDT',  7151.44,   'asset'),
('Midland',                   'TK Savings A/C',         'BDT',  43715.80,  'asset'),
('Kapil Bhi',                 'Loan Given to',          'BDT',  100000.00, 'asset'),
('Jashim BFC',                'Loan Given to',          'BHD',  25.00,     'asset'),
('Jahangir Bhi',              'Loan Given to',          'BHD',  100.00,    'asset'),
('Bangladesh School',         'Refundable Security Deposit','BHD', 100.00, 'asset'),
('EWA Deposit Sitra',         'Refundable Security Deposit','BHD', 100.00, 'asset'),
('GOSI Deposit',              'Refundable Security Deposit','BHD', 1140.00,'asset'),
('Liability GNF and GNS',     'GNF and GNS fund',       'BHD',  -1348.58,  'liability');

-- ---- Seed: exchange rates ----
INSERT INTO exchange_rates (from_cur, to_cur, rate) VALUES
('BHD', 'BDT', 325.00),
('BDT', 'BHD', 0.003077),
('USD', 'BHD', 0.377),
('BHD', 'USD', 2.652),
('GBP', 'BHD', 0.476),
('BHD', 'GBP', 2.101)
ON DUPLICATE KEY UPDATE rate=VALUES(rate);

-- ---- Seed: categories ----
INSERT INTO categories (name, parent, icon, type) VALUES
('Salary',          '',           '💼', 'income'),
('Profit from I&S', '',           '📈', 'income'),
('Dividend',        '',           '💰', 'income'),
('Food',            '',           '🍽', 'expense'),
('Grocery',         'Food',       '🛒', 'expense'),
('Eating out',      'Food',       '🍜', 'expense'),
('Monthly fixed',   '',           '📅', 'expense'),
('House Rent',      'Monthly fixed','🏠','expense'),
('School Fees',     'Monthly fixed','🏫','expense'),
('Electricity bill','Monthly fixed','⚡','expense'),
('Fuel',            'Monthly fixed','⛽','expense'),
('Mobile/Internet', 'Monthly fixed','📱','expense'),
('Medical',         '',           '🏥', 'expense'),
('Doctors',         'Medical',    '👨‍⚕️','expense'),
('Medicine',        'Medical',    '💊', 'expense'),
('Apparel',         '',           '👔', 'expense'),
('Clothing',        'Apparel',    '👗', 'expense'),
('Laundry',         'Apparel',    '🧺', 'expense'),
('Social Life',     '',           '👥', 'expense'),
('Gift',            'Social Life','🎁', 'expense'),
('Beauty',          '',           '💅', 'expense'),
('Household',       '',           '🏡', 'expense'),
('Transport',       '',           '🚗', 'expense'),
('Education',       '',           '📚', 'expense'),
('Investment',      '',           '📊', 'transfer'),
('Transfer',        '',           '🔄', 'transfer'),
('Other',           '',           '📌', 'expense');
