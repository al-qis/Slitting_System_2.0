<?php
// =============================================================
// isu_excel_parser.php
// PLACEMENT: C:\Apache24\htdocs\slitting_system\isu_excel_parser.php
//
// Pure parsing logic for Initial Stock Setup bulk Excel import.
// No DB, no file I/O here -- just string -> structured data.
// Kept separate so it can be unit-tested without a database or
// an actual uploaded file.
// =============================================================

/**
 * Parse "Item number" -> [product, width]
 *
 * Example: "SF-DS-3020-123.0" -> ["DS-3020", "123"]
 *
 * Rule: ignore "SF-" prefix. The LAST hyphen-separated segment is
 * the width (a number, possibly with decimals). Everything between
 * the prefix and that final width segment is the product code.
 *
 * "SF-DS-3020-123.0"
 *    strip "SF-"  -> "DS-3020-123.0"
 *    split on "-" -> ["DS", "3020", "123.0"]
 *    last segment "123.0" is the width -> strip trailing ".0" -> "123"
 *    remaining segments rejoined "-" -> "DS-3020" is the product
 */
function isu_parse_item_number(string $raw): array
{
    $raw = trim($raw);
    if ($raw === '') {
        throw new InvalidArgumentException("Item number is empty.");
    }

    // Strip "SF-" prefix only if present at the start (case-insensitive)
    $stripped = preg_replace('/^SF-/i', '', $raw);

    $parts = explode('-', $stripped);
    if (count($parts) < 2) {
        throw new InvalidArgumentException("Item number \"$raw\" does not contain a width segment.");
    }

    $widthRaw = array_pop($parts); // last segment = width
    $product  = implode('-', $parts);

    if ($product === '' || !is_numeric($widthRaw)) {
        throw new InvalidArgumentException("Item number \"$raw\" could not be split into product + width.");
    }

    $width = isu_strip_trailing_decimal_zeros($widthRaw);

    return [$product, $width];
}

/**
 * Removes trailing ".0", ".00", etc. from a numeric string.
 * "123.0" -> "123"   |   "123.50" -> "123.5"   |   "123" -> "123"
 */
function isu_strip_trailing_decimal_zeros(string $numStr): string
{
    if (strpos($numStr, '.') === false) {
        return $numStr; // no decimal point at all
    }
    $numStr = rtrim($numStr, '0');   // "123.50" -> "123.5", "123.00" -> "123."
    $numStr = rtrim($numStr, '.');   // "123."   -> "123"
    return $numStr;
}

/**
 * Parse "Batch number" -> [lot_no, coil_no, roll_no]
 *
 * Example: "825Y13 CG-02 R7" -> ["825Y13", "CG-2", "R7"]
 *
 * Rule: split on whitespace into exactly 3 parts.
 *   - lot_no:  1st part, unchanged.
 *   - coil_no: 2nd part, with leading zeros stripped from the
 *              numeric segment AFTER THE LAST HYPHEN only.
 *              e.g. "CG-02" -> "CG-2", "AB-001-02" -> "AB-001-2"
 *              (only the final segment's leading zeros are
 *              stripped; earlier segments are left untouched,
 *              since those may be meaningful codes, not numbers
 *              with incidental zero-padding).
 *   - roll_no: 3rd part, unchanged.
 */
function isu_parse_batch_number(string $raw): array
{
    $raw = trim($raw);
    if ($raw === '') {
        throw new InvalidArgumentException("Batch number is empty.");
    }

    $parts = preg_split('/\s+/', $raw);
    if (count($parts) !== 3) {
        throw new InvalidArgumentException(
            "Batch number \"$raw\" must have exactly 3 space-separated parts (Lot, Coil, Roll), got " . count($parts) . "."
        );
    }

    [$lot_no, $coil_no_raw, $roll_no] = $parts;

    $coil_no = isu_strip_leading_zeros_last_segment($coil_no_raw);

    return [$lot_no, $coil_no, $roll_no];
}

/**
 * Strips leading zeros from the numeric run at the START of the
 * LAST hyphen-separated segment only.
 *
 * "CG-02"     -> "CG-2"
 * "CG-002"    -> "CG-2"
 * "AB-001-02" -> "AB-001-2"   (only the final segment touched)
 * "CG02"      -> "CG02"       (no hyphen -> nothing stripped;
 *                              see note below)
 * "CG-0"      -> "CG-0"       (would become "" -- kept as "0"
 *                              so the value is never emptied out)
 *
 * NOTE: if your real data sometimes has NO hyphen at all (e.g.
 * "CG02" meaning the same thing as "CG-02"), tell me and I'll
 * extend this to detect a trailing numeric run even without a
 * hyphen -- left out for now since your example always has one.
 */
function isu_strip_leading_zeros_last_segment(string $value): string
{
    $lastHyphen = strrpos($value, '-');
    if ($lastHyphen === false) {
        return $value; // no hyphen -- nothing to touch, per current spec
    }

    $head = substr($value, 0, $lastHyphen + 1); // includes the hyphen, e.g. "CG-"
    $tail = substr($value, $lastHyphen + 1);     // e.g. "02"

    if (!preg_match('/^0*(\d+)$/', $tail, $m)) {
        return $value; // tail isn't a plain leading-zero number -- leave untouched
    }

    $strippedTail = $m[1]; // e.g. "02" -> "2"
    return $head . $strippedTail;
}

/**
 * Parse "Available physical" -> float for length / actual_length.
 *
 * Example: "539.00" -> 539.00 (float)
 */
function isu_parse_available_physical(string $raw): float
{
    $raw = trim($raw);
    if ($raw === '' || !is_numeric($raw)) {
        throw new InvalidArgumentException("Available physical \"$raw\" is not a valid number.");
    }
    return (float)$raw;
}

/**
 * Parses one full Excel row into the target structure.
 * Throws InvalidArgumentException with a descriptive message on
 * any failure -- callers should catch this PER ROW so one bad
 * row doesn't abort the whole import.
 *
 * @param array $row  ['item_number' => ..., 'batch_number' => ..., 'available_physical' => ...]
 * @return array       ['product','width','lot_no','coil_no','roll_no','actual_length','length']
 */
function isu_parse_row(array $row): array
{
    $itemNumber  = trim((string)($row['item_number']        ?? ''));
    $batchNumber = trim((string)($row['batch_number']        ?? ''));
    $available   = trim((string)($row['available_physical']  ?? ''));

    if ($itemNumber === '' && $batchNumber === '' && $available === '') {
        throw new InvalidArgumentException("Row is completely empty.");
    }
    if ($itemNumber === '')  throw new InvalidArgumentException("Item number is missing.");
    if ($batchNumber === '') throw new InvalidArgumentException("Batch number is missing.");
    if ($available === '')   throw new InvalidArgumentException("Available physical is missing.");

    [$product, $width]            = isu_parse_item_number($itemNumber);
    [$lot_no, $coil_no, $roll_no] = isu_parse_batch_number($batchNumber);
    $actual_length                 = isu_parse_available_physical($available);

    return [
        'product'       => $product,
        'width'         => $width,
        'lot_no'        => $lot_no,
        'coil_no'       => $coil_no,
        'roll_no'       => $roll_no,
        'actual_length' => $actual_length,
        'length'        => $actual_length, // mirrors actual_length, same rule as the manual form
    ];
}
