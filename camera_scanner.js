/**
 * camera_scanner.js  v4 — INSTANT CLOSE
 * ============================================================
 * ROOT FIX: We no longer call scanner.stop() on close.
 * Instead we REMOVE the #cam-reader div from the DOM entirely.
 * Removing the element kills the video stream immediately —
 * the browser releases the camera hardware right away.
 * Next open creates a fresh #cam-reader div and new scanner.
 * Result: close is instant, no async waiting at all.
 * ============================================================
 */

(function () {

  /* ── Load html5-qrcode from CDN ───────────────────────── */
  function loadLib(callback) {
    if (typeof Html5Qrcode !== 'undefined') { callback(); return; }
    var s = document.createElement('script');
    s.src = 'https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js';
    s.onload = callback;
    s.onerror = function () {
      alert('Cannot load QR scanner library. Check internet connection.');
    };
    document.head.appendChild(s);
  }

  /* ── Styles ────────────────────────────────────────────── */
  function injectStyles() {
    if (document.getElementById('cam-scanner-style')) return;
    var css = `
      #cam-scan-btn {
        position: fixed;
        bottom: 28px;
        right: 28px;
        z-index: 9000;
        width: 68px;
        height: 68px;
        border-radius: 50%;
        background: linear-gradient(135deg, #0f2744 0%, #1e5fa5 100%);
        color: #fff;
        border: none;
        cursor: pointer;
        box-shadow: 0 6px 24px rgba(15,39,68,.45);
        display: flex;
        align-items: center;
        justify-content: center;
        flex-direction: column;
        gap: 3px;
        font-family: system-ui, sans-serif;
        touch-action: manipulation;
        -webkit-tap-highlight-color: transparent;
      }
      #cam-scan-btn:active { transform: scale(0.93); }
      #cam-scan-btn svg { width:28px; height:28px; pointer-events:none; }
      #cam-scan-btn .btn-label {
        font-size:9px; font-weight:800;
        letter-spacing:.8px; pointer-events:none;
      }
      #cam-scan-btn::before {
        content:''; position:absolute; inset:-6px;
        border-radius:50%; border:2px solid rgba(30,95,165,.4);
        animation: camPulse 2s ease-out infinite; pointer-events:none;
      }
      @keyframes camPulse {
        0%  { transform:scale(1);   opacity:.8; }
        70% { transform:scale(1.3); opacity:0;  }
        100%{ transform:scale(1.3); opacity:0;  }
      }

      /* Overlay */
      #cam-overlay {
        display: none;
        position: fixed;
        inset: 0;
        z-index: 9100;
        background: rgba(0,0,0,.88);
        flex-direction: column;
        align-items: center;
        justify-content: center;
        font-family: system-ui, sans-serif;
      }
      #cam-overlay.open { display:flex; }

      /* Box */
      #cam-box {
        background: #0f1923;
        border-radius: 20px;
        padding: 24px;
        width: min(420px, 94vw);
        box-shadow: 0 24px 80px rgba(0,0,0,.6);
        border: 1px solid rgba(255,255,255,.08);
      }

      /* Header */
      #cam-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        margin-bottom: 18px;
        position: relative;
        z-index: 99999;
      }
      #cam-title {
        color:#fff; font-size:15px; font-weight:800;
        display:flex; align-items:center; gap:8px; pointer-events:none;
      }
      #cam-title svg { width:20px; height:20px; color:#60a5fa; }

      /* Close button — RED, large, always on top */
      #cam-close-btn {
        position: relative;
        z-index: 99999;
        background: #e11d48;
        border: none;
        color: #fff;
        width: 48px;
        height: 48px;
        border-radius: 10px;
        cursor: pointer;
        font-size: 22px;
        font-weight: 900;
        display: flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
        touch-action: manipulation;
        -webkit-tap-highlight-color: rgba(225,29,72,.3);
        user-select: none;
        -webkit-user-select: none;
      }
      #cam-close-btn:active { background: #9f1239; transform: scale(0.93); }

      /* Viewfinder */
      #cam-reader-wrap {
        position: relative;
        border-radius: 12px;
        overflow: hidden;
        background: #111;
        min-height: 260px;
      }
      /* This div gets destroyed/recreated on each open */
      #cam-reader { width:100%; }
      #cam-reader video {
        border-radius: 0 !important;
        border: none !important;
        display: block !important;
      }
      #cam-reader img { display:none !important; }

      /* Corner brackets */
      .cam-bracket {
        position:absolute; width:32px; height:32px;
        border-color:#60a5fa; border-style:solid; border-width:0;
        opacity:.9; pointer-events:none; z-index:5;
      }
      .cam-bracket.tl{top:16px;left:16px;border-top-width:3px;border-left-width:3px;border-radius:4px 0 0 0;}
      .cam-bracket.tr{top:16px;right:16px;border-top-width:3px;border-right-width:3px;border-radius:0 4px 0 0;}
      .cam-bracket.bl{bottom:16px;left:16px;border-bottom-width:3px;border-left-width:3px;border-radius:0 0 0 4px;}
      .cam-bracket.br{bottom:16px;right:16px;border-bottom-width:3px;border-right-width:3px;border-radius:0 0 4px 0;}

      /* Scan line */
      #cam-scan-line {
        position:absolute; left:16px; right:16px; height:2px;
        background:linear-gradient(90deg,transparent,#60a5fa,#93c5fd,#60a5fa,transparent);
        border-radius:2px; top:16px; pointer-events:none; z-index:6;
        animation: camScanLine 2.5s ease-in-out infinite;
      }
      @keyframes camScanLine {
        0%  { top:16px; }
        50% { top:calc(100% - 18px); }
        100%{ top:16px; }
      }

      /* Status */
      #cam-status {
        margin-top:14px; text-align:center; font-size:13px;
        color:#6b7280; min-height:22px;
        display:flex; align-items:center; justify-content:center; gap:8px;
        position:relative; z-index:9999;
      }
      #cam-status.cam-ready   { color:#34d399; }
      #cam-status.cam-error   { color:#f87171; }
      #cam-status.cam-success { color:#34d399; font-weight:700; }
      .cam-spinner {
        width:14px; height:14px;
        border:2px solid rgba(107,114,128,.3);
        border-top-color:#60a5fa;
        border-radius:50%;
        animation:camSpin .7s linear infinite;
        display:inline-block; flex-shrink:0;
      }
      @keyframes camSpin { to { transform:rotate(360deg); } }

      /* Permission error */
      #cam-perm-err {
        display:none; padding:24px 16px; text-align:center;
      }
      #cam-perm-err.show { display:block; }
      #cam-perm-err strong { color:#f87171; font-size:14px; }
      #cam-perm-err p { font-size:13px; margin:8px 0 0; color:#9ca3af; }

      /* Manual entry */
      #cam-manual-toggle {
        margin-top:12px; text-align:center;
        position:relative; z-index:9999;
      }
      #cam-manual-toggle-btn {
        background:none; border:none; color:#60a5fa;
        font-size:12px; cursor:pointer; text-decoration:underline;
        touch-action:manipulation;
      }
      #cam-manual-row {
        margin-top:8px; display:none; gap:8px;
        position:relative; z-index:9999;
      }
      #cam-manual-row.show { display:flex; }
      #cam-manual-input {
        flex:1; background:rgba(255,255,255,.07);
        border:1px solid rgba(255,255,255,.15); border-radius:8px;
        color:#fff; padding:9px 12px; font-size:13px; outline:none;
      }
      #cam-manual-input::placeholder { color:#6b7280; }
      #cam-manual-input:focus { border-color:#60a5fa; }
      #cam-manual-submit {
        background:#2563eb; border:none; color:#fff;
        border-radius:8px; padding:9px 16px;
        font-size:13px; font-weight:700; cursor:pointer;
        touch-action:manipulation;
      }
      #cam-manual-submit:active { background:#1d4ed8; }

      @media (prefers-reduced-motion:reduce) {
        #cam-scan-btn::before, #cam-scan-line, .cam-spinner { animation:none !important; }
      }
    `;
    var el = document.createElement('style');
    el.id  = 'cam-scanner-style';
    el.textContent = css;
    document.head.appendChild(el);
  }

  /* ── Build static overlay (no #cam-reader inside yet) ── */
  function buildOverlay() {
    if (document.getElementById('cam-overlay')) return;

    /* Floating button */
    var btn  = document.createElement('button');
    btn.id   = 'cam-scan-btn';
    btn.type = 'button';
    btn.setAttribute('aria-label', 'Open camera scanner');
    btn.innerHTML = `
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
           stroke-linecap="round" stroke-linejoin="round">
        <rect x="3" y="3" width="7" height="7" rx="1"/>
        <rect x="14" y="3" width="7" height="7" rx="1"/>
        <rect x="3" y="14" width="7" height="7" rx="1"/>
        <path d="M14 14h2v2h-2zM18 14h3M14 18h2M18 18h3v3M14 21h2"/>
      </svg>
      <span class="btn-label">SCAN</span>`;
    document.body.appendChild(btn);

    /* Overlay shell — #cam-reader is created fresh on each open */
    var ov  = document.createElement('div');
    ov.id   = 'cam-overlay';
    ov.innerHTML = `
      <div id="cam-box">
        <div id="cam-header">
          <div id="cam-title">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                 stroke-linecap="round" stroke-linejoin="round">
              <path d="M3 7V5a2 2 0 0 1 2-2h2M17 3h2a2 2 0 0 1 2 2v2
                       M21 17v2a2 2 0 0 1-2 2h-2M7 21H5a2 2 0 0 1-2-2v-2"/>
              <rect x="7" y="7" width="10" height="10" rx="1"/>
            </svg>
            Camera QR Scanner
          </div>
          <button id="cam-close-btn" type="button" aria-label="Close">✕</button>
        </div>

        <div id="cam-reader-wrap">
          <!-- #cam-reader div injected here on open, removed on close -->
          <div class="cam-bracket tl"></div>
          <div class="cam-bracket tr"></div>
          <div class="cam-bracket bl"></div>
          <div class="cam-bracket br"></div>
          <div id="cam-scan-line"></div>
          <div id="cam-perm-err">
            <svg width="40" height="40" viewBox="0 0 24 24" fill="none"
                 stroke="#f87171" stroke-width="2">
              <circle cx="12" cy="12" r="10"/>
              <path d="M4.93 4.93l14.14 14.14"/>
            </svg>
            <strong>Camera Access Denied</strong>
            <p>Allow camera in browser settings then tap SCAN again.</p>
          </div>
        </div>

        <div id="cam-status">
          <span class="cam-spinner"></span> Starting camera…
        </div>

        <div id="cam-manual-toggle">
          <button type="button" id="cam-manual-toggle-btn">⌨ Type manually instead</button>
        </div>
        <div id="cam-manual-row">
          <input id="cam-manual-input" type="text"
                 placeholder="LOT=5001;COIL=FK-1;ROLL=R1"
                 autocomplete="off" autocorrect="off" spellcheck="false">
          <button id="cam-manual-submit" type="button">Submit</button>
        </div>
      </div>`;
    document.body.appendChild(ov);
  }

  /* ── Public init ───────────────────────────────────────── */
  window.initCameraScanner = function (options) {
    options = options || {};
    var onScan = options.onScan || function (t) { console.log('Scanned:', t); };
    injectStyles();
    loadLib(function () {
      buildOverlay();
      wireEvents(onScan);
    });
  };

  /* ── Wire events ───────────────────────────────────────── */
  function wireEvents(onScan) {

    var overlay    = document.getElementById('cam-overlay');
    var scanBtn    = document.getElementById('cam-scan-btn');
    var closeBtn   = document.getElementById('cam-close-btn');
    var wrap       = document.getElementById('cam-reader-wrap');
    var statusEl   = document.getElementById('cam-status');
    var permErr    = document.getElementById('cam-perm-err');
    var manualIn   = document.getElementById('cam-manual-input');
    var manualBtn  = document.getElementById('cam-manual-submit');
    var manToggle  = document.getElementById('cam-manual-toggle-btn');
    var manRow     = document.getElementById('cam-manual-row');
    var scanLine   = document.getElementById('cam-scan-line');

    var isOpen     = false;
    var isStarting = false;
    var scanner    = null;

    /* ── destroyReader — THE CORE FIX ─────────────────────
       Remove the #cam-reader element from the DOM.
       This kills the <video> stream immediately — the browser
       releases the camera hardware with no async wait at all.
       No scanner.stop() needed.                             */
    function destroyReader() {
      var old = document.getElementById('cam-reader');
      if (old) old.parentNode.removeChild(old);
      scanner    = null;
      isStarting = false;
    }

    /* ── closeOverlay — INSTANT ───────────────────────────
       1. Hide overlay (same frame as tap)
       2. Destroy reader element (releases camera hardware)
       Everything is synchronous — zero async delay.        */
    function closeOverlay() {
      if (!isOpen) return;
      isOpen     = false;
      isStarting = false;

      /* 1. Hide UI immediately */
      overlay.classList.remove('open');

      /* 2. Kill video element — releases camera instantly */
      destroyReader();

      /* 3. Reset state for next open */
      permErr.classList.remove('show');
      scanLine.style.display = 'block';
      manRow.classList.remove('show');
      setStatus('loading', 'Starting camera…');
    }

    /* ── openScanner ──────────────────────────────────────*/
    function openScanner() {
      if (isOpen || isStarting) return;
      isOpen     = true;
      isStarting = true;

      /* Reset UI */
      permErr.classList.remove('show');
      scanLine.style.display = 'block';
      manualIn.value = '';
      manRow.classList.remove('show');
      setStatus('loading', 'Starting camera…');

      /* Create a fresh #cam-reader div inside the wrap */
      destroyReader();
      var readerDiv    = document.createElement('div');
      readerDiv.id     = 'cam-reader';
      /* Insert before the first child (before brackets / scan-line) */
      wrap.insertBefore(readerDiv, wrap.firstChild);

      overlay.classList.add('open');

      /* Start scanner on the new element */
      scanner = new Html5Qrcode('cam-reader');

      Html5Qrcode.getCameras().then(function (cameras) {
        if (!isOpen) { destroyReader(); return; }

        if (!cameras || cameras.length === 0) {
          isStarting = false;
          setStatus('error', 'No camera found on this device.');
          return;
        }

        scanner.start(
          { facingMode: 'environment' },
          {
            fps: 10,
            qrbox: function (w, h) {
              var s = Math.min(w, h) * 0.7;
              return { width: s, height: s };
            },
            aspectRatio: 1.0,
          },
          function onDecode(text) {
            if (!isOpen) return;

            /* Got a QR — close immediately and fire callback */
            setStatus('success', '✓ ' + text);
            closeOverlay();        /* instant — no waiting */
            onScan(text.trim());
          },
          function () { /* per-frame scan failures — ignore */ }
        ).then(function () {
          isStarting = false;
          if (!isOpen) { destroyReader(); return; }
          setStatus('ready', '📷 Point camera at QR code');
        }).catch(function (err) {
          isStarting = false;
          var msg = err ? err.toString() : '';
          if (msg.indexOf('ermission') !== -1 || msg.indexOf('enied') !== -1) {
            scanLine.style.display = 'none';
            permErr.classList.add('show');
            setStatus('error', 'Camera permission denied.');
          } else {
            setStatus('error', 'Camera error — try again.');
            console.warn('cam-scanner:', err);
          }
        });

      }).catch(function () {
        isStarting = false;
        scanLine.style.display = 'none';
        permErr.classList.add('show');
        setStatus('error', 'Cannot access camera.');
      });
    }

    /* ── Status helper ─────────────────────────────────── */
    function setStatus(type, msg) {
      statusEl.className = type === 'ready'   ? 'cam-ready'   :
                           type === 'error'   ? 'cam-error'   :
                           type === 'success' ? 'cam-success' : '';
      if (type === 'loading') {
        statusEl.innerHTML = '<span class="cam-spinner"></span> ' + msg;
      } else {
        statusEl.textContent = msg;
      }
    }

    /* ── Manual entry ──────────────────────────────────── */
    function submitManual() {
      var val = manualIn.value.trim();
      if (!val) { manualIn.focus(); return; }
      closeOverlay();
      onScan(val);
    }

    /* ── Bind events ───────────────────────────────────── */

    /* Close button — both click AND touchend for tablets */
    function handleClose(e) {
      e.preventDefault();
      e.stopPropagation();
      closeOverlay();
    }
    closeBtn.addEventListener('click',    handleClose);
    closeBtn.addEventListener('touchend', handleClose, { passive: false });

    /* Open button */
    function handleOpen(e) {
      e.preventDefault();
      openScanner();
    }
    scanBtn.addEventListener('click',    openScanner);
    scanBtn.addEventListener('touchend', handleOpen, { passive: false });

    /* Tap dark area outside box */
    overlay.addEventListener('click', function (e) {
      if (e.target === overlay) closeOverlay();
    });

    /* Manual toggle */
    manToggle.addEventListener('click', function () {
      manRow.classList.toggle('show');
      if (manRow.classList.contains('show')) manualIn.focus();
    });
    manualBtn.addEventListener('click', submitManual);
    manualIn.addEventListener('keydown', function (e) {
      if (e.key === 'Enter') submitManual();
    });

    /* ── Keyboard shortcuts ───────────────────────────────
       S key  = open camera scanner
       ESC    = close camera scanner                       */
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && isOpen) { closeOverlay(); return; }
      if ((e.key === 's' || e.key === 'S') && !isOpen) {
        var tag = document.activeElement ? document.activeElement.tagName : '';
        if (!['INPUT', 'TEXTAREA', 'SELECT'].includes(tag)) openScanner();
      }
    });

    /* ── Hardware barcode scanner support ─────────────────
       A hardware scanner gun types characters very fast
       (all chars arrive within ~80ms) then sends Enter.
       We buffer keystrokes on the document and treat any
       rapid burst ending in Enter as a scanner input.
       Works on ANY page — no input needs to be focused.
       Ignored when: camera overlay is open, or a real
       input/textarea/select already has focus.           */
    var hwBuffer  = '';
    var hwTimer   = null;
    var HW_GAP_MS = 80; /* ms — hardware scanners finish well under this */

    document.addEventListener('keydown', function (e) {
      /* If camera overlay is open, ignore hardware scanner */
      if (isOpen) return;

      /* If user is typing in a real input, leave it alone */
      var tag = document.activeElement ? document.activeElement.tagName : '';
      if (['INPUT', 'TEXTAREA', 'SELECT'].includes(tag)) return;

      if (e.key === 'Enter') {
        /* Enter = end of barcode stream */
        var scanned = hwBuffer.trim();
        hwBuffer = '';
        clearTimeout(hwTimer);
        if (scanned.length > 3) {
          /* Fire the same onScan callback as camera scanner */
          onScan(scanned);
        }
        return;
      }

      /* Ignore modifier-only keys (Ctrl, Alt, Shift, etc.) */
      if (e.key.length > 1) return;

      /* Accumulate character */
      hwBuffer += e.key;

      /* Safety timeout: discard buffer if no Enter comes within HW_GAP_MS
         This prevents stray keyboard taps from polluting the buffer */
      clearTimeout(hwTimer);
      hwTimer = setTimeout(function () {
        hwBuffer = '';
      }, HW_GAP_MS);
    });
  }

})();