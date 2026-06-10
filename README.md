TRIGGERS USED FOR JUAKALI CBO
===============================
DELIMITER //
DROP TRIGGER IF EXISTS `after_loan_request_approval` //
CREATE TRIGGER `after_loan_request_approval`
AFTER UPDATE ON `loan_requests`
FOR EACH ROW
BEGIN
IF NEW.status = 'approved' AND (OLD.status IS NULL OR OLD.status <> 'approved') THEN
UPDATE wallets w
INNER JOIN wallet_types wt ON w.wallet_type_id = wt.id
SET w.balance = w.balance + NEW.amount,
w.updated_at = NOW()
WHERE w.member_id = NEW.member_id 
AND LOWER(wt.name) = 'loan';
ELSEIF NEW.status = 'cleared' AND (OLD.status IS NULL OR OLD.status <> 'cleared') THEN
UPDATE wallets w
INNER JOIN wallet_types wt ON w.wallet_type_id = wt.id
SET w.balance = 0.00,
w.updated_at = NOW()
WHERE w.member_id = NEW.member_id 
AND LOWER(wt.name) = 'loan';
END IF;
END//
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
