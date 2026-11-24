-- Sequential public order references (CL-001, CL-002, …).
-- Historical reference_code values are never rewritten.
-- Reverse: DROP TABLE order_ref_seq;

CREATE TABLE IF NOT EXISTS order_ref_seq (
    id       TINYINT UNSIGNED NOT NULL PRIMARY KEY,
    next_val INT UNSIGNED     NOT NULL
) ENGINE=InnoDB;

-- Seed only when empty. Start at 1 so new orders begin at CL-001,
-- independent of legacy formats (e.g. CL0000000001 or CL-79EA4A84).
INSERT INTO order_ref_seq (id, next_val)
SELECT 1, 1
WHERE NOT EXISTS (SELECT 1 FROM order_ref_seq WHERE id = 1);

-- If sequential CL-### refs already exist, advance past the highest.
UPDATE order_ref_seq seq
JOIN (
    SELECT COALESCE(MAX(CAST(SUBSTRING(reference_code, 4) AS UNSIGNED)), 0) + 1 AS next_val
    FROM orders
    WHERE reference_code REGEXP '^CL-[0-9]+$'
) src
SET seq.next_val = GREATEST(seq.next_val, src.next_val)
WHERE seq.id = 1;
