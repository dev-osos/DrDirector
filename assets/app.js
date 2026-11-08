(() => {
  'use strict';
  const $ = (s) => document.querySelector(s);
  const chat = $('#chat'), form = $('#form'), input = $('#drug'), sendBtn = $('#send');

  const ICONS = {
    safe: '<svg viewBox="0 0 24 24" width="28" height="28" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="m5 12.5 4.5 4.5L19 7.5"/></svg>',
    unsafe: '<svg viewBox="0 0 24 24" width="28" height="28" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round"><path d="M6 6l12 12M18 6 6 18"/></svg>',
    consult: '<svg viewBox="0 0 24 24" width="28" height="28" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="M12 8v5"/><circle cx="12" cy="17" r=".6" fill="currentColor"/><path d="M10.3 3.9 2.4 17.6A2 2 0 0 0 4.1 20.6h15.8a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/></svg>'
  };
  ICONS.unknown = ICONS.consult;
  const RESULT_LABEL = { safe: 'غير متعارض', unsafe: 'متعارض', neutral: 'محايد', error: 'غير متاح' };

  /* DOM helper (textContent فقط لتجنب XSS) */
  function h(tag, props = {}, ...kids) {
    const el = document.createElement(tag);
    for (const [k, v] of Object.entries(props)) {
      if (k === 'class') el.className = v;
      else if (k === 'html') el.innerHTML = v;
      else if (k.startsWith('on')) el.addEventListener(k.slice(2), v);
      else if (v !== false && v != null) el.setAttribute(k, v);
    }
    for (const k of kids.flat()) if (k != null) el.append(k.nodeType ? k : document.createTextNode(k));
    return el;
  }
  const scrollDown = () => requestAnimationFrame(() => chat.scrollTo({ top: chat.scrollHeight, behavior: 'smooth' }));

  function userMsg(text) {
    chat.append(h('div', { class: 'msg user' }, h('div', { class: 'bubble' }, text)));
    scrollDown();
  }
  function botMsg(content) {
    const col = h('div', { class: 'col' }, content);
    const m = h('div', { class: 'msg bot' }, h('div', { class: 'avatar' }, 'DR'), col);
    chat.append(m); scrollDown();
    return m;
  }
  function typing() {
    return botMsg(h('div', { class: 'bubble' },
      h('div', { class: 'typing' }, h('i'), h('i'), h('i')),
      h('div', { class: 'status' }, 'أبحث في عدة مصادر…')));
  }

  /* ---------- API ---------- */
  async function api(drug, refresh = false) {
    let r;
    try {
      r = await fetch('api.php', {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ drug, refresh })
      });
    } catch { throw new Error('لا يوجد اتصال بالإنترنت. حاول مرة أخرى عند عودة الاتصال.'); }
    const j = await r.json().catch(() => null);
    if (!j) throw new Error('استجابة غير صالحة من الخادم.');
    if (!r.ok || !j.ok) throw new Error(j.error || 'حدث خطأ غير متوقع.');
    return j;
  }

  /* ---------- Result card ---------- */
  function resultCard(d) {
    const total = d.safe + d.unsafe;
    const meter = h('div', { class: 'meter' },
      total === 0
        ? h('span', { class: 'm-none', style: 'flex:1' })
        : [h('span', { class: 'm-safe', style: `flex:${d.safe}` }), h('span', { class: 'm-unsafe', style: `flex:${d.unsafe}` })]);

    const srcs = d.sources.map((s) => h('div', { class: 'src' },
      h('div', { class: 'src-head' },
        h('a', { href: s.url, target: '_blank', rel: 'noopener noreferrer' }, s.name),
        h('span', { class: `dot d-${s.result}` }, RESULT_LABEL[s.result] || s.result)),
      (s.evidence || []).map((e) => h('div', { class: 'ev' }, e))));

    const when = new Date(d.checked_at).toLocaleDateString('ar-EG', { year: 'numeric', month: 'long', day: 'numeric' });

    return h('div', { class: `result v-${d.verdict}` },
      h('div', { class: 'verdict' },
        h('div', { class: 'vicon', html: ICONS[d.verdict] || ICONS.consult }),
        h('div', {}, h('small', {}, d.drug), h('strong', {}, d.message))),
      h('div', { class: 'body' },
        meter,
        h('div', { class: 'counts' },
          h('span', { class: 'pill p-safe' }, `غير متعارض مع الحمل: ${d.safe}`),
          h('span', { class: 'pill p-unsafe' }, `متعارض مع الحمل: ${d.unsafe}`),
          d.neutral ? h('span', { class: 'pill p-neutral' }, `محايد: ${d.neutral}`) : null),
        d.strict_override
          ? h('p', { class: 'note' }, 'تنبيه: أغلب المصادر لم تُظهر تعارضاً، لكن مصدراً واحداً على الأقل يذكر أن الدواء ممنوع أو عالي الخطورة في الحمل، لذلك الأسلم استشارة الطبيب.')
          : null,
        srcs.length
          ? h('details', { class: 'srcs' }, h('summary', {}, `المصادر (${srcs.length})`), srcs)
          : null,
        h('div', { class: 'meta' },
          d.cached ? h('span', { class: 'tag cached' }, `من السجل · سُئل ${d.asked_count} مرات`) : h('span', { class: 'tag' }, 'نتيجة جديدة'),
          h('span', {}, when),
          h('button', { class: 'refresh', type: 'button', onclick: () => ask(d.drug, true) }, 'تحديث البيانات')),
        h('p', { class: 'disc' }, 'النتيجة مبنية على عدّ ما ورد في المصادر المتاحة، وهي للاسترشاد فقط. القرار النهائي دائماً مع الطبيب.')));
  }

  /* ---------- Ask ---------- */
  let busy = false;
  async function ask(drug, refresh = false) {
    drug = drug.trim();
    if (!drug || busy) return;
    busy = true; sendBtn.disabled = true;
    userMsg(refresh ? `تحديث: ${drug}` : drug);
    const t = typing();
    try {
      const d = await api(drug, refresh);
      t.remove();
      botMsg(resultCard(d));
    } catch (e) {
      t.remove();
      botMsg(h('div', { class: 'bubble' }, e.message));
    } finally {
      busy = false; sendBtn.disabled = false; input.focus();
    }
  }

  form.addEventListener('submit', (e) => {
    e.preventDefault();
    const v = input.value; input.value = '';
    ask(v);
  });

  /* ---------- Chips ---------- */
  const chips = $('#chips');
  ['Paracetamol', 'Ibuprofen', 'Amoxicillin', 'Metformin', 'Isotretinoin'].forEach((n) =>
    chips.append(h('button', { class: 'chip', type: 'button', onclick: () => ask(n) }, n)));

  /* ---------- History drawer ---------- */
  const drawer = $('#drawer'), scrim = $('#scrim'), list = $('#historyList');
  const VCOL = { safe: 'var(--safe)', unsafe: 'var(--unsafe)', consult: 'var(--warn)', unknown: 'var(--grey)' };
  async function openHistory() {
    drawer.hidden = scrim.hidden = false;
    list.replaceChildren(h('li', { class: 'empty' }, 'جارٍ التحميل…'));
    try {
      const r = await fetch('api.php?action=history');
      const j = await r.json();
      list.replaceChildren();
      if (!j.items?.length) { list.append(h('li', { class: 'empty' }, 'لا يوجد سجل بعد.')); return; }
      j.items.forEach((it) => list.append(h('li', {},
        h('button', { type: 'button', onclick: () => { closeHistory(); ask(it.drug_name); } },
          h('span', { style: `width:10px;height:10px;border-radius:50%;background:${VCOL[it.verdict]}` }),
          h('span', { class: 'n' }, it.drug_name),
          h('span', { class: 'c' }, `×${it.asked_count}`)))));
    } catch { list.replaceChildren(h('li', { class: 'empty' }, 'تعذر تحميل السجل.')); }
  }
  function closeHistory() { drawer.hidden = scrim.hidden = true; }
  $('#historyBtn').addEventListener('click', openHistory);
  $('#closeDrawer').addEventListener('click', closeHistory);
  scrim.addEventListener('click', closeHistory);

  /* ---------- Welcome ---------- */
  botMsg(h('div', { class: 'bubble' },
    'أهلاً! أنا DR - Director 👋 اكتب اسم الدواء (العلمي أو التجاري) وسأبحث في عدة مصادر لأعرف إن كان آمناً أثناء الحمل أم لا.'));

  /* ---------- PWA ---------- */
  let deferred;
  const installBtn = $('#installBtn');
  window.addEventListener('beforeinstallprompt', (e) => { e.preventDefault(); deferred = e; installBtn.hidden = false; });
  installBtn.addEventListener('click', async () => {
    if (!deferred) return;
    deferred.prompt(); await deferred.userChoice; deferred = null; installBtn.hidden = true;
  });
  window.addEventListener('appinstalled', () => (installBtn.hidden = true));
  if ('serviceWorker' in navigator) window.addEventListener('load', () => navigator.serviceWorker.register('sw.js').catch(() => {}));
})();
