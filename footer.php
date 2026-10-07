</div> </div> </div> <footer class="text-center py-3 text-muted border-top bg-white">
    <small>&copy; <?php echo date('Y'); ?> Nichias MK Slitting System</small>
</footer>
<script>
(function() {
    let globalStockCodeDebounce = null;
    const basePrefix = "<?php echo isset($pathPrefix) ? $pathPrefix : (isset($prefix) ? $prefix : ''); ?>";

    window.handleStockCodeInput = function(inputEl) {
        if (!inputEl) return;
        const rawVal = inputEl.value.trim();
        
        const isExact4Digits = /^\d{4}$/.test(rawVal);
        const isPrefixHyphen = /^\d{4}-$/.test(rawVal);
        
        let targetPrefix = null;
        if (isExact4Digits) {
            targetPrefix = rawVal;
        } else if (isPrefixHyphen) {
            targetPrefix = rawVal.substring(0, 4);
        } else {
            const match = rawVal.match(/^(\d{4})-(.*)$/);
            if (match) {
                const prefixPart = match[1];
                if (inputEl.dataset.lastAutoPrefix && inputEl.dataset.lastAutoPrefix !== prefixPart) {
                    targetPrefix = prefixPart;
                }
            }
        }

        if (targetPrefix) {
            clearTimeout(globalStockCodeDebounce);
            globalStockCodeDebounce = setTimeout(() => {
                fetch(basePrefix + 'stock_code_ajax.php?prefix=' + encodeURIComponent(targetPrefix))
                    .then(res => res.json())
                    .then(data => {
                        if (data && data.success && data.stock_code) {
                            inputEl.value = data.stock_code;
                            inputEl.dataset.lastAutoPrefix = targetPrefix;
                            if (typeof inputEl.setSelectionRange === 'function') {
                                const len = data.stock_code.length;
                                inputEl.setSelectionRange(len, len);
                            }
                        }
                    })
                    .catch(err => console.error('Error fetching auto stock code count:', err));
            }, 150);
        }
    };

    window.handleStockCodeSplitInput = function(prefixInput) {
        if (!prefixInput) return;
        const prefix = prefixInput.value.trim();
        const seqInput = document.getElementById('edit_stock_code_seq');
        
        window.updateFullStockCodeHidden();

        if (/^\d{4}$/.test(prefix)) {
            clearTimeout(globalStockCodeDebounce);
            globalStockCodeDebounce = setTimeout(() => {
                fetch(basePrefix + 'stock_code_ajax.php?prefix=' + encodeURIComponent(prefix))
                    .then(res => res.json())
                    .then(data => {
                        if (data && data.success && data.seq) {
                            if (seqInput) {
                                seqInput.value = data.seq;
                            }
                            window.updateFullStockCodeHidden();
                        }
                    })
                    .catch(err => console.error('Error fetching stock code seq:', err));
            }, 150);
        }
    };

    window.updateFullStockCodeHidden = function() {
        const prefixInput = document.getElementById('edit_stock_code_prefix');
        const seqInput = document.getElementById('edit_stock_code_seq');
        const fullInput = document.getElementById('edit_stock_code_full');

        if (prefixInput && seqInput && fullInput) {
            const p = prefixInput.value.trim();
            const s = seqInput.value.trim();
            fullInput.value = (p !== '' || s !== '') ? (p + '-' + s) : '';
        }
    };

    document.addEventListener('input', function(e) {
        if (e.target && (e.target.name === 'stock_code' || e.target.classList.contains('modal-row-stock-code'))) {
            window.handleStockCodeInput(e.target);
        }
    });
})();
</script>
</body>
</html>