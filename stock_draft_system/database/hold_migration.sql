-- On Hold Feature Migration
-- Run this on existing databases to add hold columns to draft_coils

ALTER TABLE `draft_coils`
  ADD COLUMN `is_on_hold`   TINYINT(1)   NOT NULL DEFAULT 0 AFTER `status`,
  ADD COLUMN `hold_note`    VARCHAR(255) NULL      AFTER `is_on_hold`,
  ADD COLUMN `hold_set_by`  VARCHAR(150) NULL      AFTER `hold_note`,
  ADD COLUMN `hold_set_at`  DATETIME     NULL      AFTER `hold_set_by`;
