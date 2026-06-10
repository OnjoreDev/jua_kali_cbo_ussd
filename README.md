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