-- Add hold_draft_customer and hold_draft_sos to draft_coils
ALTER TABLE `draft_coils`
  ADD COLUMN `hold_draft_customer` VARCHAR(150) NULL AFTER `hold_note`,
  ADD COLUMN `hold_draft_sos`      VARCHAR(100) NULL AFTER `hold_draft_customer`;
