/* Catalog editor — Gordey_ap01. No writes happen before the server validates a form. */
(() => {
  const root = document.querySelector('.s101-admin');
  if (!root) return;
  if (root.querySelector('[data-catalog-applied]') && window.parent !== window) window.parent.postMessage({ type: 's101-catalog-applied' }, location.origin);
  let dirty = false;
  window.S101HasUnsavedChanges = () => dirty;
  const markDirty = () => { dirty = true; };
  function trackChange(event) {
    if (!event.target.matches('#s101-search,[data-service-select],[data-select-all]')) markDirty();
  }
  root.addEventListener('input', trackChange);
  root.addEventListener('change', trackChange);
  window.addEventListener('beforeunload', event => {
    if (dirty) { event.preventDefault(); event.returnValue = ''; }
  });

  function warning(messages, target) {
    let dialog = document.getElementById('s101-warning');
    if (!dialog) {
      dialog = document.createElement('dialog');
      dialog.id = 's101-warning';
      dialog.className = 's101-warning';
      dialog.setAttribute('role', 'alertdialog');
      dialog.setAttribute('aria-labelledby', 's101-warning-title');
      dialog.innerHTML = '<h2 id="s101-warning-title">Проверьте заполнение</h2><p>Ваши данные остались в форме.</p><ul></ul><p class="description">Исправьте указанные поля и повторите. Изменения ещё не применены.</p><button class="button button-primary" type="button">Вернуться к форме</button>';
      document.body.append(dialog);
      dialog.querySelector('button').addEventListener('click', () => dialog.close());
    }
    dialog.querySelector('ul').replaceChildren(...messages.map(message => {
      const li = document.createElement('li'); li.textContent = message; return li;
    }));
    dialog.addEventListener('close', () => {
      if (!target) return;
      for (let parent = target.parentElement; parent; parent = parent.parentElement) {
        if (parent.tagName === 'DETAILS') parent.open = true;
        if (parent.hidden) parent.hidden = false;
      }
      target.focus(); target.scrollIntoView({ block: 'center', behavior: 'instant' });
    }, { once: true });
    dialog.showModal();
  }

  function validate(form) {
    form.querySelectorAll('.s101-field-error').forEach(node => node.remove());
    form.querySelectorAll('[aria-invalid]').forEach(node => {
      node.removeAttribute('aria-invalid');
      if (node.getAttribute('aria-describedby')?.startsWith('s101-error-')) node.removeAttribute('aria-describedby');
    });
    const errors = [];
    function error(control, message) {
      if (!control) return;
      control.setAttribute('aria-invalid', 'true');
      const note = document.createElement('span'); note.className = 's101-field-error';
      note.id = `s101-error-${errors.length}`; note.textContent = message;
      control.setAttribute('aria-describedby', note.id); control.after(note);
      errors.push({ control, message });
    }
    form.querySelectorAll('input[required],select[required],textarea[required]').forEach(control => {
      if (!control.value.trim()) error(control, `Заполните поле «${control.labels?.[0]?.textContent.trim() || control.getAttribute('aria-label') || 'Название'}».`);
    });
    if (form.dataset.operation === 'save_brand' && !form.querySelector('[name="term[category_slugs][]"]:checked')) {
      error(form.querySelector('[name="term[category_slugs][]"]'), 'Выберите хотя бы одну категорию бренда — например, «Телефоны» или «Ноутбуки». Можно выбрать несколько.');
    }
    form.querySelectorAll('#s101-price-rows tr').forEach((row, index) => {
      if (row.querySelector('[data-delete-flag]')?.value === '1') return;
      const name = row.querySelector('[data-service-name]');
      const code = row.querySelector('[name$="[service_code]"]');
      const entered = Array.from(row.querySelectorAll('textarea,[data-price-amount]')).some(input => input.value.trim());
      if (!code?.value && !entered) return;
      if (!name.value.trim()) error(name, `Строка ${index + 1}: введите название услуги или отметьте строку для удаления.`);
      for (const part of ['work', 'total']) {
        const type = row.querySelector(`[data-price-type="${part}"]`);
        const amount = row.querySelector(`[data-price-amount="${part}"]`);
        if (['Фиксированная', 'Ориентир', 'От'].includes(type.value) && !amount.value.trim()) error(amount, `Строка ${index + 1}: укажите сумму, включая 0, или выберите тип без суммы.`);
      }
    });
    form.querySelectorAll('input[type="number"],input[maxlength],textarea[maxlength]').forEach(control => {
      if (!control.validity.valid && !control.hasAttribute('aria-invalid')) error(control, `${control.getAttribute('aria-label') || control.labels?.[0]?.textContent || 'Поле'}: ${control.validationMessage}`);
    });
    return errors;
  }

  root.querySelectorAll('form[data-s101-form]').forEach(form => {
    // JS handles export downloads through the browser's ordinary form submission.
    if (form.dataset.operation === 'export') return;
    form.addEventListener('submit', async event => {
      event.preventDefault();
      if (form.dataset.busy) return;
      const errors = validate(form);
      if (errors.length) { warning(errors.map(error => error.message), errors[0].control); return; }
      const confirmation = event.submitter?.dataset.confirm;
      if (confirmation && !window.confirm(confirmation)) return;
      const data = new FormData(form); data.set('_s101_async', '1');
      const button = event.submitter;
      const label = button?.textContent || button?.value;
      form.dataset.busy = '1'; form.setAttribute('aria-busy', 'true');
      if (button) { button.disabled = true; if (button.tagName === 'INPUT') button.value = 'Проверяем…'; else button.textContent = 'Проверяем…'; }
      try {
        // WordPress's hidden input named "action" shadows the form.action property.
        const response = await fetch(form.getAttribute('action'), { method: 'POST', body: data, credentials: 'same-origin', headers: { Accept: 'application/json' } });
        const result = await response.json();
        if (!result.success) {
          let message = result.data?.message || 'Не удалось сохранить изменения. Повторите попытку.';
          if (message.includes('адрес уже занят')) message += ' Укажите другой код адреса или откройте существующий бренд для редактирования.';
          throw new Error(message);
        }
        dirty = false;
        window.location.assign(result.data.url);
      } catch (error) {
        const message = error instanceof SyntaxError ? 'Сервер не подтвердил сохранение. Данные остались в форме. Обновите вход в WordPress в другой вкладке и повторите.' : error.message;
        warning([message === 'Failed to fetch' ? 'Нет связи с сервером. Данные сохранены в открытой форме. Проверьте соединение и повторите.' : message]);
      } finally {
        delete form.dataset.busy; form.removeAttribute('aria-busy');
        if (button) { button.disabled = false; if (button.tagName === 'INPUT') button.value = label; else button.textContent = label; }
      }
    });
  });

  root.addEventListener('click', event => {
    const row = event.target.closest('tr[data-href]');
    if (row && !event.target.closest('a,button,input,select,textarea,label,form') && !window.getSelection().toString()) window.location.assign(row.dataset.href);
  });
  const search = document.getElementById('s101-search');
  search?.addEventListener('input', () => {
    const query = search.value.trim().toLocaleLowerCase('ru'); let found = 0;
    root.querySelectorAll('.s101-directory tbody tr').forEach(row => { row.hidden = !row.textContent.toLocaleLowerCase('ru').includes(query); if (!row.hidden) found++; });
    document.getElementById('s101-no-results').hidden = found > 0;
  });

  const body = document.getElementById('s101-price-rows');
  if (body) {
    let nextIndex = body.rows.length;
    const refreshSelection = () => {
      const boxes = Array.from(body.querySelectorAll('[data-service-select]'));
      const count = boxes.filter(box => box.checked).length;
      root.querySelector('[data-selected-count]').textContent = `Выбрано: ${count}`;
      root.querySelector('[data-delete-selected]').disabled = !count;
      root.querySelector('[data-undo-delete]').hidden = !body.querySelector('.s101-row-deleted');
      const all = root.querySelector('[data-select-all]'); all.checked = count > 0 && count === boxes.length; all.indeterminate = count > 0 && count < boxes.length;
    };
    document.getElementById('s101-add-price').addEventListener('click', () => {
      if (body.rows.length >= 100) { warning(['В одной карточке можно сохранить до 100 услуг.']); return; }
      body.insertAdjacentHTML('beforeend', document.getElementById('s101-price-template').innerHTML.replaceAll('__ROW__', String(nextIndex++)));
      body.lastElementChild.querySelector('[data-service-name]').focus(); markDirty();
    });
    root.querySelector('[data-select-all]').addEventListener('change', event => { body.querySelectorAll('[data-service-select]').forEach(box => { box.checked = event.target.checked; }); refreshSelection(); });
    body.addEventListener('change', refreshSelection);
    root.querySelector('[data-delete-selected]').addEventListener('click', () => {
      body.querySelectorAll('[data-service-select]:checked').forEach(box => {
        const row = box.closest('tr'); row.querySelector('[data-delete-flag]').value = '1'; row.classList.add('s101-row-deleted'); row.querySelector('[data-delete-message]').hidden = false; box.checked = false;
      });
      markDirty(); refreshSelection();
      root.querySelector('.s101-live').textContent = 'Строки помечены для удаления. Проверьте и примените изменения, чтобы удалить их с сайта.';
    });
    root.querySelector('[data-undo-delete]').addEventListener('click', () => {
      body.querySelectorAll('.s101-row-deleted').forEach(row => { row.querySelector('[data-delete-flag]').value = '0'; row.querySelector('[data-delete-message]').hidden = true; row.classList.remove('s101-row-deleted'); }); markDirty(); refreshSelection();
    });
    body.addEventListener('input', event => {
      const amount = event.target.closest('[data-price-amount]');
      if (!amount || amount.value.trim() === '') return;
      const type = amount.closest('tr').querySelector(`[data-price-type="${amount.dataset.priceAmount}"]`);
      if (['Бесплатно', 'По запросу', 'После диагностики'].includes(type.value)) type.value = 'Фиксированная';
    });
    body.addEventListener('change', event => {
      const type = event.target.closest('[data-price-type]'); if (!type) return;
      const amount = type.closest('tr').querySelector(`[data-price-amount="${type.dataset.priceType}"]`);
      if (type.value === 'Бесплатно') amount.value = '0';
      else if (['По запросу', 'После диагностики'].includes(type.value)) amount.value = '';
    });
    refreshSelection();
  }

  const editor = root.querySelector('.s101-editor-dialog');
  if (editor) {
    const frame = editor.querySelector('iframe'); let opener; let applied = false;
    window.addEventListener('message', event => {
      if (event.origin === location.origin && event.source === frame.contentWindow && event.data?.type === 's101-catalog-applied') applied = true;
    });
    root.querySelectorAll('[data-services-popup]').forEach(link => link.addEventListener('click', event => {
      if (event.ctrlKey || event.metaKey || event.shiftKey) return;
      event.preventDefault(); opener = link; frame.src = link.href; editor.showModal();
    }));
    function closeEditor(event) {
      if (frame.contentWindow?.S101HasUnsavedChanges?.() && !window.confirm('Есть несохранённые изменения. Закрыть редактор?')) { event?.preventDefault(); return; }
      editor.close(); opener?.focus();
      if (applied) window.location.reload();
    }
    editor.querySelector('[data-close-editor]').addEventListener('click', closeEditor);
    editor.addEventListener('cancel', closeEditor);
  }
})();
