/**
 * Galaxy Tracker — Non-Intrusive Tip Jar Modal
 * Handles PayPal & Cash App support links with zero tracking or clutter.
 * Fully lazy-loaded and self-contained with inline fallback styles.
 */
(function () {
  const PAYPAL_URL = 'https://www.paypal.biz/dmillsMTG';
  const CASHAPP_URL = 'https://cash.app/$CruelUltimatum';
  const CASHTAG = '$CruelUltimatum';

  const MODAL_CSS = `
    .tip-jar-backdrop {
      display: none;
      position: fixed;
      inset: 0;
      background: rgba(8, 11, 18, 0.82);
      backdrop-filter: blur(8px);
      -webkit-backdrop-filter: blur(8px);
      z-index: 999999;
      align-items: center;
      justify-content: center;
      padding: 20px;
      opacity: 0;
      pointer-events: none;
      transition: opacity 0.22s ease-out;
    }
    .tip-jar-backdrop.active {
      display: flex !important;
      opacity: 1 !important;
      pointer-events: auto !important;
    }
    body.modal-open {
      overflow: hidden;
    }
    .tip-jar-dialog {
      background: linear-gradient(145deg, #131926, #0e131d);
      border: 1px solid rgba(255, 255, 255, 0.12);
      border-radius: 16px;
      width: 100%;
      max-width: 490px;
      box-shadow: 0 25px 60px rgba(0, 0, 0, 0.65), 0 0 40px rgba(99, 102, 241, 0.15);
      padding: 24px 26px;
      color: #f1f5f9;
      transform: scale(0.96) translateY(10px);
      transition: transform 0.22s cubic-bezier(0.16, 1, 0.3, 1);
      box-sizing: border-box;
      font-family: 'Inter', system-ui, -apple-system, sans-serif;
    }
    .tip-jar-backdrop.active .tip-jar-dialog {
      transform: scale(1) translateY(0);
    }
    .tip-jar-header {
      display: flex;
      align-items: center;
      justify-content: space-between;
      margin-bottom: 12px;
    }
    .tip-jar-title-wrap {
      display: flex;
      align-items: center;
      gap: 10px;
    }
    .tip-jar-emoji {
      font-size: 1.4rem;
    }
    .tip-jar-header h3 {
      font-size: 1.25rem;
      font-weight: 700;
      color: #fff;
      margin: 0;
    }
    .tip-jar-close {
      background: none;
      border: none;
      color: #94a3b8;
      font-size: 1.8rem;
      line-height: 1;
      cursor: pointer;
      padding: 0 4px;
      border-radius: 6px;
      transition: color 0.15s;
    }
    .tip-jar-close:hover {
      color: #fff;
    }
    .tip-jar-note {
      font-size: 0.88rem;
      line-height: 1.55;
      color: #94a3b8;
      margin-bottom: 20px;
    }
    .tip-jar-note em {
      color: #cbd5e1;
      font-style: normal;
      font-weight: 600;
    }
    .tip-options-grid {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 14px;
    }
    @media (max-width: 520px) {
      .tip-options-grid {
        grid-template-columns: 1fr;
      }
    }
    .tip-card {
      background: rgba(255, 255, 255, 0.035);
      border: 1px solid rgba(255, 255, 255, 0.08);
      border-radius: 12px;
      padding: 16px;
      display: flex;
      flex-direction: column;
      justify-content: space-between;
      gap: 14px;
      transition: border-color 0.2s, background 0.2s, transform 0.2s;
      box-sizing: border-box;
    }
    .tip-card:hover {
      border-color: rgba(255, 255, 255, 0.18);
      background: rgba(255, 255, 255, 0.055);
      transform: translateY(-2px);
    }
    .tip-card-top {
      display: flex;
      flex-direction: column;
      gap: 8px;
    }
    .tip-provider-badge {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      font-size: 0.75rem;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.5px;
      padding: 3px 8px;
      border-radius: 6px;
      width: fit-content;
    }
    .tip-provider-badge.paypal {
      background: rgba(0, 112, 186, 0.18);
      color: #38bdf8;
      border: 1px solid rgba(56, 189, 248, 0.3);
    }
    .tip-provider-badge.cashapp {
      background: rgba(0, 214, 50, 0.15);
      color: #4ade80;
      border: 1px solid rgba(74, 222, 128, 0.3);
    }
    .tip-svg {
      width: 14px;
      height: 14px;
      display: inline-block;
      vertical-align: middle;
    }
    .tip-handle {
      font-family: 'JetBrains Mono', monospace, sans-serif;
      font-size: 0.95rem;
      font-weight: 600;
      color: #fff;
      word-break: break-all;
    }
    .tip-btn {
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 6px;
      padding: 9px 12px;
      border-radius: 8px;
      font-size: 0.86rem;
      font-weight: 600;
      text-decoration: none;
      transition: filter 0.15s, transform 0.15s;
    }
    .tip-btn.paypal-btn {
      background: linear-gradient(135deg, #0070ba, #00508f);
      color: #fff;
    }
    .tip-btn.paypal-btn:hover {
      filter: brightness(1.15);
      transform: translateY(-1px);
    }
    .tip-btn.cashapp-btn {
      background: linear-gradient(135deg, #00D632, #009e25);
      color: #051a0b;
      font-weight: 700;
      flex: 1;
    }
    .tip-btn.cashapp-btn:hover {
      filter: brightness(1.15);
      transform: translateY(-1px);
    }
    .tip-arrow {
      font-size: 1rem;
    }
    .tip-dual-actions {
      display: flex;
      gap: 6px;
      align-items: center;
    }
    .tip-copy-btn {
      background: rgba(255, 255, 255, 0.08);
      border: 1px solid rgba(255, 255, 255, 0.15);
      color: #cbd5e1;
      font-size: 0.8rem;
      font-weight: 600;
      padding: 9px 10px;
      border-radius: 8px;
      cursor: pointer;
      transition: all 0.15s;
      white-space: nowrap;
    }
    .tip-copy-btn:hover {
      background: rgba(255, 255, 255, 0.16);
      color: #fff;
    }
    .tip-copy-btn.copied {
      background: rgba(74, 222, 128, 0.2);
      border-color: #4ade80;
      color: #4ade80;
    }
  `;

  function createModalHtml() {
    return `
      <div id="tipJarModal" class="tip-jar-backdrop" style="display: none;" aria-hidden="true" role="dialog" aria-labelledby="tipJarTitle">
        <div class="tip-jar-dialog">
          <div class="tip-jar-header">
            <div class="tip-jar-title-wrap">
              <span class="tip-jar-emoji">☕</span>
              <h3 id="tipJarTitle">Galaxy Tracker Tip Jar</h3>
            </div>
            <button type="button" class="tip-jar-close" aria-label="Close Tip Jar" onclick="window.closeTipJar()">&times;</button>
          </div>

          <div class="tip-jar-body">
            <p class="tip-jar-note">
              Galaxy Tracker and its analytics are 100% free tools created for the <em>Once Upon a Galaxy</em> community. Tips help cover database, server, and domain costs, but are never expected! Thank you for the support. ❤️
            </p>

            <div class="tip-options-grid">
              <!-- PayPal Card -->
              <div class="tip-card tip-card-paypal">
                <div class="tip-card-top">
                  <div class="tip-provider-badge paypal">
                    <svg viewBox="0 0 24 24" class="tip-svg" fill="currentColor">
                      <path d="M7.076 21.337H2.47a.641.641 0 0 1-.633-.74L4.944 3.72a.787.787 0 0 1 .776-.66h7.682c3.042 0 5.485 1.05 6.138 4.292.355 1.761-.033 3.32-.932 4.417-.992 1.209-2.613 1.834-4.819 1.834h-1.89a.788.788 0 0 0-.776.66l-.807 5.127a.64.64 0 0 1-.633.547H7.076z"/>
                    </svg>
                    <span>PayPal</span>
                  </div>
                  <span class="tip-handle">dmillsMTG</span>
                </div>
                <a href="${PAYPAL_URL}" target="_blank" rel="noopener noreferrer" class="tip-btn paypal-btn">
                  Send via PayPal <span class="tip-arrow">↗</span>
                </a>
              </div>

              <!-- Cash App Card -->
              <div class="tip-card tip-card-cashapp">
                <div class="tip-card-top">
                  <div class="tip-provider-badge cashapp">
                    <svg viewBox="0 0 24 24" class="tip-svg" fill="currentColor">
                      <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1.41 16.09V20h-2.67v-1.93c-1.71-.36-3.16-1.46-3.27-3.4h2.45c.1 1.05.82 1.63 1.82 1.63.98 0 1.61-.53 1.61-1.34 0-.82-.64-1.25-2.07-1.63-1.92-.51-3.37-1.28-3.37-3.16 0-1.63 1.25-2.82 2.83-3.2V5h2.67v1.89c1.47.33 2.68 1.34 2.82 3.03h-2.43c-.12-.86-.69-1.37-1.54-1.37-.88 0-1.47.53-1.47 1.23 0 .76.59 1.15 1.94 1.51 2.06.55 3.5 1.32 3.5 3.32 0 1.73-1.33 2.97-2.98 3.38z"/>
                    </svg>
                    <span>Cash App</span>
                  </div>
                  <span class="tip-handle">${CASHTAG}</span>
                </div>
                <div class="tip-dual-actions">
                  <a href="${CASHAPP_URL}" target="_blank" rel="noopener noreferrer" class="tip-btn cashapp-btn">
                    Open Cash App <span class="tip-arrow">↗</span>
                  </a>
                  <button type="button" class="tip-copy-btn" onclick="window.copyTipCashtag(this)" title="Copy $Cashtag to clipboard">
                    📋 Copy
                  </button>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    `;
  }

  function ensureModalInjected() {
    if (!document.getElementById('tipJarStyles')) {
      const styleEl = document.createElement('style');
      styleEl.id = 'tipJarStyles';
      styleEl.textContent = MODAL_CSS;
      document.head.appendChild(styleEl);
    }
    if (!document.getElementById('tipJarModal')) {
      const wrapper = document.createElement('div');
      wrapper.innerHTML = createModalHtml().trim();
      document.body.appendChild(wrapper.firstChild);
    }
  }

  window.openTipJar = function (e) {
    if (e && e.preventDefault) e.preventDefault();
    ensureModalInjected();
    const modal = document.getElementById('tipJarModal');
    if (modal) {
      modal.style.display = 'flex';
      // Force reflow for smooth opacity transition
      void modal.offsetWidth;
      modal.classList.add('active');
      modal.setAttribute('aria-hidden', 'false');
      document.body.classList.add('modal-open');
    }
  };

  window.closeTipJar = function () {
    const modal = document.getElementById('tipJarModal');
    if (modal) {
      modal.classList.remove('active');
      modal.setAttribute('aria-hidden', 'true');
      document.body.classList.remove('modal-open');
      setTimeout(() => {
        if (!modal.classList.contains('active')) {
          modal.style.display = 'none';
        }
      }, 240);
      if (window.location.hash === '#tip-jar' || window.location.hash === '#tip') {
        history.replaceState(null, document.title, window.location.pathname + window.location.search);
      }
    }
  };

  window.copyTipCashtag = function (btn) {
    const tag = CASHTAG;
    const originalText = btn.innerHTML;

    function showSuccess() {
      btn.innerHTML = '✓ Copied!';
      btn.classList.add('copied');
      setTimeout(() => {
        btn.innerHTML = originalText;
        btn.classList.remove('copied');
      }, 2200);
    }

    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(tag).then(showSuccess).catch(() => {
        fallbackCopy();
      });
    } else {
      fallbackCopy();
    }

    function fallbackCopy() {
      const ta = document.createElement('textarea');
      ta.value = tag;
      ta.style.position = 'fixed';
      ta.style.opacity = '0';
      document.body.appendChild(ta);
      ta.focus();
      ta.select();
      try {
        document.execCommand('copy');
        showSuccess();
      } catch (err) {
        console.error('Copy failed:', err);
      }
      document.body.removeChild(ta);
    }
  };

  // Event Listeners
  document.addEventListener('click', (e) => {
    // Backdrop click
    const modal = document.getElementById('tipJarModal');
    if (modal && e.target === modal) {
      window.closeTipJar();
      return;
    }

    // Trigger links
    const trigger = e.target.closest('a[href="#tip-jar"], a[href="#tip"], .tip-jar-btn, .tip-jar-link');
    if (trigger) {
      e.preventDefault();
      window.openTipJar();
    }
  });

  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' || e.key === 'Esc') {
      window.closeTipJar();
    }
  });

  function checkHash() {
    if (window.location.hash === '#tip-jar' || window.location.hash === '#tip') {
      window.openTipJar();
    }
  }

  // Only check hash on load; NEVER unconditionally inject modal DOM until requested!
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', checkHash);
  } else {
    checkHash();
  }

  window.addEventListener('hashchange', checkHash);
})();
