document.addEventListener('DOMContentLoaded', () => {
  // ——— Modal de Detalles ———
  const modalDetail = document.getElementById('modal-detalles');
  const closeDetail = modalDetail.querySelector('.modal-close');

  document.querySelectorAll('.detail-btn').forEach(btn => {
    btn.addEventListener('click', () => {
      modalDetail.querySelector('#md-nombre').textContent      = btn.dataset.nombre;
      modalDetail.querySelector('#md-nombre2').textContent     = btn.dataset.nombre;
      modalDetail.querySelector('#md-fi').textContent          = btn.dataset.fi;
      modalDetail.querySelector('#md-ft').textContent          = btn.dataset.ft;
      modalDetail.querySelector('#md-lugar').textContent       = btn.dataset.lugar;
      modalDetail.querySelector('#md-encargado').textContent   = btn.dataset.encargado;
      modalDetail.querySelector('#md-descripcion').textContent = btn.dataset.descripcion;
      modalDetail.querySelector('#md-equipos').textContent     = btn.dataset.equipos;
      modalDetail.querySelector('#md-previo').textContent      = btn.dataset.previo;
      modalDetail.querySelector('#md-tipo').textContent        = btn.dataset.tipo;
      modalDetail.querySelector('#md-asist').textContent       = btn.dataset.asist;
      modalDetail.querySelector('#md-observacion').textContent = btn.dataset.observacion;
      modalDetail.querySelector('#md-final').textContent       = btn.dataset.final;
      modalDetail.style.display = 'flex';
    });
  });

  closeDetail.addEventListener('click', () => modalDetail.style.display = 'none');
  modalDetail.addEventListener('click', e => {
    if (e.target === modalDetail) modalDetail.style.display = 'none';
  });

  // ——— Modal de Edición ———
  const modalEdit = document.getElementById('modal-edit');
  const closeEdit = modalEdit.querySelector('.modal-close');

  // Función que filtra el <select> de encargado según proyectos seleccionados
  function filterEncargados(selectedProjects, selectedEnc) {
    const encSelect = document.getElementById('edit-encargado');
    let valid = false;
    Array.from(encSelect.options).forEach(opt => {
      if (!opt.value) return; // placeholder
      const projects = (opt.dataset.projects || '').split(',');
      const ok = selectedProjects.length === 0
               ? true
               : projects.some(p => selectedProjects.includes(p));
      opt.hidden = !ok;
      if (opt.value === selectedEnc && ok) {
        valid = true;
      }
    });
    // Si el encargado actual ya no es válido, lo limpiamos
    encSelect.value = valid ? selectedEnc : '';
  }

  document.querySelectorAll('.edit-btn').forEach(btn => {
    btn.addEventListener('click', () => {
      // 1) Rellenar campos básicos
      document.getElementById('edit-id').value          = btn.dataset.id;
      document.getElementById('edit-nombre').value      = btn.dataset.nombre;
      document.getElementById('edit-lugar').value       = btn.dataset.lugar;
      document.getElementById('edit-descripcion').value = btn.dataset.descripcion;
      document.getElementById('edit-observacion').value = btn.dataset.observacion;
      document.getElementById('edit-previo').value      = btn.dataset.previo;
      document.getElementById('edit-tipo').value        = btn.dataset.tipo;
      document.getElementById('edit-final').value       = btn.dataset.final;

      // 2) Referencias a inputs y mensaje de error
      const startInp = document.getElementById('edit-start');
      const endInp   = document.getElementById('edit-end');
      const errEnd   = document.getElementById('end-error');
      const encSelect= document.getElementById('edit-encargado');

      // 3) Cargar valores iniciales de fecha
      startInp.value = btn.dataset.start.replace(' ', 'T');
      endInp.value   = btn.dataset.end.replace(' ', 'T');
      endInp.min     = startInp.value;

      // 4) Validación inline de fechas
      function validateDate() {
        if (endInp.value && endInp.value < startInp.value) {
          errEnd.style.display = 'block';
        } else {
          errEnd.style.display = 'none';
        }
      }
      startInp.onchange = () => {
        endInp.min = startInp.value;
        if (endInp.value < endInp.min) endInp.value = endInp.min;
        validateDate();
      };
      endInp.oninput = validateDate;

      // 5) Marcar checkboxes de proyectos
      const eqRaw = btn.dataset.equipos || '';
      const eqArr = eqRaw ? eqRaw.split(',') : [];
      document.querySelectorAll('.checkbox-item input').forEach(chk => {
        chk.checked = eqArr.includes(chk.value);
      });

      // 6) Filtrar encargado inicialmente
      filterEncargados(eqArr, btn.dataset.encargado);
      validateDate();

      // 7) Re-filtrar encargado y quizá limpiar la selección
      document.querySelectorAll('.checkbox-item input').forEach(chk => {
        chk.onchange = () => {
          const selected = Array.from(
            document.querySelectorAll('.checkbox-item input:checked'),
            c => c.value
          );
          // pasamos la selección actual del select como referencia
          const currentEnc = encSelect.value;
          filterEncargados(selected, currentEnc);
        };
      });

      // 8) Mostrar modal
      modalEdit.style.display = 'flex';
    });
  });

  closeEdit.addEventListener('click', () => modalEdit.style.display = 'none');
  modalEdit.addEventListener('click', e => {
    if (e.target === modalEdit) modalEdit.style.display = 'none';
  });

  // ——— Guardar Cambios Edición ———
  const saveBtn = document.getElementById('btn-save-evento');
  saveBtn.addEventListener('click', async () => {
    const errEnd = document.getElementById('end-error');
    errEnd.style.display = 'none';

    if (saveBtn.disabled) return;
    saveBtn.disabled = true;
    const orig = saveBtn.textContent;
    saveBtn.textContent = 'Guardando…';

    // Validación final de fechas
    const startInp = document.getElementById('edit-start');
    const endInp   = document.getElementById('edit-end');
    if (endInp.value && endInp.value < startInp.value) {
      errEnd.style.display = 'block';
      endInp.focus();
      saveBtn.disabled = false;
      saveBtn.textContent = orig;
      return;
    }

    // Envío AJAX
    const form = document.getElementById('form-edit-evento');
    const fd   = new FormData(form);
    try {
      const res  = await fetch('actualizar_evento.php', { method: 'POST', body: fd });
      const json = await res.json();
      if (!json.mensaje) throw new Error(json.error || 'Error al guardar');
      location.reload();
    } catch (err) {
      alert('Error: ' + err.message);
      saveBtn.disabled = false;
      saveBtn.textContent = orig;
    }
  });
});
