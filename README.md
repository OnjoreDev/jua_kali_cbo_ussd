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
    -- Define temporary variable storage for tracking current running balances
    DECLARE current_welfare_bal DECIMAL(10,2) DEFAULT 0.00;
    DECLARE new_welfare_bal DECIMAL(10,2) DEFAULT 0.00;
    DECLARE generated_receipt VARCHAR(20);
    -- Condition: Trigger matches exclusively when status changes from anything else to 'approved'
    IF NEW.status = 'approved' AND (OLD.status IS NULL OR OLD.status != 'approved') AND NEW.amount_eligible > 0 THEN
        -- 1. Fetch the member's active pre-transaction Welfare Wallet (Type ID: 2) balance string
        SELECT IFNULL(balance, 0.00) INTO current_welfare_bal 
        FROM wallets 
        WHERE member_id = NEW.member_id AND wallet_type_id = 2 
        LIMIT 1;
        -- 2. Compute the precise post-transaction snapshot balance value
        SET new_welfare_bal = current_welfare_bal + NEW.amount_eligible;
        -- 3. Mutate the member's ledger record balance inside the wallets table directly
        UPDATE wallets 
        SET balance = new_welfare_bal
        WHERE member_id = NEW.member_id AND wallet_type_id = 2;
        -- 4. Formulate a pristine, unique payment receipt voucher format matching your standards
        SET generated_receipt = CONCAT('WEL-', UPPER(SUBSTRING(MD5(RAND()), 1, 8)));
        -- 5. Append a standardized transactional record entry directly to the transactions audit ledger
        INSERT INTO transactions (
            member_id, 
            type, 
            amount, 
            balance, 
            status, 
            payment_receipt, 
            description, 
            created_at
        ) VALUES (
            NEW.member_id,
            'Credit',
            NEW.amount_eligible,
            new_welfare_bal,
            'Completed',
            generated_receipt,
            CONCAT('Approved Welfare Payout for ', UPPER(NEW.claim_type), ' (Ticket: ', NEW.tracking_number, ')'),
            NOW()
        );

    END IF;
END$$
DELIMITER ;