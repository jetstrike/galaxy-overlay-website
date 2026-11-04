/**
 * Galaxy Tracker — Non-Intrusive Tip Jar Modal
 * Handles PayPal & Cash App support links with zero tracking or clutter.
 */
(function () {
  const PAYPAL_URL = 'https://www.paypal.biz/dmillsMTG';
  const CASHAPP_URL = 'https://cash.app/$CruelUltimatum';
  const CASHTAG = '$CruelUltimatum';

  function createModalHtml() {
    return `
      <div id="tipJarModal" class="tip-jar-backdrop" aria-hidden="true" role="dialog" aria-labelledby="tipJarTitle">
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
              Galaxy Tracker and its analytics are 100% free and open-source tools created for the <em>Once Upon a Galaxy</em> community. Tips help cover database, server, and domain costs, but are never expected! Thank you for the support. ❤️
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

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => {
      ensureModalInjected();
      checkHash();
    });
  } else {
    ensureModalInjected();
    checkHash();
  }

  window.addEventListener('hashchange', checkHash);
})();
