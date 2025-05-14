/* Sólo gestiona el pop-up y el logout; los datos ya vienen por PHP */
document.addEventListener('DOMContentLoaded', () => {
  const fotoDom = document.getElementById('foto_perfil');
  const overlay = document.getElementById('overlay');
  const bigImg  = document.getElementById('big-img');
  const logout  = document.getElementById('logout');

  // al hacer clic en la foto → ampliar
  fotoDom.addEventListener('click', () => {
    bigImg.src = fotoDom.src;
    overlay.style.display = 'flex';
  });

  // cerrar overlay al hacer clic fuera de la imagen
  overlay.addEventListener('click', e => {
    if (e.target === overlay) overlay.style.display = 'none';
  });

  // logout
  logout.addEventListener('click', e => {
    e.preventDefault();
    const token = localStorage.getItem('token');
    fetch('cerrar_sesion.php', {
      headers: {'Authorization':'Bearer '+token}
    }).finally(() => {
      localStorage.clear();
      location.replace('login.html');
    });
  });

    // ——— Verificar correo si no está validado ———
  const verifyBtn = document.getElementById('btn-verificar');
  if (verifyBtn) {
    verifyBtn.addEventListener('click', async () => {
      verifyBtn.disabled    = true;
      verifyBtn.textContent = 'Enviando…';
      try {
        const token = localStorage.getItem('token');
        const res = await fetch('enviar_verify_token.php', {
          method: 'POST',
          headers: {
            'Authorization': 'Bearer ' + token
          }
        });
        const j = await res.json();
        if (!res.ok) throw new Error(j.error || 'Error');
        alert(j.mensaje);
      } catch (err) {
        alert(err.message);
      } finally {
        verifyBtn.disabled    = false;
        verifyBtn.textContent = 'Verificar';
      }
    });
  }
});
