/* CampusLink — interface behaviour (progressive enhancement only). */
(function () {
  'use strict';

  var reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  /* ---------- Account dropdown ------------------------------------- */
  function setupAccountMenu() {
    var wrap = document.querySelector('[data-acct]');
    if (!wrap) return;
    var btn  = wrap.querySelector('[data-acct-toggle]');
    var menu = wrap.querySelector('[data-acct-menu]');
    if (!btn || !menu) return;

    function items() {
      return Array.prototype.slice.call(menu.querySelectorAll('[role="menuitem"]'));
    }
    function close() {
      menu.classList.remove('is-open');
      btn.setAttribute('aria-expanded', 'false');
    }
    function open() {
      menu.classList.add('is-open');
      btn.setAttribute('aria-expanded', 'true');
      var first = items()[0];
      if (first) first.focus();
    }

    btn.addEventListener('click', function (e) {
      e.stopPropagation();
      menu.classList.contains('is-open') ? close() : open();
    });
    document.addEventListener('click', function (e) {
      if (!wrap.contains(e.target)) close();
    });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && menu.classList.contains('is-open')) {
        close();
        btn.focus();
      }
    });
    menu.addEventListener('keydown', function (e) {
      var list = items();
      if (!list.length) return;
      var i = list.indexOf(document.activeElement);
      if (e.key === 'ArrowDown') {
        e.preventDefault();
        list[(i + 1) % list.length].focus();
      } else if (e.key === 'ArrowUp') {
        e.preventDefault();
        list[(i <= 0 ? list.length : i) - 1].focus();
      } else if (e.key === 'Home') {
        e.preventDefault();
        list[0].focus();
      } else if (e.key === 'End') {
        e.preventDefault();
        list[list.length - 1].focus();
      }
    });
  }

  /* ---------- Focus trap helper ------------------------------------ */
  function trapFocus(container, e) {
    var focusables = container.querySelectorAll(
      'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'
    );
    if (!focusables.length) return;
    var first = focusables[0];
    var last  = focusables[focusables.length - 1];
    if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
    else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
  }

  /* ---------- Slide-in overlays (drawer + dashboard sidebar) -------- */
  function setupOverlay(panelSel, openSel, closeSel, scrimSel) {
    var panel = document.querySelector(panelSel);
    if (!panel) return;
    var scrim = document.querySelector(scrimSel);
    var openers = document.querySelectorAll(openSel);
    var closers = document.querySelectorAll(closeSel);
    var lastFocused = null;

    function open() {
      lastFocused = document.activeElement;
      panel.classList.add('is-open');
      if (scrim) scrim.classList.add('is-open');
      openers.forEach(function (b) { b.setAttribute('aria-expanded', 'true'); });
      var f = panel.querySelector('input, a[href], button:not([disabled])');
      if (f) f.focus();
      document.addEventListener('keydown', onKey);
    }
    function close() {
      panel.classList.remove('is-open');
      if (scrim) scrim.classList.remove('is-open');
      openers.forEach(function (b) { b.setAttribute('aria-expanded', 'false'); });
      document.removeEventListener('keydown', onKey);
      if (lastFocused && lastFocused.focus) lastFocused.focus();
    }
    function onKey(e) {
      if (e.key === 'Escape') { close(); return; }
      if (e.key === 'Tab') trapFocus(panel, e);
    }

    openers.forEach(function (b) { b.addEventListener('click', open); });
    closers.forEach(function (b) { b.addEventListener('click', close); });
    if (scrim) scrim.addEventListener('click', close);
  }

  /* ---------- Toasts ----------------------------------------------- */
  function setupToasts() {
    var stack = document.getElementById('toastStack');
    if (!stack) return;
    stack.querySelectorAll('.toast').forEach(function (toast) {
      toast.querySelectorAll('.toast-close').forEach(function (b) {
        b.addEventListener('click', function () { toast.remove(); });
      });
      setTimeout(function () {
        toast.style.transition = reduceMotion ? 'none' : 'opacity .2s ease, transform .2s ease';
        toast.style.opacity = '0';
        toast.style.transform = 'translateY(6px)';
        setTimeout(function () { toast.remove(); }, 220);
      }, 6000);
    });
  }

  /* ---------- Password visibility ---------------------------------- */
  function setupPasswordToggles() {
    document.querySelectorAll('[data-pw-toggle]').forEach(function (btn) {
      var field = btn.closest('.pw-field');
      var input = field && field.querySelector('input');
      if (!input) return;
      btn.addEventListener('click', function () {
        var show = input.type === 'password';
        input.type = show ? 'text' : 'password';
        btn.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
        btn.setAttribute('aria-pressed', show ? 'true' : 'false');
        btn.classList.toggle('is-on', show);
        input.focus();
      });
    });
  }

  /* ---------- Password policy (mirrors PHP) ------------------------- */
  var COMMON_PASSWORDS = [
    'password', 'password1', 'password123', 'passw0rd',
    '12345678', '123456789', '1234567890',
    'qwerty123', 'qwertyui', 'qwertyuiop',
    'iloveyou', 'admin123', 'letmein', 'welcome1',
    'abc12345', '11111111', 'changeme',
    'football', 'baseball', 'sunshine', 'princess',
    'dragon123', 'campuslink', 'student1'
  ];
  var COMMON_PASSWORD_STEMS = [
    'password', 'passw0rd', 'qwerty', 'letmein', 'welcome', 'admin',
    'campuslink', 'student', 'iloveyou', 'changeme', 'dragon',
    'football', 'baseball', 'sunshine', 'princess'
  ];

  function passwordPolicyError(password, email, name) {
    if (!password || password.length < 12) {
      return 'Password must be at least 12 characters.';
    }
    if (password.length > 200) {
      return 'Password is too long (200 characters maximum).';
    }
    if (/^[0-9]+$/.test(password)) {
      return 'Password cannot contain only numbers. Use letters and at least one symbol.';
    }

    var lower = password.toLowerCase();
    if (/(.)\1{3,}/.test(lower) || /(.{2})\1{2,}/.test(lower)) {
      return 'Password cannot contain repeated characters or repeated patterns.';
    }
    for (var i = 0; i <= lower.length - 4; i++) {
      var ascending = true;
      var descending = true;
      for (var j = 1; j < 4; j++) {
        var difference = lower.charCodeAt(i + j) - lower.charCodeAt(i + j - 1);
        ascending = ascending && difference === 1;
        descending = descending && difference === -1;
      }
      if (ascending || descending) {
        return 'Password cannot contain sequential characters such as 1234 or abcd.';
      }
    }

    if (!/[a-z]/.test(password)) return 'Password must include at least one lowercase letter.';
    if (!/[A-Z]/.test(password)) return 'Password must include at least one uppercase letter.';
    if (!/[0-9]/.test(password)) return 'Password must include at least one number.';
    if (!/[^A-Za-z0-9\s]/.test(password)) return 'Password must include at least one symbol, such as !, @, #, or $.';
    if (COMMON_PASSWORDS.indexOf(lower) !== -1) {
      return 'That password is too common. Please choose something less predictable.';
    }
    for (var k = 0; k < COMMON_PASSWORD_STEMS.length; k++) {
      var stem = COMMON_PASSWORD_STEMS[k];
      if (lower.indexOf(stem) === 0 && /^[0-9!@#$%^&*._?+\-]*$/.test(lower.slice(stem.length))) {
        return 'That password is too common. Please choose something less predictable.';
      }
    }

    var emailLower = (email || '').trim().toLowerCase();
    if (emailLower && lower.indexOf(emailLower) !== -1) {
      return 'Password must not contain your email address.';
    }
    var nameLower = (name || '').trim().toLowerCase();
    if (nameLower.length >= 3 && lower.indexOf(nameLower) !== -1) {
      return 'Password must not contain your name.';
    }
    return '';
  }

  function setupPasswordPolicy() {
    document.querySelectorAll('form[data-password-policy]').forEach(function (form) {
      form.addEventListener('submit', function (event) {
        var passwordInput = form.querySelector('#password, [name="password"], #new_password, [name="new_password"]');
        var confirmInput = form.querySelector('#confirm, [name="confirm"], #confirm_password, [name="confirm_password"]');
        var emailInput = form.querySelector('#email, [name="email"]');
        var nameInput = form.querySelector('#name, [name="name"]');
        var errorEl = form.querySelector('#password-client-error');
        if (!passwordInput) return;

        var password = passwordInput.value || '';
        if (form.getAttribute('data-password-policy') === 'profile' && password === '') {
          if (errorEl) {
            errorEl.hidden = true;
            errorEl.textContent = '';
          }
          return;
        }
        var email = emailInput ? emailInput.value : '';
        var name = nameInput ? nameInput.value : '';
        var message = passwordPolicyError(password, email, name);
        if (!message && confirmInput && password !== (confirmInput.value || '')) {
          message = 'Passwords do not match.';
        }

        if (message) {
          event.preventDefault();
          if (errorEl) {
            errorEl.hidden = false;
            errorEl.textContent = message;
          } else {
            window.alert(message);
          }
          passwordInput.focus();
          return;
        }
        if (errorEl) {
          errorEl.hidden = true;
          errorEl.textContent = '';
        }
      });
    });
  }

  /* ---------- Quantity steppers ------------------------------------ */
  function setupQuantity() {
    document.querySelectorAll('[data-qty]').forEach(function (group) {
      var input = group.querySelector('input');
      if (!input) return;
      var min = parseInt(input.getAttribute('min') || '1', 10);
      var max = parseInt(input.getAttribute('max') || '999', 10);
      group.querySelectorAll('[data-qty-step]').forEach(function (btn) {
        btn.addEventListener('click', function () {
          var step = parseInt(btn.getAttribute('data-qty-step'), 10) || 0;
          var next = (parseInt(input.value, 10) || min) + step;
          next = Math.max(min, Math.min(max, next));
          input.value = next;
          input.dispatchEvent(new Event('change', { bubbles: true }));
        });
      });
    });
  }

  /* ---------- Destructive action confirmation ---------------------- */
  function setupConfirm() {
    document.querySelectorAll('[data-confirm]').forEach(function (el) {
      el.addEventListener('click', function (e) {
        if (el.dataset.confirmed === '1') return;
        if (!window.confirm(el.getAttribute('data-confirm'))) {
          e.preventDefault();
          e.stopPropagation();
        }
      });
    });
  }

  /* ---------- Auto-submit filter selects --------------------------- */
  function setupAutoSubmit() {
    document.querySelectorAll('form[data-autosubmit] select, form[data-autosubmit] input[type="search"]').forEach(function (el) {
      el.addEventListener('change', function () {
        if (el.type === 'search') return;
        el.form && el.form.submit();
      });
    });
  }

  /* ---------- Listing image preview + 5 MB client check ------------ */
  // Keep in sync with SEC_IMAGE_MAX_BYTES in includes/security.php
  var IMAGE_MAX_BYTES = 5 * 1024 * 1024;

  function setupImagePreview() {
    document.querySelectorAll('input[type="file"][data-img-preview]').forEach(function (input) {
      var target = document.querySelector(input.getAttribute('data-img-preview'));
      if (!target) return;
      var img = target.querySelector('img');
      if (!img) return;
      var hint = input.closest('.form-group') && input.closest('.form-group').querySelector('.form-hint');
      var errorId = input.id ? input.id + '-size-error' : '';
      var errorEl = errorId ? document.getElementById(errorId) : null;
      if (!errorEl && hint) {
        errorEl = document.createElement('p');
        errorEl.className = 'form-hint';
        errorEl.id = errorId || undefined;
        errorEl.setAttribute('role', 'alert');
        errorEl.hidden = true;
        errorEl.style.color = 'var(--clay,#B4532A)';
        hint.insertAdjacentElement('afterend', errorEl);
      }

      input.addEventListener('change', function () {
        var file = input.files && input.files[0];
        if (errorEl) {
          errorEl.hidden = true;
          errorEl.textContent = '';
        }
        if (!file) {
          img.removeAttribute('src');
          target.classList.remove('has-image');
          return;
        }
        if (file.type.indexOf('image/') !== 0) {
          input.value = '';
          img.removeAttribute('src');
          target.classList.remove('has-image');
          if (errorEl) {
            errorEl.hidden = false;
            errorEl.textContent = 'Image must be JPEG, PNG, WebP or GIF.';
          }
          return;
        }
        if (file.size > IMAGE_MAX_BYTES) {
          input.value = '';
          img.removeAttribute('src');
          target.classList.remove('has-image');
          if (errorEl) {
            errorEl.hidden = false;
            errorEl.textContent = 'Image must be under 5 MB.';
          } else {
            window.alert('Image must be under 5 MB.');
          }
          return;
        }
        var url = URL.createObjectURL(file);
        img.addEventListener('load', function () { URL.revokeObjectURL(url); }, { once: true });
        img.src = url;
        target.classList.add('has-image');
      });
    });
  }

  /* ---------- Copy-to-clipboard ------------------------------------ */
  function setupCopyButtons() {
    document.querySelectorAll('[data-copy]').forEach(function (btn) {
      var target = document.getElementById(btn.getAttribute('data-copy'));
      if (!target) return;
      btn.addEventListener('click', function () {
        var value = target.value !== undefined ? target.value : target.textContent;
        value = (value || '').trim();
        var done = function () {
          var original = btn.innerHTML;
          btn.textContent = 'Copied';
          setTimeout(function () { btn.innerHTML = original; }, 1600);
        };
        if (navigator.clipboard && navigator.clipboard.writeText) {
          navigator.clipboard.writeText(value).then(done, function () {});
        } else if (target.select) {
          target.select();
          try { document.execCommand('copy'); done(); } catch (e) {}
        }
      });
    });
  }

  /* ---------- Homepage hero photo carousel ------------------------- */
  function setupHeroCarousel() {
    var root = document.querySelector('[data-hero-carousel]');
    if (!root) return;

    var slides = Array.prototype.slice.call(root.querySelectorAll('.hero-slide'));
    var dots = Array.prototype.slice.call(root.querySelectorAll('[data-hero-dot]'));
    var status = root.querySelector('[data-hero-status]');
    var prevBtn = root.querySelector('[data-hero-prev]');
    var nextBtn = root.querySelector('[data-hero-next]');
    if (slides.length < 2) return;

    var index = Math.max(0, slides.findIndex(function (s) { return s.classList.contains('is-active'); }));
    var intervalMs = parseInt(root.getAttribute('data-interval') || '5500', 10) || 5500;
    var timer = null;
    var hovering = false;
    var touchX = null;

    function labels() {
      return slides.map(function (slide, i) {
        var cap = slide.querySelector('.hero-slide-caption');
        return (cap && cap.textContent.trim()) || ('Photo ' + (i + 1));
      });
    }

    function preload(i) {
      var img = slides[i] && slides[i].querySelector('img');
      if (!img || img.dataset.preloaded === '1') return;
      var src = img.currentSrc || img.src;
      if (!src) return;
      var warm = new Image();
      warm.src = src;
      img.dataset.preloaded = '1';
    }

    function go(to) {
      var next = ((to % slides.length) + slides.length) % slides.length;
      if (next === index) return;
      slides[index].classList.remove('is-active');
      slides[index].setAttribute('aria-hidden', 'true');
      if (dots[index]) {
        dots[index].classList.remove('is-on');
        dots[index].setAttribute('aria-selected', 'false');
      }

      index = next;
      slides[index].classList.add('is-active');
      slides[index].removeAttribute('aria-hidden');
      if (dots[index]) {
        dots[index].classList.add('is-on');
        dots[index].setAttribute('aria-selected', 'true');
      }
      if (status) status.textContent = 'Showing ' + labels()[index];
      preload((index + 1) % slides.length);
    }

    function stop() {
      if (timer) { clearInterval(timer); timer = null; }
    }

    function start() {
      stop();
      if (reduceMotion || hovering || document.hidden) return;
      timer = setInterval(function () { go(index + 1); }, intervalMs);
    }

    if (prevBtn) prevBtn.addEventListener('click', function () { go(index - 1); start(); });
    if (nextBtn) nextBtn.addEventListener('click', function () { go(index + 1); start(); });
    dots.forEach(function (dot) {
      dot.addEventListener('click', function () {
        var to = parseInt(dot.getAttribute('data-hero-dot') || '0', 10);
        go(to);
        start();
      });
    });

    root.addEventListener('mouseenter', function () { hovering = true; stop(); });
    root.addEventListener('mouseleave', function () { hovering = false; start(); });
    root.addEventListener('focusin', function () { hovering = true; stop(); });
    root.addEventListener('focusout', function (e) {
      if (!root.contains(e.relatedTarget)) { hovering = false; start(); }
    });

    root.addEventListener('keydown', function (e) {
      if (e.key === 'ArrowLeft') { e.preventDefault(); go(index - 1); start(); }
      if (e.key === 'ArrowRight') { e.preventDefault(); go(index + 1); start(); }
    });
    if (!root.hasAttribute('tabindex')) root.setAttribute('tabindex', '0');

    root.addEventListener('touchstart', function (e) {
      if (!e.changedTouches || !e.changedTouches.length) return;
      touchX = e.changedTouches[0].clientX;
      stop();
    }, { passive: true });
    root.addEventListener('touchend', function (e) {
      if (touchX === null || !e.changedTouches || !e.changedTouches.length) return;
      var dx = e.changedTouches[0].clientX - touchX;
      touchX = null;
      if (Math.abs(dx) < 40) { start(); return; }
      go(dx < 0 ? index + 1 : index - 1);
      start();
    }, { passive: true });

    document.addEventListener('visibilitychange', function () {
      if (document.hidden) stop(); else start();
    });

    preload((index + 1) % slides.length);
    start();
  }

  /* ---------- Checkout one-click submit guard ---------------------- */
  function setupCheckoutForm() {
    var form = document.querySelector('[data-checkout-form]');
    if (!form) return;
    var btn = form.querySelector('#checkoutSubmit') || form.querySelector('[type="submit"]');
    if (!btn) return;

    var labelEl = btn.querySelector('.btn-label');
    var defaultHtml = btn.innerHTML;
    var processing = form.getAttribute('data-processing-label') || 'Processing order…';
    var hint = form.querySelector('.checkout-pending-hint');
    var busy = false;

    function setBusy(on) {
      busy = on;
      form.classList.toggle('is-submitting', on);
      btn.setAttribute('aria-busy', on ? 'true' : 'false');
      if (hint) hint.hidden = !on;
      if (on) {
        if (labelEl) labelEl.textContent = processing;
        else btn.textContent = processing;
        // Defer disabled so Chromium does not cancel this submission.
        setTimeout(function () { btn.disabled = true; }, 0);
      } else {
        btn.disabled = false;
        btn.innerHTML = defaultHtml;
        labelEl = btn.querySelector('.btn-label');
      }
    }

    form.addEventListener('submit', function (e) {
      if (busy) {
        e.preventDefault();
        return;
      }
      setBusy(true);
    });

    // Back-forward cache / cancelled navigation: restore the button.
    window.addEventListener('pageshow', function () {
      if (busy) setBusy(false);
    });
  }

  /** Label dashboard table cells for mobile card layout (CSS uses data-label). */
  function setupResponsiveTables() {
    document.querySelectorAll('.dash .table-wrap table').forEach(function (table) {
      var heads = Array.prototype.map.call(table.querySelectorAll('thead th'), function (th) {
        return (th.textContent || '').trim();
      });
      if (!heads.length) return;
      table.querySelectorAll('tbody tr').forEach(function (tr) {
        Array.prototype.forEach.call(tr.children, function (td, i) {
          if (td.tagName !== 'TD') return;
          if (!td.getAttribute('data-label') && heads[i]) {
            td.setAttribute('data-label', heads[i]);
          }
        });
      });
    });
  }

  function init() {
    setupAccountMenu();
    setupOverlay('#navDrawer', '[data-drawer-open]', '[data-drawer-close]', '[data-drawer-scrim]');
    setupOverlay('#dashSide', '[data-dash-open]', '[data-dash-close]', '[data-dash-scrim]');
    setupToasts();
    setupPasswordToggles();
    setupPasswordPolicy();
    setupQuantity();
    setupConfirm();
    setupAutoSubmit();
    setupImagePreview();
    setupCopyButtons();
    setupHeroCarousel();
    setupCheckoutForm();
    setupResponsiveTables();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
