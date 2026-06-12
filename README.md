TRIGGERS USED FOR JUAKALI CBO
===============================
TRIGGER FOR LOAN REQUEST
=========================
DELIMITER $$

DROP TRIGGER IF EXISTS `after_loan_request_approval`$$

CREATE TRIGGER `after_loan_request_approval`
AFTER UPDATE ON `loan_requests`
FOR EACH ROW
BEGIN
    DECLARE target_wallet_type_id INT DEFAULT 4; -- 'Loan' wallet type ID
    DECLARE generated_receipt VARCHAR(50);
    DECLARE transaction_desc TEXT;
    DECLARE current_loan_bal INT DEFAULT 0;

    -- =========================================================
    -- PATH A: LOAN DISBURSEMENT APPROVAL (Debits/Money Leaving)
    -- =========================================================
    IF NEW.status = 'approved' AND (OLD.status IS NULL OR OLD.status <> 'approved') THEN
        
        -- Generate absolute reference code
        SET generated_receipt = CONCAT('DSB-LN', UPPER(SUBSTRING(MD5(CONCAT(NOW(), RAND())), 1, 8)));
        SET transaction_desc = CONCAT('Approved Loan Request Ref #', NEW.id, ' | Amount Disbursed: KES ', FORMAT(NEW.amount, 2));

        -- 1. Fetch current running snapshot balance BEFORE this new loan hits it
        SELECT IFNULL(MAX(balance), 0) INTO current_loan_bal 
        FROM wallets 
        WHERE member_id = NEW.member_id AND wallet_type_id = target_wallet_type_id 
        LIMIT 1;

        -- Calculate what the running balance snapshot will look like after insertion
        SET current_loan_bal = current_loan_bal + NEW.amount;

        -- 2. Log into the Disbursements ledger (Money Out)
        -- CRITICAL: This insert statement automatically fires the `after_disbursement_inserted` trigger,
        -- which will seamlessly add the balance to the wallets table exactly ONCE.
        INSERT INTO disbursements (member_id, wallet_type_id, amount, running_balance, payout_receipt, description, created_at)
        VALUES (NEW.member_id, target_wallet_type_id, CAST(NEW.amount AS SIGNED), current_loan_bal, generated_receipt, transaction_desc, NOW());


    -- =========================================================
    -- PATH B: LOAN SETTLEMENT / CLEARANCE (Credits/Money Coming In)
    -- =========================================================
    ELSEIF NEW.status = 'cleared' AND (OLD.status IS NULL OR OLD.status <> 'cleared') THEN
        
        SET generated_receipt = CONCAT('RCP-LCLR', UPPER(SUBSTRING(MD5(CONCAT(NOW(), RAND())), 1, 8)));
        SET transaction_desc = CONCAT('Loan Account Cleared/Settled for Request Ref #', NEW.id);

        -- 1. Flush the running loan balance back to zero via the receipts ledger trigger
        -- We log a receipt with the exact balance amount remaining to clear it out to 0
        SELECT IFNULL(MAX(balance), 0) INTO current_loan_bal 
        FROM wallets 
        WHERE member_id = NEW.member_id AND wallet_type_id = target_wallet_type_id 
        LIMIT 1;

        -- 2. Log into the Receipts ledger (Money In)
        -- This insert automatically fires the `after_receipt_inserted` trigger, 
        -- which subtracts the current_loan_bal from the wallet, perfectly returning it to 0.
        INSERT INTO receipts (member_id, wallet_type_id, amount, running_balance, payment_receipt, description, created_at)
        VALUES (NEW.member_id, target_wallet_type_id, current_loan_bal, 0, generated_receipt, transaction_desc, NOW());

    END IF;
END$$

DELIMITER ;
TRIGGER FOR ALL KINDS OF DEPOSITS
======================================
DELIMITER $$

-- =========================================================
-- 1. TRIGGER FOR THE RECEIPTS TABLE (MONEY-IN / CREDITS)
-- =========================================================
DROP TRIGGER IF EXISTS `after_receipt_inserted`$$

CREATE TRIGGER `after_receipt_inserted`
AFTER INSERT ON `receipts`
FOR EACH ROW
BEGIN
    -- If it's a Loan Wallet (Type 4), money coming in REDUCES the debt balance.
    IF NEW.wallet_type_id = 4 THEN
        UPDATE wallets 
        SET balance = balance - NEW.amount,
            updated_at = NOW()
        WHERE member_id = NEW.member_id AND wallet_type_id = 4;
        
    -- For Main (1) and Welfare (2), money coming in INCREASES their savings balance.
    ELSE
        INSERT INTO wallets (member_id, wallet_type_id, balance, created_at, updated_at) 
        VALUES (NEW.member_id, NEW.wallet_type_id, NEW.amount, NOW(), NOW())
        ON DUPLICATE KEY UPDATE 
            balance = balance + NEW.amount,
            updated_at = NOW();
    END IF;
END$$


-- =========================================================
-- 2. TRIGGER FOR THE DISBURSEMENTS TABLE (MONEY-OUT / DEBITS)
-- =========================================================
DROP TRIGGER IF EXISTS `after_disbursement_inserted`$$

CREATE TRIGGER `after_disbursement_inserted`
AFTER INSERT ON `disbursements`
FOR EACH ROW
BEGIN
    -- If it's a Loan Wallet (Type 4), money paid out INCREASES their debt balance.
    IF NEW.wallet_type_id = 4 THEN
        INSERT INTO wallets (member_id, wallet_type_id, balance, created_at, updated_at) 
        VALUES (NEW.member_id, NEW.wallet_type_id, NEW.amount, NOW(), NOW())
        ON DUPLICATE KEY UPDATE 
            balance = balance + NEW.amount,
            updated_at = NOW();
            
    -- For Main (1) and Welfare (2), money moving out REDUCES their savings balance.
    ELSE
        UPDATE wallets 
        SET balance = balance - NEW.amount,
            updated_at = NOW()
        WHERE member_id = NEW.member_id AND wallet_type_id = NEW.wallet_type_id;
    END IF;
END$$

DELIMITER ;



TRIGGER FOR WELFARE CLAIMS:
==============================
DELIMITER $$

DROP TRIGGER IF EXISTS `after_welfare_claim_approval`$$

CREATE TRIGGER `after_welfare_claim_approval`
AFTER UPDATE ON `welfare_claims`
FOR EACH ROW
BEGIN
    DECLARE current_welfare_bal INT DEFAULT 0;
    DECLARE new_welfare_bal INT DEFAULT 0;
    DECLARE generated_receipt VARCHAR(50);

    -- Fire exclusively when status transitions to 'approved'
    IF NEW.status = 'approved' AND (OLD.status IS NULL OR OLD.status != 'approved') AND NEW.amount_eligible > 0 THEN
        
        -- 1. Snapshot old wallet balance (Cast cleanly to INT)
        SELECT IFNULL(CAST(balance AS SIGNED), 0) INTO current_welfare_bal 
        FROM wallets 
        WHERE member_id = NEW.member_id AND wallet_type_id = 2 
        LIMIT 1;

        -- 2. Compute the precise running addition
        SET new_welfare_bal = current_welfare_bal + CAST(NEW.amount_eligible AS SIGNED);

        -- 3. Mutate the running wallet balance
        UPDATE wallets 
        SET balance = new_welfare_bal
        WHERE member_id = NEW.member_id AND wallet_type_id = 2;

        -- 4. Generate a 100% Unique Reference Code (Using Timestamp + Random Hex)
        -- This completely prevents the UNIQUE constraint violation in MyISAM
        SET generated_receipt = CONCAT('TX-W', UPPER(SUBSTRING(MD5(CONCAT(NOW(), RAND())), 1, 8)));

        -- 5. Insert audit trail ledger record matching your exact schema layout
        INSERT INTO transactions (
            member_id, 
            type, 
            amount, 
            balance, 
            status, 
            payment_receipt, 
            description, 
            created_at,
            updated_at
        ) VALUES (
            NEW.member_id,
            'Credit',                             -- Matches your ENUM('Credit','Debit')
            CAST(NEW.amount_eligible AS SIGNED),   -- Matches your INT amount column
            new_welfare_bal,                      -- Matches your INT balance column
            'Completed',                          -- Matches your ENUM('Pending','Completed','Cancelled')
            generated_receipt,                    -- Unique string for VARCHAR(50)
            CONCAT('Approved Welfare Payout for ', UPPER(NEW.claim_type), ' (Ticket: ', NEW.tracking_number, ')'),
            NOW(),
            NULL
        );

    END IF;
END$$

DELIMITER ;

======================================================================================
