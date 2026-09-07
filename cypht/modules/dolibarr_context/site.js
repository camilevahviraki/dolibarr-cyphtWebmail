'use strict';

/* Dolibarr card on the From row of an open message. */

/* Last card drawn, so a message rendered twice does not fetch twice. */
/* The bridges sit beside public/, which is what this page is served from.
 * A relative URL keeps them same-origin whatever the site is called, so
 * nothing has to be configured and nothing has to be reachable from the
 * server itself. */
var dolibarr_context_bridge = function(name) {
    return '../bridge/' + name + '.php';
};

var dolibarr_context_state = {
    email: null,
    data: null,
    status: '',
    at: 0
};

var dolibarr_context_strings = function(root) {
    return {
        caption: root.getAttribute('data-str-caption') || '',
        empty: root.getAttribute('data-str-empty') || '',
        loading: root.getAttribute('data-str-loading') || '',
        failed: root.getAttribute('data-str-failed') || '',
        retry: root.getAttribute('data-str-retry') || '',
        stale: root.getAttribute('data-str-stale') || '',
        open: root.getAttribute('data-str-open') || '',
        more: root.getAttribute('data-str-more') || '',
        details: root.getAttribute('data-str-details') || '',
        customer: root.getAttribute('data-str-customer') || '',
        supplier: root.getAttribute('data-str-supplier') || '',
        add: root.getAttribute('data-str-add') || '',
        addWhy: root.getAttribute('data-str-add-why') || '',
        addTitle: root.getAttribute('data-str-add-title') || '',
        addName: root.getAttribute('data-str-add-name') || '',
        addHint: root.getAttribute('data-str-add-hint') || '',
        addSave: root.getAttribute('data-str-add-save') || '',
        addWorking: root.getAttribute('data-str-add-working') || '',
        addDone: root.getAttribute('data-str-add-done') || '',
        addExisting: root.getAttribute('data-str-add-existing') || '',
        addFailed: root.getAttribute('data-str-add-failed') || '',
        addOpen: root.getAttribute('data-str-add-open') || ''
    };
};

/* The display name on the open message's From line, for prefilling the create dialog. */
var dolibarr_context_sender_name = function() {
    var el = document.querySelector('.msg_text .js-header_from');
    if (!el) {
        return '';
    }
    var raw = (el.textContent || '').trim();
    var lt = raw.indexOf('<');
    if (lt > 0) {
        raw = raw.slice(0, lt);
    } else if (lt === 0 || raw.indexOf('@') > -1) {
        return ''; /* a bare address is not a name */
    }
    return raw.replace(/^["'\s]+|["'\s]+$/g, '');
};

var dolibarr_context_body = function(root) {
    return root.querySelector('.dolibarr_context_body');
};

/* Move the panel onto the message's From row. */
var dolibarr_context_relocate = function(root) {
    var from = document.querySelector('.msg_text .js-header_from');
    if (!from) {
        return false;
    }

    var host = from.closest('.col-md-10');
    if (!host) {
        var dropdown = from.closest('.dropdown');
        host = dropdown ? dropdown.parentNode : null;
    }
    if (!host) {
        return false;
    }

    if (root.parentNode !== host) {
        host.appendChild(root);
    }
    root.classList.add('dolibarr_context_inline');
    root.classList.remove('border-bottom', 'border-secondary-subtle', 'py-2');

    return true;
};

var dolibarr_context_note = function(root, text, cls) {
    var body = dolibarr_context_body(root);
    if (!body) {
        return;
    }
    body.innerHTML = '';
    var note = document.createElement('span');
    note.className = cls || 'text-muted';
    note.textContent = text;
    body.appendChild(note);
};

/* An anchor out to Dolibarr. */
var dolibarr_context_link = function(text, url, cls) {
    var link = document.createElement('a');
    link.className = cls || 'text-decoration-none';
    link.textContent = text;
    /* Escapes the webmail iframe, as the contacts notice does. */
    link.setAttribute('target', '_top');
    link.setAttribute('href', url);
    /* Cypht intercepts internal links to route them; this one is not ours. */
    link.setAttribute('data-external', 'true');
    return link;
};

var dolibarr_context_badge = function(text, cls) {
    var badge = document.createElement('span');
    badge.className = 'badge fw-normal ' + (cls || 'bg-body-secondary text-secondary');
    badge.textContent = text;
    return badge;
};

/* The rows behind one chip: reference, date, amount, each linking to its card. */
var dolibarr_context_rows = function(block, strings) {
    var wrap = document.createElement('div');
    wrap.className = 'dolibarr_context_rows ms-1 mt-1 border-start ps-2';
    wrap.setAttribute('data-rows-for', block.key);
    wrap.hidden = true;

    (block.rows || []).forEach(function(row) {
        var line = document.createElement('div');
        line.className = 'd-flex flex-wrap align-items-baseline gap-2 py-1';

        line.appendChild(dolibarr_context_link(row.ref, row.url, 'text-decoration-none fw-medium'));

        if (row.date) {
            var date = document.createElement('span');
            date.className = 'text-muted';
            date.textContent = row.date;
            line.appendChild(date);
        }

        if (row.amount) {
            var amount = document.createElement('span');
            amount.className = 'ms-auto';
            amount.textContent = row.amount;
            line.appendChild(amount);
        }

        wrap.appendChild(line);
    });

    /* Only when there are more than we were given, so the link is honest. */
    if (block.count > (block.rows || []).length && block.url) {
        var more = document.createElement('div');
        more.className = 'py-1';
        more.appendChild(dolibarr_context_link(strings.more, block.url, 'text-decoration-none small'));
        wrap.appendChild(more);
    }

    return wrap;
};

/* One chip: a count, a label, and a total when the records carry money. */
var dolibarr_context_chip = function(block) {
    var chip = document.createElement('button');
    chip.type = 'button';
    chip.className = 'btn btn-sm btn-outline-secondary d-inline-flex align-items-center gap-1 py-0 px-2';
    chip.setAttribute('data-block', block.key);
    chip.setAttribute('aria-expanded', 'false');

    if (block.icon) {
        var icon = document.createElement('i');
        icon.className = 'bi ' + block.icon;
        chip.appendChild(icon);
    }

    var count = document.createElement('span');
    count.className = 'fw-medium';
    count.textContent = String(block.count);
    chip.appendChild(count);

    var label = document.createElement('span');
    label.textContent = block.label;
    chip.appendChild(label);

    if (block.total) {
        var total = document.createElement('span');
        total.className = 'text-muted';
        total.textContent = block.total;
        chip.appendChild(total);
    }

    return chip;
};

/* The identity link: the record's own name, carrying a mark that says it opens something. */
var dolibarr_context_record_link = function(text, url, strings) {
    var link = dolibarr_context_link(text, url, 'fw-semibold');
    if (strings.open) {
        link.setAttribute('title', strings.open);
    }

    var mark = document.createElement('i');
    mark.className = 'bi bi-box-arrow-up-right ms-1 small';
    mark.setAttribute('aria-hidden', 'true');
    link.appendChild(mark);

    return link;
};

/* Normalised for comparison only. */
var dolibarr_context_same_name = function(a, b) {
    var norm = function(v) {
        return (v || '').toLowerCase().replace(/\s+/g, ' ').trim();
    };
    return norm(a) !== '' && norm(a) === norm(b);
};

/* What to call the record, given that the From line above it already names the sender. */
var dolibarr_context_label = function(data, strings) {
    var match = data.match;
    var party = data.thirdparty;
    var sender = dolibarr_context_sender_name();

    if (party && party.name && !dolibarr_context_same_name(party.name, sender)) {
        return { text: party.name, url: party.url, showBadges: true };
    }

    if (party) {
        /* The company is the sender. */
        if (party.is_customer) {
            return { text: strings.customer, url: party.url, showBadges: false };
        }
        if (party.is_supplier) {
            return { text: strings.supplier, url: party.url, showBadges: false };
        }
        return { text: party.name, url: party.url, showBadges: false };
    }

    /* A colleague or member: no company, so the record type is all that is new. */
    return {
        text: (match.type_label || match.name),
        url: match.url,
        showBadges: false
    };
};

/* The offer, for an address that matched nothing: one quiet button. */
var dolibarr_context_offer_add = function(root, strings) {
    var body = dolibarr_context_body(root);
    if (!body) {
        return;
    }
    body.innerHTML = '';

    var btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'btn btn-sm btn-outline-secondary d-inline-flex align-items-center gap-1 py-0 px-2';
    btn.id = 'dolibarr_context_add';
    /* Plain title attribute: this control is rebuilt per message, and an
     * uninitialised Bootstrap tooltip would show nothing. */
    if (strings.addWhy) {
        btn.setAttribute('title', strings.addWhy);
    }

    var icon = document.createElement('i');
    icon.className = 'bi bi-person-plus';
    btn.appendChild(icon);

    var label = document.createElement('span');
    label.textContent = strings.add;
    btn.appendChild(label);

    btn.addEventListener('click', function() {
        dolibarr_context_open_add(root, strings);
    });

    body.appendChild(btn);
};

/* The create dialog. */
var dolibarr_context_open_add = function(root, strings) {
    var email = root.getAttribute('data-email');
    var suggested = dolibarr_context_sender_name() || email.split('@')[0];

    var modal = new Hm_Modal({
        title: strings.addTitle,
        modalId: 'dolibarr_context_add_modal'
    });

    /* Static shell; values from the message are written in below. */
    modal.setContent(
        '<div class="mb-2">' +
        '<label class="form-label small fw-medium" for="dolibarr_context_add_name"></label>' +
        '<input type="text" class="form-control form-control-sm" id="dolibarr_context_add_name" autocomplete="off">' +
        '</div>' +
        '<div class="small text-muted" id="dolibarr_context_add_email"></div>' +
        '<div class="small text-muted mt-2" id="dolibarr_context_add_hint"></div>' +
        '<div class="small text-danger mt-2" id="dolibarr_context_add_error" hidden></div>'
    );

    /* The extra class is how the click handler finds this button again. */
    modal.addFooterBtn(strings.addSave, 'btn-primary dolibarr_context_create_btn', function() {
        var field = document.getElementById('dolibarr_context_add_name');
        dolibarr_context_do_create(root, strings, modal, field ? field.value : '');
    });

    modal.open();

    var lbl = document.querySelector('label[for="dolibarr_context_add_name"]');
    if (lbl) {
        lbl.textContent = strings.addName;
    }
    var field = document.getElementById('dolibarr_context_add_name');
    if (field) {
        field.value = suggested;
        field.focus();
        field.select();
    }
    var mail = document.getElementById('dolibarr_context_add_email');
    if (mail) {
        mail.textContent = email;
    }
    var hint = document.getElementById('dolibarr_context_add_hint');
    if (hint) {
        hint.textContent = strings.addHint;
    }
};

/* Put the dialog's create button into a working state, or take it away. */
var dolibarr_context_create_btn_state = function(modal, working, strings) {
    var btn = document.querySelector('.dolibarr_context_create_btn');

    if (!working) {
        modal.customButtons = [];
        modal.recreateButtons();
        return;
    }

    if (!btn) {
        return;
    }

    /* Disabled first: the endpoint is idempotent, but two requests are not. */
    btn.disabled = true;
    btn.textContent = '';

    var spin = document.createElement('span');
    spin.className = 'spinner-border spinner-border-sm me-1';
    spin.setAttribute('role', 'status');
    spin.setAttribute('aria-hidden', 'true');
    btn.appendChild(spin);

    var label = document.createElement('span');
    label.textContent = strings.addWorking;
    btn.appendChild(label);
};

/* What the dialog says once the record exists: that it does, and a way to go and look at it. */
var dolibarr_context_create_done = function(modal, strings, status, url, name) {
    modal.setContent(
        '<div class="d-flex align-items-start gap-2">' +
        '<i class="bi bi-check-circle-fill text-success fs-5"></i>' +
        '<div>' +
        '<div class="fw-medium" id="dolibarr_context_done_title"></div>' +
        '<div class="small text-muted" id="dolibarr_context_done_name"></div>' +
        '<div class="mt-2" id="dolibarr_context_done_link"></div>' +
        '</div></div>'
    );

    var title = document.getElementById('dolibarr_context_done_title');
    if (title) {
        title.textContent = (status === 'created') ? strings.addDone : strings.addExisting;
    }

    var who = document.getElementById('dolibarr_context_done_name');
    if (who) {
        who.textContent = name || '';
    }

    var slot = document.getElementById('dolibarr_context_done_link');
    if (slot && url) {
        slot.appendChild(dolibarr_context_record_link(strings.addOpen, url, strings));
    }

    /* Nothing left to create, so the button that offered it goes. */
    dolibarr_context_create_btn_state(modal, false, strings);
};

/* POST the create, then tell the dialog and redraw the panel behind it. */
var dolibarr_context_do_create = function(root, strings, modal, name) {
    var email = root.getAttribute('data-email');
    var err = document.getElementById('dolibarr_context_add_error');
    if (err) {
        err.hidden = true;
        err.textContent = '';
    }

    dolibarr_context_create_btn_state(modal, true, strings);
    dolibarr_context_note(root, strings.addWorking);

    var form = new FormData();
    form.append('email', email);
    form.append('name', name);

    fetch(dolibarr_context_bridge('create'), {
        method: 'POST',
        credentials: 'same-origin',
        headers: {'Accept': 'application/json'},
        body: form
    }).then(function(response) {
        return response.json().catch(function() {
            return {};
        });
    }).then(function(res) {
            /* The bridge answers with the record it made or found; the
             * dialog only needs to know which of the two happened. */
            var status = 'error';
            if (res && res.created) {
                status = 'created';
            } else if (res && res.existing) {
                status = 'existing';
            }

            if (status !== 'created' && status !== 'existing') {
                /* Kept in the dialog: the refusal belongs beside the field. */
                var message = (res && res.error) || strings.addFailed;
                var box = document.getElementById('dolibarr_context_add_error');
                if (box) {
                    box.textContent = message;
                    box.hidden = false;
                }
                var btn = document.querySelector('.dolibarr_context_create_btn');
                if (btn) {
                    btn.disabled = false;
                    btn.textContent = strings.addSave;
                }
                dolibarr_context_offer_add(root, strings);
                return;
            }

            dolibarr_context_create_done(
                modal,
                strings,
                status,
                (res && res.url) || '',
                (res && res.dolibarr_context_create_name) || name
            );

            /* The panel behind still says nobody owns this address. */
            dolibarr_context_state = { email: null, data: null, status: '', at: 0 };
            root.removeAttribute('data-dolibarr-bound');
            dolibarr_context_init();
    }).catch(function() {
        var box = document.getElementById('dolibarr_context_add_error');
        if (box) {
            box.textContent = strings.addFailed;
            box.hidden = false;
        }
        dolibarr_context_create_btn_state(modal, false, strings);
        dolibarr_context_offer_add(root, strings);
    });
};

var dolibarr_context_render = function(root, strings, data, status) {
    var body = dolibarr_context_body(root);
    if (!body) {
        return;
    }

    if (!data || !data.match) {
        /* One quiet control rather than a sentence. */
        if (data && data.can_create) {
            dolibarr_context_offer_add(root, strings);
        } else {
            root.hidden = true;
        }
        return;
    }

    body.innerHTML = '';

    /* Only when the panel could not be moved onto the From row. */
    if (!root.classList.contains('dolibarr_context_inline') && strings.caption) {
        var caption = document.createElement('div');
        caption.className = 'dolibarr_context_caption text-muted w-100';
        caption.textContent = strings.caption;
        body.appendChild(caption);
    }

    var label = dolibarr_context_label(data, strings);
    body.appendChild(dolibarr_context_record_link(label.text, label.url, strings));

    if (label.showBadges) {
        var party = data.thirdparty;
        if (party && party.is_customer) {
            body.appendChild(dolibarr_context_badge(strings.customer, 'bg-primary-subtle text-primary-emphasis'));
        }
        if (party && party.is_supplier) {
            body.appendChild(dolibarr_context_badge(strings.supplier, 'bg-info-subtle text-info-emphasis'));
        }
    }

    var blocks = data.blocks || [];
    if (!blocks.length) {
        var none = document.createElement('span');
        none.className = 'text-muted';
        none.textContent = strings.empty;
        body.appendChild(none);
    }
    if (blocks.length) {
        var chips = document.createElement('span');
        chips.className = 'd-inline-flex flex-wrap gap-2';

        /* Full width, so rows drop below instead of widening the header row. */
        var rows = document.createElement('div');
        rows.className = 'w-100 mt-1';

        blocks.forEach(function(block) {
            chips.appendChild(dolibarr_context_chip(block));
            rows.appendChild(dolibarr_context_rows(block, strings));
        });

        body.appendChild(chips);
        body.appendChild(rows);

        chips.addEventListener('click', function(ev) {
            var chip = ev.target.closest('[data-block]');
            if (!chip) {
                return;
            }
            var key = chip.getAttribute('data-block');
            var panel = rows.querySelector('[data-rows-for="' + key + '"]');
            if (!panel) {
                return;
            }
            var opening = panel.hidden;
            /* One open at a time: this sits above the message. */
            rows.querySelectorAll('[data-rows-for]').forEach(function(other) {
                other.hidden = true;
            });
            chips.querySelectorAll('[data-block]').forEach(function(other) {
                other.setAttribute('aria-expanded', 'false');
                other.classList.remove('active');
            });
            panel.hidden = !opening;
            chip.setAttribute('aria-expanded', opening ? 'true' : 'false');
            chip.classList.toggle('active', opening);
        });
    }

    if (status === 'stale') {
        var warn = document.createElement('div');
        warn.className = 'small text-warning-emphasis w-100';
        warn.textContent = strings.stale;
        body.appendChild(warn);
    }
};

/* Give up, but leave a way back. */
var dolibarr_context_recover = function(root, strings, attempt) {
    if (!document.body.contains(root)) {
        return; /* the message was closed while we were waiting */
    }

    if (attempt < 2) {
        dolibarr_context_load(root, strings, attempt + 1);
        return;
    }

    var body = dolibarr_context_body(root);
    if (!body) {
        return;
    }
    body.innerHTML = '';

    /* A control, not a message. */
    var again = document.createElement('button');
    again.type = 'button';
    again.className = 'btn btn-sm btn-outline-secondary d-inline-flex align-items-center gap-1 py-0 px-2';
    again.id = 'dolibarr_context_recheck';
    if (strings.failed) {
        again.setAttribute('title', strings.failed);
    }

    var icon = document.createElement('i');
    icon.className = 'bi bi-arrow-clockwise';
    again.appendChild(icon);

    var label = document.createElement('span');
    label.textContent = strings.retry;
    again.appendChild(label);

    again.addEventListener('click', function() {
        /* Back to attempt one, so the button gets the silent retry too. */
        dolibarr_context_load(root, strings, 1);
    });

    body.appendChild(again);
};

/* Fetch the card for this panel's address and draw it. */
var dolibarr_context_load = function(root, strings, attempt) {
    attempt = attempt || 1;
    var email = root.getAttribute('data-email');

    /* Shorter than the session cache: this absorbs the double render only. */
    if (dolibarr_context_state.email === email
            && dolibarr_context_state.data
            && (Date.now() - dolibarr_context_state.at) < 15000) {
        dolibarr_context_render(root, strings, dolibarr_context_state.data, dolibarr_context_state.status);
        return;
    }

    dolibarr_context_note(root, strings.loading);

    var settled = false;
    var timer = window.setTimeout(function() {
        if (settled) {
            return;
        }
        settled = true;
        dolibarr_context_recover(root, strings, attempt);
    }, 8000);

    /* Whichever of the two paths arrives first wins; the other becomes a no-op. */
    var claim = function() {
        if (settled) {
            return false;
        }
        settled = true;
        window.clearTimeout(timer);
        return true;
    };

    /* Same origin as Dolibarr, so the browser attaches the session cookie and
     * anything the site puts in front of it. The server never calls its own
     * URL, which is what used to break behind an auth proxy or a firewall. */
    fetch(dolibarr_context_bridge('context') + '?email=' + encodeURIComponent(email), {
        credentials: 'same-origin',
        headers: {'Accept': 'application/json'}
    }).then(function(response) {
        return response.json().then(function(body) {
            return {ok: response.ok, status: response.status, body: body};
        });
    }).then(function(res) {
        if (!claim()) {
            return;
        }

        /* The message may have been closed, or another one opened, while the request was in flight. */
        if (!document.body.contains(root)) {
            return;
        }

        if (res.status === 403) {
            /* Refused, not broken: nothing to show and nothing to retry. */
            root.hidden = true;
            return;
        }

        if (!res.ok || !res.body || typeof res.body !== 'object') {
            dolibarr_context_recover(root, strings, attempt);
            return;
        }

        dolibarr_context_state = {
            email: email,
            data: res.body,
            status: 'ok',
            at: Date.now()
        };

        /* A throw here would leave the loading note as the last thing written. */
        try {
            dolibarr_context_render(root, strings, res.body, 'ok');
        } catch (err) {
            if (window.console && console.error) {
                console.error('dolibarr_context: render failed', err);
            }
            dolibarr_context_recover(root, strings, 2);
        }
    }).catch(function() {
        if (claim()) {
            dolibarr_context_recover(root, strings, attempt);
        }
    });
};

var dolibarr_context_init = function() {
    var root = document.getElementById('dolibarr_context');
    if (!root || !root.getAttribute('data-email')) {
        return; /* not a message view, or no address to look up */
    }

    /* The response is replayed from local storage, so this runs twice. */
    if (root.getAttribute('data-dolibarr-bound') === '1') {
        return;
    }
    root.setAttribute('data-dolibarr-bound', '1');

    /* Before the fetch, so the loading note appears in its final place. */
    dolibarr_context_relocate(root);

    dolibarr_context_load(root, dolibarr_context_strings(root));
};

/* imap/site.js calls this once the message view is fully in the DOM. */
var dolibarr_context_view_finished = window.imap_message_view_finished;
window.imap_message_view_finished = function(msg_uid, detail, listParent, skip_links) {
    var result = dolibarr_context_view_finished
        ? dolibarr_context_view_finished(msg_uid, detail, listParent, skip_links)
        : undefined;

    dolibarr_context_init();

    return result;
};

$(document).ready(function() {
    dolibarr_context_init();
});
