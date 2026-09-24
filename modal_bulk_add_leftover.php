<!-- ================================================================
     MODAL: BULK ADD LEFTOVER MOTHER COILS FROM EXCEL
     Can be included in mother_coil.php and raw_material.php
================================================================ -->
<div class="modal fade" id="bulkAddLeftoverModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-scrollable">
    <div class="modal-content border-0 shadow">
      <div class="modal-header bg-warning text-dark py-3">
        <h5 class="modal-title fw-bold">
          <i class="bi bi-arrow-return-right me-2"></i>Bulk Add Leftover Mother Coils from Excel
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>

      <div class="modal-body p-4">
        <!-- Explanatory note -->
        <div class="alert alert-light border border-warning-subtle d-flex align-items-start gap-3 mb-3 p-3">
          <i class="bi bi-info-circle-fill text-warning fs-4 mt-1"></i>
          <div class="small text-secondary">
            <strong class="text-dark">How this works:</strong>
            Paste rows copied from Excel containing leftover/balance coils. Once saved, these will be registered as active balance inventory (<code>status = 'IN'</code>, <code>source_type = 'slitting_cut_into_2'</code>), immediately available on the <strong>Raw Material</strong> board for slitting operators, and ready for leftover QR sticker printing.
          </div>
        </div>

        <!-- Excel paste textarea -->
        <div class="mb-3">
          <div class="d-flex justify-content-between align-items-center mb-1">
            <label class="form-label fw-semibold mb-0">
              <i class="bi bi-clipboard-plus text-primary me-1"></i> Paste Excel Rows Here (tab-separated, without headers)
            </label>
            <span class="badge bg-light text-dark border small">Supports 10-Col Standard &amp; 4–6 Col Direct formats</span>
          </div>
          <textarea id="leftover_paste_zone" class="form-control font-monospace" rows="5"
                    placeholder="Example Format A (10-col factory standard):&#10;RS-3825   [tab]   1250   [tab]   826711 CH-2   [tab]   [tab]   350   ...   SUS304&#10;&#10;Example Format B (Direct columns):&#10;826711   [tab]   CH-2   [tab]   RS-3825   [tab]   SUS304   [tab]   1250   [tab]   350"></textarea>
          <div class="form-text small mt-1">
            Tip: Select rows in Excel, press <kbd>Ctrl+C</kbd>, click inside the box above, and press <kbd>Ctrl+V</kbd>.
          </div>
        </div>

        <div class="d-flex gap-2 mb-3">
          <button type="button" class="btn btn-primary btn-sm px-3 shadow-sm" id="leftover_parse_btn">
            <i class="bi bi-list-check me-1"></i> Parse Rows
          </button>
          <button type="button" class="btn btn-outline-secondary btn-sm" id="leftover_clear_btn">
            <i class="bi bi-x-circle me-1"></i> Clear
          </button>
          <button type="button" class="btn btn-outline-success btn-sm ms-auto" id="leftover_add_manual_row_btn">
            <i class="bi bi-plus-lg me-1"></i> Add Blank Row
          </button>
        </div>

        <div class="alert alert-warning d-none py-2 px-3 small" id="leftover_parse_warning"></div>
        <div class="alert alert-success d-none py-2 px-3 small fw-semibold" id="leftover_result_msg"></div>

        <!-- Preview Table -->
        <div class="table-responsive rounded border shadow-sm">
          <table class="table table-sm table-hover table-bordered align-middle text-center mb-0" id="leftover_preview_table">
            <thead class="table-dark small">
              <tr>
                <th style="width:40px;">#</th>
                <th style="width:130px;">Lot No *</th>
                <th style="width:120px;">Coil No *</th>
                <th style="min-width:160px;">Product *</th>
                <th style="width:110px;">Grade</th>
                <th style="width:110px;">Width (mm) *</th>
                <th style="width:130px;">Leftover Length (m) *</th>
                <th style="width:45px;"></th>
              </tr>
            </thead>
            <tbody id="leftover_preview_tbody" class="small font-monospace">
              <tr>
                <td colspan="8" class="text-center py-4 text-muted font-sans-serif">
                  <i class="bi bi-clipboard-data text-secondary fs-3 d-block mb-1"></i>
                  Paste Excel rows above and click <strong>Parse Rows</strong> to preview data.
                </td>
              </tr>
            </tbody>
          </table>
        </div>

      </div>

      <div class="modal-footer bg-light py-2">
        <span class="text-muted small me-auto" id="leftover_row_count_label">0 leftover coils parsed</span>
        <button type="button" class="btn btn-secondary btn-sm px-3" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-success btn-sm px-4 fw-semibold shadow-sm" id="leftover_save_btn" disabled>
          <i class="bi bi-check2-circle me-1"></i> Save All Leftovers to Stock
        </button>
      </div>
    </div>
  </div>
</div>

<script>
(function() {
    let leftoverRows = [];

    const pasteZone      = document.getElementById('leftover_paste_zone');
    const parseBtn       = document.getElementById('leftover_parse_btn');
    const clearBtn       = document.getElementById('leftover_clear_btn');
    const addManualBtn   = document.getElementById('leftover_add_manual_row_btn');
    const saveBtn        = document.getElementById('leftover_save_btn');
    const tbody          = document.getElementById('leftover_preview_tbody');
    const warningBox     = document.getElementById('leftover_parse_warning');
    const resultBox      = document.getElementById('leftover_result_msg');
    const countLabel     = document.getElementById('leftover_row_count_label');

    function escapeHtml(str) {
        if (str === null || str === undefined) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function showWarning(msg) {
        if (!warningBox) return;
        if (msg) {
            warningBox.textContent = msg;
            warningBox.classList.remove('d-none');
        } else {
            warningBox.classList.add('d-none');
        }
    }

    /* ── Smart Parse Logic ───────────────────────────────────── */
    function parseLeftoverClipboard(raw) {
        const lines = raw.split(/\r?\n/).map(l => l.trim()).filter(l => l !== '');
        if (lines.length === 0) {
            showWarning('Please paste at least one row before parsing.');
            return;
        }

        const parsed   = [];
        const badLines = [];

        lines.forEach((line, idx) => {
            const cols = line.split('\t').map(c => c.trim());
            if (cols.length === 0 || (cols.length === 1 && cols[0] === '')) return;

            let lot = '', coil = '', product = '', grade = '', width = '', length = '', pastedProduct = '';

            if (cols.length >= 10) {
                // Standard 10-column layout:
                // 0:Product | 1:spacer | 2:Width | 3:Lot+Coil | 4:spacer | 5:Length | 6..8:spacers | 9:Grade
                pastedProduct = cols[0];
                width         = cols[2];
                const lotCoil = cols[3];
                length        = cols[5];
                grade         = cols[9] || '-';

                const parts = lotCoil.split(' ').filter(Boolean);
                if (parts.length >= 2) {
                    lot  = parts[0];
                    coil = parts.slice(1).join(' ');
                } else if (parts.length === 1) {
                    lot  = parts[0];
                    coil = '';
                }
            } else if (cols.length >= 6) {
                if (cols[0].includes('-') && !isNaN(parseFloat(cols[4])) && !isNaN(parseFloat(cols[5]))) {
                    pastedProduct = cols[0];
                    lot           = cols[1];
                    coil          = cols[2];
                    grade         = cols[3] || '-';
                    width         = cols[4];
                    length        = cols[5];
                } else {
                    lot           = cols[0];
                    coil          = cols[1];
                    pastedProduct = cols[2];
                    grade         = cols[3] || '-';
                    width         = cols[4];
                    length        = cols[5];
                }
            } else if (cols.length === 5) {
                lot    = cols[0];
                coil   = cols[1];
                grade  = cols[2] || '-';
                width  = cols[3];
                length = cols[4];
            } else if (cols.length === 4) {
                lot    = cols[0];
                coil   = cols[1];
                grade  = '-';
                width  = cols[2];
                length = cols[3];
            } else if (cols.length === 3) {
                const parts = cols[0].split(' ').filter(Boolean);
                lot    = parts[0] || '';
                coil   = parts.slice(1).join(' ') || '';
                grade  = '-';
                width  = cols[1];
                length = cols[2];
            }

            const wNum = parseFloat(width);
            const lNum = parseFloat(length);

            if (!lot || !coil || isNaN(wNum) || isNaN(lNum)) {
                badLines.push(idx + 1);
                return;
            }

            parsed.push({
                lot_no:         lot,
                coil_no:        coil,
                grade:          grade || '-',
                width:          width,
                length:         length,
                product:        '',
                pastedProduct:  pastedProduct,
                productOptions: [],
                productStatus:  'pending'
            });
        });

        if (badLines.length > 0) {
            showWarning('Note: ' + badLines.length + ' line(s) (Line ' + badLines.join(', ') + ') had incomplete columns and were skipped. Valid rows are previewed below.');
        } else {
            showWarning('');
        }

        leftoverRows = parsed;
        renderLeftoverTable();
        leftoverRows.forEach((row, i) => lookupLeftoverProduct(i));
    }

    function renderProductCell(row, i) {
        if (row.productStatus === 'pending') {
            return '<span class="text-muted small"><i class="spinner-border spinner-border-sm me-1"></i>Resolving…</span>';
        }
        if (row.productStatus === 'single') {
            return '<input type="text" class="form-control form-control-sm text-success fw-bold leftover-product" value="' + escapeHtml(row.product) + '">';
        }
        if (row.productStatus === 'multiple') {
            const options = row.productOptions.map(p =>
                '<option value="' + escapeHtml(p) + '" ' + (p === row.product ? 'selected' : '') + '>' + escapeHtml(p) + '</option>'
            ).join('');
            return '<select class="form-select form-select-sm leftover-product-select border-warning"><option value="">-- Choose Product --</option>' + options + '</select>';
        }
        return '<input type="text" class="form-control form-control-sm leftover-product" placeholder="Product code" value="' + escapeHtml(row.product) + '">';
    }

    function renderLeftoverTable() {
        tbody.innerHTML = '';
        const count = leftoverRows.length;
        countLabel.textContent = count + ' leftover coil' + (count !== 1 ? 's' : '') + ' parsed';
        saveBtn.disabled = (count === 0);

        if (count === 0) {
            tbody.innerHTML = '<tr><td colspan="8" class="text-center py-4 text-muted font-sans-serif"><i class="bi bi-clipboard-data text-secondary fs-3 d-block mb-1"></i>Paste Excel rows above and click <strong>Parse Rows</strong> to preview data.</td></tr>';
            return;
        }

        leftoverRows.forEach((row, i) => {
            const tr = document.createElement('tr');
            tr.dataset.index = i;
            tr.innerHTML = `
                <td class="text-muted">${i + 1}</td>
                <td><input type="text" class="form-control form-control-sm fw-bold leftover-lot" value="${escapeHtml(row.lot_no)}"></td>
                <td><input type="text" class="form-control form-control-sm leftover-coil" value="${escapeHtml(row.coil_no)}"></td>
                <td class="leftover-product-cell">${renderProductCell(row, i)}</td>
                <td><input type="text" class="form-control form-control-sm leftover-grade" value="${escapeHtml(row.grade)}"></td>
                <td><input type="number" step="0.1" class="form-control form-control-sm text-end leftover-width" value="${escapeHtml(row.width)}"></td>
                <td><input type="number" step="0.1" class="form-control form-control-sm text-end text-success fw-bold leftover-length" value="${escapeHtml(row.length)}"></td>
                <td><button type="button" class="btn btn-outline-danger btn-sm px-2 leftover-remove" title="Remove row">&times;</button></td>
            `;
            tbody.appendChild(tr);
        });

        tbody.querySelectorAll('tr').forEach(tr => {
            const i = parseInt(tr.dataset.index, 10);
            tr.querySelector('.leftover-lot').addEventListener('input', e => leftoverRows[i].lot_no = e.target.value.trim());
            tr.querySelector('.leftover-coil').addEventListener('change', e => {
                leftoverRows[i].coil_no       = e.target.value.trim();
                leftoverRows[i].product       = '';
                leftoverRows[i].productStatus = 'pending';
                lookupLeftoverProduct(i);
            });
            tr.querySelector('.leftover-grade').addEventListener('input', e => leftoverRows[i].grade = e.target.value.trim());
            tr.querySelector('.leftover-width').addEventListener('input', e => leftoverRows[i].width = e.target.value.trim());
            tr.querySelector('.leftover-length').addEventListener('input', e => leftoverRows[i].length = e.target.value.trim());

            const prodInput = tr.querySelector('.leftover-product');
            if (prodInput) {
                prodInput.addEventListener('input', e => leftoverRows[i].product = e.target.value.trim());
            }
            const prodSelect = tr.querySelector('.leftover-product-select');
            if (prodSelect) {
                prodSelect.addEventListener('change', e => leftoverRows[i].product = e.target.value.trim());
            }

            tr.querySelector('.leftover-remove').addEventListener('click', () => {
                leftoverRows.splice(i, 1);
                renderLeftoverTable();
            });
        });
    }

    async function lookupLeftoverProduct(i) {
        const row = leftoverRows[i];
        if (!row || !row.coil_no) return;

        if (row.pastedProduct) {
            try {
                const vRes  = await fetch('mother_coil.php?ajax=validate_product&code=' + encodeURIComponent(row.pastedProduct));
                const vData = await vRes.json();
                if (vData.ok && vData.found) {
                    row.productStatus = 'single';
                    row.product       = vData.product;
                    updateRowProductCell(i);
                    return;
                }
            } catch (e) {}
        }

        try {
            const res  = await fetch('mother_coil.php?ajax=get_product&coil=' + encodeURIComponent(row.coil_no));
            const data = await res.json();
            if (!data.ok || !data.products || data.products.length === 0) {
                row.productStatus = 'none';
                row.product       = row.pastedProduct || '';
            } else if (data.products.length === 1) {
                row.productStatus = 'single';
                row.product       = data.products[0];
            } else {
                row.productStatus  = 'multiple';
                row.productOptions = data.products;
                row.product        = row.pastedProduct && data.products.includes(row.pastedProduct) ? row.pastedProduct : '';
            }
        } catch (e) {
            row.productStatus = 'none';
            row.product       = row.pastedProduct || '';
        }

        updateRowProductCell(i);
    }

    function updateRowProductCell(i) {
        const row = leftoverRows[i];
        const tr = tbody.querySelector(`tr[data-index="${i}"]`);
        if (!tr) return;
        const cell = tr.querySelector('.leftover-product-cell');
        if (!cell) return;
        cell.innerHTML = renderProductCell(row, i);

        const prodInput = cell.querySelector('.leftover-product');
        if (prodInput) {
            prodInput.addEventListener('input', e => row.product = e.target.value.trim());
        }
        const prodSelect = cell.querySelector('.leftover-product-select');
        if (prodSelect) {
            prodSelect.addEventListener('change', e => row.product = e.target.value.trim());
        }
    }

    parseBtn.addEventListener('click', () => {
        parseLeftoverClipboard(pasteZone.value);
    });

    clearBtn.addEventListener('click', () => {
        pasteZone.value = '';
        leftoverRows = [];
        showWarning('');
        resultBox.classList.add('d-none');
        renderLeftoverTable();
    });

    addManualBtn.addEventListener('click', () => {
        leftoverRows.push({
            lot_no:         '',
            coil_no:        '',
            grade:          '-',
            width:          '',
            length:         '',
            product:        '',
            pastedProduct:  '',
            productOptions: [],
            productStatus:  'manual'
        });
        showWarning('');
        renderLeftoverTable();
    });

    saveBtn.addEventListener('click', async () => {
        const invalidRows = [];
        const validRows = leftoverRows.filter((r, idx) => {
            const hasLot   = r.lot_no && r.lot_no.trim() !== '';
            const hasCoil  = r.coil_no && r.coil_no.trim() !== '';
            const hasProd  = r.product && r.product.trim() !== '';
            const hasW     = parseFloat(r.width) > 0;
            const hasL     = parseFloat(r.length) > 0;

            if (!hasLot || !hasCoil || !hasProd || !hasW || !hasL) {
                invalidRows.push(idx + 1);
                return false;
            }
            return true;
        });

        if (validRows.length === 0) {
            showWarning('Cannot save: All rows have missing fields. Please ensure Lot No, Coil No, Product, Width, and Leftover Length are filled.');
            return;
        }

        if (invalidRows.length > 0) {
            if (!confirm('Warning: Row(s) ' + invalidRows.join(', ') + ' are incomplete and will be skipped. Do you wish to continue saving the ' + validRows.length + ' valid row(s)?')) {
                return;
            }
        }

        saveBtn.disabled = true;
        saveBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Saving to Inventory…';

        try {
            const formData = new FormData();
            formData.append('action', 'bulk_add_leftover');
            formData.append('rows_json', JSON.stringify(validRows));

            const res  = await fetch('mother_coil.php', { method: 'POST', body: formData });
            const data = await res.json();

            if (data.ok) {
                let msg = '✅ Successfully registered ' + data.inserted + ' leftover coil(s) into stock!';
                if (data.skipped && data.skipped.length > 0) {
                    msg += ' (' + data.skipped.length + ' skipped: ' + data.skipped.map(s => 'Row ' + s.row + ': ' + s.reason).join('; ') + ')';
                }
                resultBox.textContent = msg;
                resultBox.classList.remove('d-none');
                showWarning('');

                if (data.inserted > 0) {
                    setTimeout(() => window.location.reload(), 1200);
                }
            } else {
                showWarning(data.msg || 'Failed to save leftover coils. Please check your data.');
            }
        } catch (err) {
            showWarning('Network or server error occurred while saving.');
        } finally {
            saveBtn.disabled = false;
            saveBtn.innerHTML = '<i class="bi bi-check2-circle me-1"></i> Save All Leftovers to Stock';
        }
    });

    const modalEl = document.getElementById('bulkAddLeftoverModal');
    if (modalEl) {
        modalEl.addEventListener('show.bs.modal', function() {
            pasteZone.value = '';
            leftoverRows = [];
            showWarning('');
            resultBox.classList.add('d-none');
            renderLeftoverTable();
        });
    }
})();
</script>
